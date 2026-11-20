<?php
// Konfiguration fuer den Lauf in GitHub Actions.
// Diese Datei enthaelt KEINE Geheimnisse: Token, Postfach und Passwort kommen
// aus den GitHub-Secrets (Umgebungsvariablen), siehe .github/workflows/newsletter-sender.yml
return [
    'api_base'      => getenv('API_BASE') ?: 'https://tri-eventportal.com',
    'token'         => getenv('NEWSLETTER_SENDER_TOKEN') ?: '',

    'smtp_host'     => getenv('SMTP_HOST') ?: 'smtp.ionos.de',
    'smtp_port'     => (int) (getenv('SMTP_PORT') ?: 587),   // 587 mit 'tls' oder 465 mit 'ssl'
    'smtp_secure'   => getenv('SMTP_SECURE') ?: 'tls',
    'smtp_user'     => getenv('SMTP_USER') ?: '',
    'smtp_pass'     => getenv('SMTP_PASS') ?: '',
    'envelope_from' => getenv('SMTP_USER') ?: '',

    'batch'         => 20,
    'max_per_run'   => 100,
    'max_runtime'   => 50,
];
