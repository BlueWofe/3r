<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FirstPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $role = Role::create(['name' => '示範管理員', 'slug' => 'system-admin', 'active' => true, 'permissions' => []]);
        $admin = User::create(['name' => '管理員', 'phone' => '0900000001', 'email' => 'admin@example.test', 'password' => 'Established-Password']);
        $admin->roles()->attach($role);

        return $admin;
    }

    private function createStaff(User $admin, array $extra = []): User
    {
        $role = Role::create(['name' => '示範志工', 'slug' => 'volunteer', 'active' => true, 'permissions' => ['donations.read.own']]);
        $this->actingAs($admin)->postJson('/api/v1/users', array_merge([
            'name' => '新志工', 'phone' => '0900000002', 'password' => '123456789',
            'active' => true, 'role_ids' => [$role->id],
        ], $extra))->assertOk();

        return User::where('phone', '0900000002')->firstOrFail();
    }

    public function test_admin_created_password_requires_change_and_client_cannot_override_flag(): void
    {
        $admin = $this->admin();
        $staff = $this->createStaff($admin, ['must_change_password' => false]);
        $this->assertTrue($staff->must_change_password);
        $this->assertTrue(Hash::check('123456789', $staff->password));
        $this->actingAs($staff)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.must_change_password', true);
        $this->actingAs($admin)->putJson('/api/v1/users/'.$staff->id, [
            'name' => '新志工', 'active' => true, 'role_ids' => $staff->roles->pluck('id')->all(),
            'must_change_password' => false,
        ])->assertOk();
        $this->assertTrue($staff->fresh()->must_change_password);
        $staff->update(['remember_token' => 'remember-before-admin-reset']);
        $this->putJson('/api/v1/users/'.$staff->id, [
            'name' => '新志工', 'active' => true, 'role_ids' => $staff->roles->pluck('id')->all(),
            'password' => '987654321', 'must_change_password' => false,
        ])->assertOk();
        $this->assertTrue($staff->fresh()->must_change_password);
        $this->assertTrue(Hash::check('987654321', $staff->fresh()->password));
        $this->assertNull($staff->fresh()->remember_token);
    }

    public function test_flagged_staff_cannot_access_business_or_file_apis_or_submit_public_requests(): void
    {
        $staff = $this->createStaff($this->admin());
        $this->actingAs($staff);
        foreach ([['get', '/api/v1/donations'], ['get', '/api/v1/files/999/download'], ['put', '/api/v1/auth/profile'], ['post', '/api/v1/public/contact']] as [$method, $path]) {
            $this->{$method.'Json'}($path)->assertStatus(403)->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED')->assertJsonPath('message', '首次登入請先更改密碼');
        }
        $this->getJson('/api/v1/public/contact')->assertOk();
        $this->getJson('/api/v1/auth/csrf')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/public/contact')->assertUnprocessable();
    }

    public function test_forced_change_rejects_old_or_same_password_then_restores_access(): void
    {
        $staff = $this->createStaff($this->admin(), ['password' => '1234567890']);
        $staff->update(['remember_token' => 'remember-before-change']);
        DB::table('sessions')->insert([
            'id' => str_repeat('a', 40), 'user_id' => $staff->id, 'payload' => '', 'last_activity' => now()->timestamp,
        ]);
        $this->actingAs($staff)->putJson('/api/v1/auth/password', [
            'current_password' => 'wrong', 'password' => 'A-New-Password', 'password_confirmation' => 'A-New-Password',
        ])->assertUnprocessable();
        $this->putJson('/api/v1/auth/password', [
            'current_password' => '1234567890', 'password' => '1234567890', 'password_confirmation' => '1234567890',
        ])->assertUnprocessable();
        $this->assertTrue($staff->fresh()->must_change_password);
        $this->putJson('/api/v1/auth/password', [
            'current_password' => '1234567890', 'password' => 'A-New-Password', 'password_confirmation' => 'A-New-Password',
        ])->assertOk();
        $this->assertFalse($staff->fresh()->must_change_password);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('user.must_change_password', false);
        $this->getJson('/api/v1/donations')->assertOk();
        $this->assertTrue(Hash::check('A-New-Password', $staff->fresh()->password));
        $this->assertNull($staff->fresh()->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $staff->id)->count());
    }

    public function test_otp_reset_requires_new_password_and_clears_flag_without_changing_roles(): void
    {
        $staff = $this->createStaff($this->admin(), ['password' => 'Initial-Password']);
        $originalRoles = $staff->roles->pluck('id')->all();
        DB::table('otps')->insert([
            'phone' => $staff->phone, 'purpose' => 'reset', 'hash' => Hash::make('654321'),
            'expires_at' => now()->addMinutes(5), 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($staff)->postJson('/api/v1/auth/reset-password', [
            'phone' => $staff->phone, 'code' => '654321', 'password' => 'Initial-Password', 'password_confirmation' => 'Initial-Password',
        ])->assertUnprocessable();
        $this->assertTrue($staff->fresh()->must_change_password);
        DB::table('otps')->insert([
            'phone' => $staff->phone, 'purpose' => 'reset', 'hash' => Hash::make('123123'),
            'expires_at' => now()->addMinutes(5), 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/v1/auth/reset-password', [
            'phone' => $staff->phone, 'code' => '123123', 'password' => '1234567890', 'password_confirmation' => '1234567890',
        ])->assertOk();
        $this->assertFalse($staff->fresh()->must_change_password);
        $this->assertSame($originalRoles, $staff->fresh()->roles->pluck('id')->all());
        $this->assertTrue(Hash::check('1234567890', $staff->fresh()->password));
    }

    public function test_otp_registration_and_existing_accounts_start_without_force_flag(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->getJson('/api/v1/auth/me')->assertJsonPath('user.must_change_password', false);
        Storage::fake('local');
        config(['ministry.otp_test_phones' => '0912345678', 'ministry.sms_driver' => 'mock']);
        $this->postJson('/api/v1/auth/otp', ['phone' => '0912345678', 'purpose' => 'register'])->assertOk();
        $code = json_decode(Storage::disk('local')->get('otp-mailbox/0912345678.json'), true)['code'];
        $this->postJson('/api/v1/auth/register', [
            'phone' => '0912345678', 'name' => '會員', 'code' => $code,
            'password' => 'Member-Password', 'password_confirmation' => 'Member-Password',
        ])->assertOk();
        $member = User::where('phone', '0912345678')->firstOrFail();
        $this->assertFalse($member->must_change_password);
        $this->actingAs($member)->getJson('/api/v1/donations')->assertOk();
    }

    public function test_bad_reset_codes_keep_the_five_attempt_limit(): void
    {
        $staff = $this->createStaff($this->admin());
        DB::table('otps')->insert([
            'phone' => $staff->phone, 'purpose' => 'reset', 'hash' => Hash::make('654321'),
            'expires_at' => now()->addMinutes(5), 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($staff);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/reset-password', [
                'phone' => $staff->phone, 'code' => '000000', 'password' => 'Another-Password', 'password_confirmation' => 'Another-Password',
            ])->assertUnprocessable();
        }
        $this->assertSame(5, (int) DB::table('otps')->where('phone', $staff->phone)->value('attempts'));
        $this->postJson('/api/v1/auth/reset-password', [
            'phone' => $staff->phone, 'code' => '654321', 'password' => 'Another-Password', 'password_confirmation' => 'Another-Password',
        ])->assertUnprocessable();
        $this->assertTrue($staff->fresh()->must_change_password);
    }
}
