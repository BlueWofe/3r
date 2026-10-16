<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        Redis::shouldReceive('connection')->once()->andReturnSelf();
        Redis::shouldReceive('ping')->once()->andReturn('PONG');
        $response = $this->get('/api/v1/health');

        $response->assertStatus(200);
    }

    public function test_health_hides_dependency_error_details(): void
    {
        Redis::shouldReceive('connection')->once()->andThrow(new \RuntimeException('secret connection data'));
        $this->getJson('/api/v1/health')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
    }
}
