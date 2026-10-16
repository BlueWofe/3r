<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OtpAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_http_success_with_error_code_never_creates_otp(): void
    {
        config(['ministry.sms_driver' => 'mitake', 'ministry.mitake_username' => 'test-user', 'ministry.mitake_password' => 'test-secret']);
        Http::fake(['*' => Http::response("statuscode=e\nError=Rejected", 200)]);
        $this->postJson('/api/v1/auth/otp', ['phone' => '0912345678', 'purpose' => 'register'])->assertStatus(503);
        $this->assertDatabaseCount('otps', 0);
    }

    public function test_provider_accepted_code_uses_utf8_and_form_credentials(): void
    {
        config(['ministry.sms_driver' => 'mitake', 'ministry.mitake_username' => 'test-user', 'ministry.mitake_password' => 'test-secret']);
        Http::fake(['*' => Http::response("[1]\nmsgid=demo\nstatuscode=1\n", 200)]);
        $this->postJson('/api/v1/auth/otp', ['phone' => '0912345678', 'purpose' => 'register'])->assertOk()->assertJsonMissingPath('code');
        $this->assertDatabaseCount('otps', 1);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'CharsetURL=UTF8') && ! str_contains($r->url(), 'test-secret') && $r['username'] === 'test-user');
        $this->postJson('/api/v1/auth/otp', ['phone' => '0912345678', 'purpose' => 'register'])->assertStatus(429);
        Http::assertSentCount(1);
    }

    public function test_phone_lock_blocks_overlapping_delivery(): void
    {
        config(['ministry.sms_driver' => 'mock', 'ministry.otp_test_phones' => '0912345678']);
        $lock = Cache::lock('otp:'.hash('sha256', '0912345678'), 15);
        $this->assertTrue($lock->get());
        $this->postJson('/api/v1/auth/otp', ['phone' => '0912345678', 'purpose' => 'register'])->assertStatus(429);
        $lock->release();
        $this->assertDatabaseCount('otps', 0);
    }

    public function test_five_failed_attempts_exhaust_code(): void
    {
        Storage::fake('local');
        config(['ministry.sms_driver' => 'mock', 'ministry.otp_test_phones' => '0912345678']);
        $this->postJson('/api/v1/auth/otp', ['phone' => '0912345678', 'purpose' => 'register'])->assertOk();
        $code = json_decode(Storage::disk('local')->get('otp-mailbox/0912345678.json'), true)['code'];
        $body = ['phone' => '0912345678', 'name' => '示範', 'password' => 'Test-Only-Password', 'password_confirmation' => 'Test-Only-Password', 'code' => 'invalid'];
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/register', $body)->assertUnprocessable();
        }$body['code'] = $code;
        $this->postJson('/api/v1/auth/register', $body)->assertUnprocessable();
    }
}
