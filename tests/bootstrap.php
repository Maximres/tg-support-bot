<?php

/**
 * Кастомный bootstrap для PHPUnit вместо vendor/autoload.php напрямую.
 *
 * <env> в phpunit.xml здесь не срабатывает: DB_CONNECTION и другие переменные
 * уже выставлены как настоящие переменные окружения процесса (через
 * docker-compose env_file: .env) до старта PHP, а PHPUnit/dotenv в этой
 * связке не переопределяют уже существующие переменные окружения даже
 * с force="true". Из-за этого тесты с RefreshDatabase запускались прямо
 * на боевой БД при выполнении внутри деплой-контейнера.
 *
 * putenv() ниже — настоящий вызов ОС-уровня, выполняется раньше, чем
 * что-либо ещё, и гарантированно выигрывает у любых унаследованных
 * значений при последующей загрузке .env через vlucas/phpdotenv.
 */

$testEnv = [
    'APP_ENV' => 'testing',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'MAIL_MAILER' => 'array',
    'PULSE_ENABLED' => 'false',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'TELESCOPE_ENABLED' => 'false',
];

foreach ($testEnv as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require __DIR__ . '/../vendor/autoload.php';
