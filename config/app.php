<?php
declare(strict_types=1);

$environment = getenv('APP_ENV') ?: 'dev';

return [
    'env' => $environment,
    'debug' => (getenv('APP_DEBUG') ?: '1') === '1',
    'instance_id' => getenv('APP_INSTANCE_ID') ?: '',
    'base_url' => getenv('APP_BASE_URL') ?: '',
    'storage_path' => getenv('APP_STORAGE_PATH') ?: '',
    'database_path' => getenv('APP_DB_PATH') ?: '',
    'ocas_database_path' => getenv('OCAS_DB_PATH') ?: '',
    'setup_secret' => getenv('APP_SETUP_SECRET') ?: '',
    'session_idle_seconds' => 1800,
    'session_absolute_seconds' => 43200,
    'login_max_attempts' => 5,
    'login_window_seconds' => 900,
];
