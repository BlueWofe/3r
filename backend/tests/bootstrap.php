<?php

// PHPUnit's XML env settings may leave real process environment variables intact.
// Set all three sources before Laravel creates its environment repository.
$isolatedEnvironment = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)),
    'APP_MAINTENANCE_DRIVER' => 'file',
    'APP_CONFIG_CACHE' => __DIR__.'/.disabled-config-cache.php',
    'APP_ROUTES_CACHE' => __DIR__.'/.disabled-routes-cache.php',
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    'CACHE_DRIVER' => 'array',
    'SESSION_DRIVER' => 'array',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'DATABASE_URL' => '',
    'DB_HOST' => '',
    'DB_PORT' => '',
    'DB_USERNAME' => '',
    'DB_PASSWORD' => '',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'OTP_DRIVER' => 'mock',
    'SMS_DRIVER' => 'mock',
    'MITAKE_USERNAME' => '',
    'MITAKE_PASSWORD' => '',
    'DEMO_SEED' => 'false',
    'DEMO_PASSWORD' => '',
    'REDIS_URL' => '',
    'REDIS_HOST' => '127.0.0.1',
    'REDIS_PORT' => '0',
    'REDIS_PASSWORD' => '',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
];

foreach ([$isolatedEnvironment['APP_CONFIG_CACHE'], $isolatedEnvironment['APP_ROUTES_CACHE']] as $cachePath) {
    if (is_file($cachePath)) {
        throw new RuntimeException('Test isolation cache path must not contain a cached configuration or routes file.');
    }
}

foreach ($isolatedEnvironment as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require dirname(__DIR__).'/vendor/autoload.php';
