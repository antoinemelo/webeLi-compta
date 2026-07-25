<?php
declare(strict_types=1);

return [
    'env' => getenv('APP_ENV') ?: 'dev',
    'debug' => (getenv('APP_DEBUG') ?: '1') === '1',
    'instance_id' => getenv('APP_INSTANCE_ID') ?: '',
    'base_url' => getenv('APP_BASE_URL') ?: '',
    'storage_path' => getenv('APP_STORAGE_PATH') ?: '',
    'database_path' => getenv('APP_DB_PATH') ?: '',
    'setup_secret' => getenv('APP_SETUP_SECRET') ?: '',
    'vue_shell_enabled' => (getenv('APP_VUE_SHELL_ENABLED') ?: '0') === '1',
    'session_idle_seconds' => 1800,
    'session_absolute_seconds' => 43200,
    'login_max_attempts' => 5,
    'login_window_seconds' => 900,
];
