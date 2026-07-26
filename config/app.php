<?php
declare(strict_types=1);

$environment = getenv('APP_ENV') ?: 'dev';
$vueShellFlag = getenv('APP_VUE_SHELL_ENABLED');
$vueShellEnabled = $vueShellFlag === false
    ? $environment !== 'prod'
    : $vueShellFlag === '1';

return [
    'env' => $environment,
    'debug' => (getenv('APP_DEBUG') ?: '1') === '1',
    'instance_id' => getenv('APP_INSTANCE_ID') ?: '',
    'base_url' => getenv('APP_BASE_URL') ?: '',
    'storage_path' => getenv('APP_STORAGE_PATH') ?: '',
    'database_path' => getenv('APP_DB_PATH') ?: '',
    'lasso_database_path' => getenv('LASSO_DB_PATH') ?: '',
    'setup_secret' => getenv('APP_SETUP_SECRET') ?: '',
    'vue_shell_enabled' => $vueShellEnabled,
    'session_idle_seconds' => 1800,
    'session_absolute_seconds' => 43200,
    'login_max_attempts' => 5,
    'login_window_seconds' => 900,
];
