<?php
declare(strict_types=1);
/**
 * Tri Eventportal – Newsletter-Versandskript (IONOS SMTP)
 *
 * Holt Mails aus der Warteschlange der Website (POST /api/public/newsletter/claim),
 * versendet sie per SMTP über dein IONOS-Postfach und meldet das Ergebnis zurück
 * (POST /api/public/newsletter/ack). Läuft per Cronjob, keine Abhängigkeiten.
 *
 * Aufruf:  php newsletter-sender.php [pfad/zur/config.php]
 */

// Nur per Cronjob/CLI, nie über den Browser.
if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit('Forbidden');
}

$configPath = $argv[1] ?? __DIR__ . '/newsletter-sender.config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Konfiguration nicht gefunden: $configPath\n");
    exit(2);
}
$cfg = require $configPath;
$cfg += [
    'api_base'    => 'https://tri-eventportal.com',
    'token'       => '',
    'smtp_host'   => 'smtp.ionos.de',
    'smtp_port'   => 587,
    'smtp_secure' => 'tls',   // 'tls' = STARTTLS (587), 'ssl' = implizit (465), 'none' nur für lokale Tests
    'smtp_user'   => '',
    'smtp_pass'   => '',
    'envelope_from' => '',    // leer = smtp_user
    'batch'       => 20,      // Mails pro Abholung (max. 50)
    'max_per_run' => 100,     // Mails pro Cronlauf
    'max_runtime' => 50,      // Sekunden pro Cronlauf
    'pause_ms'    => 300,     // Pause zwischen zwei Mails
    'state_dir'   => __DIR__ . '/state',
    'log_max_kb'  => 512,
];
if ($cfg['token'] === '' || $cfg['smtp_user'] === '' || $cfg['smtp_pass'] === '') {
    fwrite(STDERR, "Konfiguration unvollständig (token, smtp_user, smtp_pass).\n");
    exit(2);
}
@mkdir($cfg['state_dir'], 0700, true);

// ---------- Hilfsfunktionen ----------
function logline(array $cfg, string $msg): void {
    $file = $cfg['state_dir'] . '/newsletter-sender.log';
    if (is_file($file) && filesize($file) > $cfg['log_max_kb'] * 1024) {
        @rename($file, $file . '.1');
    }
    @file_put_contents($file, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

/** POST JSON an die Website. Gibt [httpStatus, array|null] zurück. */
function api(array $cfg, string $path, array $payload): array {
    $ch = curl_init(rtrim($cfg['api_base'], '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['token']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, is_string($body) ? json_decode($body, true) : null];
}

function ack(array $cfg, string $id, string $status, string $error = ''): bool {
    $payload = ['id' => $id, 'status' => $status];
    if ($status === 'failed') {
        $payload['error'] = mb_substr($error !== '' ? $error : 'unbekannter Fehler', 0, 1000);
    }
    for ($try = 1; $try <= 3; $try++) {
        [$code] = api($cfg, '/api/public/newsletter/ack', $payload);
        if ($code === 200) return true;
        if ($code === 404) return true;            // Mail gibt es nicht mehr
        sleep($try * 2);
    }
    return false;
}

/** Kleiner SMTP-Client (AUTH LOGIN, STARTTLS oder implizites TLS). */
class Smtp {
    /** @var resource|null */
    private $fp = null;
    public function __construct(private array $cfg) {}

    public function connect(): void {
        $secure = $this->cfg['smtp_secure'];
        $host = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $this->cfg['smtp_host'] . ':' . $this->cfg['smtp_port'];
        $fp = @stream_socket_client($host, $errno, $errstr, 20);
        if (!$fp) throw new RuntimeException("SMTP-Verbindung fehlgeschlagen: $errstr ($errno)");
        stream_set_timeout($fp, 30);
        $this->fp = $fp;
        $this->expect(220);
        $this->cmd('EHLO ' . $this->ehloName(), 250);
        if ($secure === 'tls') {
            $this->cmd('STARTTLS', 220);
            if (!stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('STARTTLS fehlgeschlagen');
            }
            $this->cmd('EHLO ' . $this->ehloName(), 250);
        }
        if ($secure !== 'none' || $this->cfg['smtp_user'] !== '') {
            $this->cmd('AUTH LOGIN', 334);
            $this->cmd(base64_encode($this->cfg['smtp_user']), 334);
            $this->cmd(base64_encode($this->cfg['smtp_pass']), 235);
        }
    }

    private function ehloName(): string {
        $h = gethostname() ?: 'localhost';
        return preg_match('/^[A-Za-z0-9.-]+$/', $h) ? $h : 'localhost';
    }

    /** Liest eine (ggf. mehrzeilige) Antwort, prüft den Code. */
    private function expect(int $code): string {
        $all = '';
        while (($line = fgets($this->fp, 1024)) !== false) {
            $all .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        if ($all === '') throw new RuntimeException('SMTP: keine Antwort (Zeitüberschreitung)');
        if ((int) substr($all, 0, 3) !== $code) throw new SmtpReject(trim($all));
        return $all;
    }

    private function cmd(string $line, int $code): string {
        fwrite($this->fp, $line . "\r\n");
        return $this->expect($code);
    }

    public function send(string $envelopeFrom, string $to, string $message): void {
        $this->cmd('MAIL FROM:<' . $envelopeFrom . '>', 250);
        try {
            $this->cmd('RCPT TO:<' . $to . '>', 250);
            $this->cmd('DATA', 354);
            // Punkt-Stuffing und CRLF-Normalisierung
            $message = preg_replace("/\r\n|\r|\n/", "\r\n", $message);
            $message = preg_replace('/^\./m', '..', $message);
            fwrite($this->fp, $message . "\r\n.\r\n");
            $this->expect(250);
        } catch (SmtpReject $e) {
            @fwrite($this->fp, "RSET\r\n");
            @fgets($this->fp, 1024);
            throw $e;
        }
    }

    public function close(): void {
        if ($this->fp) {
            @fwrite($this->fp, "QUIT\r\n");
            @fclose($this->fp);
            $this->fp = null;
        }
    }
}
class SmtpReject extends RuntimeException {}

function encodeHeader(string $s): string {
    return preg_match('/^[\x20-\x7e]*$/', $s) ? $s : '=?UTF-8?B?' . base64_encode($s) . '?=';
}
function cleanEmail(string $e): string {
    return preg_replace('/[\r\n<>\s,;"]/', '', $e);
}

function buildMessage(array $mail): string {
    $from = cleanEmail($mail['from_email']);
    $to = cleanEmail($mail['to_email']);
    $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
    $name = trim(str_replace(["\r", "\n", '"'], '', (string) $mail['from_name']));
    $boundary = '=_tri_' . bin2hex(random_bytes(12));
    $blocked = ['from', 'to', 'cc', 'bcc', 'subject', 'date', 'message-id', 'mime-version', 'content-type', 'content-transfer-encoding', 'return-path'];

    $h = [];
    $h[] = 'Date: ' . date('r');
    $h[] = 'From: ' . ($name !== '' ? encodeHeader($name) . ' ' : '') . '<' . $from . '>';
    $h[] = 'To: <' . $to . '>';
    $h[] = 'Subject: ' . encodeHeader(str_replace(["\r", "\n"], ' ', (string) $mail['subject']));
    $h[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
    $h[] = 'MIME-Version: 1.0';
    foreach (($mail['headers'] ?? []) as $k => $v) {
        if (!is_string($k) || !is_string($v) || !preg_match('/^[A-Za-z0-9-]+$/', $k) || in_array(strtolower($k), $blocked, true)) continue;
        $h[] = $k . ': ' . str_replace(["\r", "\n"], ' ', $v);
    }
    $h[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    $crlf = fn(string $s): string => preg_replace("/\r\n|\r|\n/", "\r\n", $s);
    $text = quoted_printable_encode($crlf((string) $mail['body_text']));
    $html = quoted_printable_encode($crlf((string) $mail['body_html']));
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n$text\r\n"
          . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n$html\r\n"
          . "--$boundary--";
    return implode("\r\n", $h) . "\r\n\r\n" . $body;
}

// ---------- Ablauf ----------
$lock = fopen($cfg['state_dir'] . '/newsletter-sender.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // der vorige Lauf arbeitet noch
}

// Bereits gesendete IDs (Schutz vor Doppelversand, falls ein ack einmal scheitert)
$sentFile = $cfg['state_dir'] . '/sent-ids.json';
$sentIds = is_file($sentFile) ? (json_decode((string) file_get_contents($sentFile), true) ?: []) : [];
$saveSent = function () use (&$sentIds, $sentFile): void {
    $sentIds = array_slice(array_values(array_unique($sentIds)), -500);
    @file_put_contents($sentFile, json_encode($sentIds), LOCK_EX);
};

$start = time();
$sentCount = 0;
$failedCount = 0;
$smtp = null;
$stop = false;
$envFrom = cleanEmail($cfg['envelope_from'] !== '' ? $cfg['envelope_from'] : $cfg['smtp_user']);
$batch = max(1, min(50, (int) $cfg['batch']));

try {
    while (!$stop && $sentCount + $failedCount < $cfg['max_per_run'] && time() - $start < $cfg['max_runtime']) {
        [$code, $res] = api($cfg, '/api/public/newsletter/claim', ['limit' => $batch]);
        if ($code !== 200 || !is_array($res)) {
            logline($cfg, "claim fehlgeschlagen (HTTP $code)");
            break;
        }
        $mails = $res['mails'] ?? [];
        if (!$mails) break;

        foreach ($mails as $mail) {
            if ($sentCount + $failedCount >= $cfg['max_per_run'] || time() - $start >= $cfg['max_runtime']) {
                // nicht verarbeitete Mails bleiben 'sending' und werden nach 10 Minuten wieder freigegeben
                $stop = true;
                break;
            }
            $id = (string) $mail['id'];
            if (in_array($id, $sentIds, true)) {   // wurde schon versendet, ack hatte nur nicht geklappt
                ack($cfg, $id, 'sent');
                continue;
            }
            try {
                if ($smtp === null) {
                    $smtp = new Smtp($cfg);
                    $smtp->connect();
                }
                $smtp->send($envFrom, cleanEmail($mail['to_email']), buildMessage($mail));
                $sentIds[] = $id;
                $saveSent();
                ack($cfg, $id, 'sent');
                $sentCount++;
                logline($cfg, "gesendet id=$id kind=" . $mail['kind']);
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                $failedCount++;
                ack($cfg, $id, 'failed', $msg);
                logline($cfg, "Fehler id=$id: " . mb_substr($msg, 0, 200));
                if ($smtp) { $smtp->close(); $smtp = null; }
                // Bei Sendelimit nicht weiter versuchen, die Website plant die Mail für später neu ein.
                if (preg_match('/limit|quota|too many/i', $msg)) { $stop = true; break; }
                // Bei Verbindungs-/Login-Problemen den Lauf beenden.
                if (!($e instanceof SmtpReject)) { $stop = true; break; }
            }
            usleep((int) $cfg['pause_ms'] * 1000);
        }
    }
} finally {
    if ($smtp) $smtp->close();
}

if ($sentCount + $failedCount > 0) {
    logline($cfg, "Lauf beendet: $sentCount gesendet, $failedCount fehlgeschlagen");
}
exit(0);
