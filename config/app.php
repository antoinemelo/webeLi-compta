<?php
declare(strict_types=1);

$environment = getenv('APP_ENV') ?: 'dev';

return [
    'env' => $environment,
    'debug' => (getenv('APP_DEBUG') ?: '1') === '1',
    'instance_id' => getenv('APP_INSTANCE_ID') ?: '',
    'base_url' => getenv('APP_BASE_URL') ?: '',
    'public_url' => getenv('APP_PUBLIC_URL') ?: '',
    'storage_path' => getenv('APP_STORAGE_PATH') ?: '',
    'database_path' => getenv('APP_DB_PATH') ?: '',
    'ocas_database_path' => getenv('OCAS_DB_PATH') ?: '',
    'setup_secret' => getenv('APP_SETUP_SECRET') ?: '',
    'session_idle_seconds' => 1800,
    'session_absolute_seconds' => 43200,
    'login_max_attempts' => 5,
    'login_window_seconds' => 900,
    'password_reset_seconds' => 900,
    'password_reset_email_limit' => 3,
    'password_reset_ip_limit' => 10,
    'mfa_challenge_seconds' => 600,
    'mfa_max_attempts' => 5,
    'mfa_encryption_key' => getenv('APP_MFA_KEY') ?: '',
    'mail_transport' => getenv('APP_MAIL_TRANSPORT') ?: 'php',
    'mail_from_address' => getenv('APP_MAIL_FROM') ?: 'no-reply@localhost',
    'mail_from_name' => getenv('APP_MAIL_FROM_NAME') ?: 'Compta',
    'smtp_host' => getenv('APP_SMTP_HOST') ?: '',
    'smtp_port' => (int) (getenv('APP_SMTP_PORT') ?: 587),
    'smtp_encryption' => getenv('APP_SMTP_ENCRYPTION') ?: 'tls',
    'smtp_username' => getenv('APP_SMTP_USERNAME') ?: '',
    'smtp_password' => getenv('APP_SMTP_PASSWORD') ?: '',
];
