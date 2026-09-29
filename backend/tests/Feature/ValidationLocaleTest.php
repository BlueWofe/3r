<?php

namespace Tests\Feature;

use Tests\TestCase;

class ValidationLocaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('zh_TW');
    }

    public function test_required_and_phone_format_errors_use_traditional_chinese(): void
    {
        $this->postJson('/api/v1/auth/login', [])->assertUnprocessable()->assertJsonPath('errors.phone.0', '請填寫 手機號碼。')->assertJsonPath('errors.password.0', '請填寫 密碼。')->assertJsonPath('message', '請填寫 手機號碼。 （另有 1 項錯誤）');
        $this->postJson('/api/v1/auth/login', ['phone' => 'invalid', 'password' => 'test'])->assertUnprocessable()->assertJsonPath('errors.phone.0', '手機號碼 格式不正確。');
    }

    public function test_password_length_and_confirmation_errors_use_traditional_chinese(): void
    {
        $this->postJson('/api/v1/auth/register', ['phone' => '0912345678', 'name' => '示範', 'code' => 'invalid', 'password' => 'short', 'password_confirmation' => 'different'])->assertUnprocessable()->assertJsonPath('errors.password.0', '密碼 與確認欄位不一致。')->assertJsonPath('errors.password.1', '密碼 至少需有 10 個字元。');
    }
}
