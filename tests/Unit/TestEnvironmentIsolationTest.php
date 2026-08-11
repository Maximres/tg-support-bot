<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Гарантирует, что тесты реально изолированы от боевой БД, даже когда
 * запускаются внутри деплой-контейнера, где .env уже выставляет реальные
 * переменные окружения (DB_CONNECTION=pgsql и т.п.) как настоящие env vars
 * процесса. Без force="true" в phpunit.xml переопределения <env> тихо
 * игнорируются, и тесты с RefreshDatabase начинают писать в боевую БД.
 */
class TestEnvironmentIsolationTest extends TestCase
{
    public function test_database_connection_is_isolated_sqlite(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_app_env_is_testing(): void
    {
        $this->assertSame('testing', config('app.env'));
    }
}
