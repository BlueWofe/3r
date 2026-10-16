<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestIsolationTest extends TestCase
{
    public function test_framework_and_process_environment_are_isolated(): void
    {
        $this->assertSame('testing', getenv('APP_ENV'));
        $this->assertSame('sqlite', getenv('DB_CONNECTION'));
        $this->assertSame(':memory:', getenv('DB_DATABASE'));
        $this->assertSame('sqlite', $_SERVER['DB_CONNECTION']);
        $this->assertSame('sqlite', $_ENV['DB_CONNECTION']);
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('mock', config('ministry.sms_driver'));
    }
}
