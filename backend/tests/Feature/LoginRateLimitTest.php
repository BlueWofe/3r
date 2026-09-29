<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function account(): User
    {
        return User::create(['name' => '示範', 'phone' => '0912345678', 'email' => 'login@demo.invalid', 'password' => 'Test-Only-Password']);
    }

    public function test_five_failures_lock_phone_and_ip_for_one_minute(): void
    {
        $this->account();
        $bad = ['phone' => '0912345678', 'password' => 'wrong'];
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', $bad)->assertUnprocessable();
        }$good = ['phone' => '0912345678', 'password' => 'Test-Only-Password'];
        $this->postJson('/api/v1/auth/login', $good)->assertStatus(429);
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/login', $good)->assertOk();
    }

    public function test_success_clears_failures_and_does_not_consume_shared_auth_budget(): void
    {
        $this->account();
        $bad = ['phone' => '0912345678', 'password' => 'wrong'];
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/login', $bad)->assertUnprocessable();
        }$good = ['phone' => '0912345678', 'password' => 'Test-Only-Password'];
        for ($i = 0; $i < 12; $i++) {
            $this->postJson('/api/v1/auth/login', $good)->assertOk();
        }for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', $bad)->assertUnprocessable();
        }$this->postJson('/api/v1/auth/login', $bad)->assertStatus(429);
    }

    public function test_other_phone_has_independent_failure_budget(): void
    {
        $this->account();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['phone' => '0999999999', 'password' => 'wrong'])->assertUnprocessable();
        }$this->postJson('/api/v1/auth/login', ['phone' => '0912345678', 'password' => 'Test-Only-Password'])->assertOk();
    }
}
