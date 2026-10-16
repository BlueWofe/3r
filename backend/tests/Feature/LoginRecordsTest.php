<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $phone, array $roles): User
    {
        $user = User::create(['name' => '示範'.$phone, 'phone' => $phone, 'email' => $phone.'@example.test', 'password' => 'Valid-Test-Password']);
        $user->roles()->attach(collect($roles)->pluck('id'));

        return $user;
    }

    public function test_login_records_keep_role_snapshots_without_secrets_and_support_filters(): void
    {
        $adminRole = Role::create(['name' => '系統管理員', 'slug' => 'system-admin', 'permissions' => []]);
        $teacherRole = Role::create(['name' => '教師', 'slug' => 'teacher', 'permissions' => ['schedule.read.own']]);
        $memberRole = Role::create(['name' => '會員', 'slug' => 'member', 'permissions' => ['donations.read.own']]);
        $admin = $this->account('0900000001', [$adminRole]);
        $teacher = $this->account('0900000002', [$teacherRole, $memberRole]);

        $this->postJson('/api/v1/auth/login', ['phone' => $teacher->phone, 'password' => 'Valid-Test-Password'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['phone' => $teacher->phone, 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['phone' => '0999999999', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['phone' => $admin->phone, 'password' => 'Valid-Test-Password'])->assertOk();

        $this->assertSame(4, Entity::where('type', 'login-records')->count());
        $rows = Entity::where('type', 'login-records')->get();
        $this->assertFalse($rows->pluck('data')->contains(fn ($data) => str_contains(json_encode($data), 'Valid-Test-Password') || str_contains(json_encode($data), '0999999999')));
        $unknown = $rows->first(fn ($row) => $row->owner_id === null);
        $this->assertSame('未識別帳號', $unknown->data['name']);

        $this->actingAs($admin)->getJson('/api/v1/login-records?category=volunteer&role_id='.$teacherRole->id.'&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.roles.0.name', '教師')->assertJsonPath('data.0.categories.0', 'volunteer');
        $this->getJson('/api/v1/login-records?category=member')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/login-records?category=admin')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/login-records?from='.now()->format('Y-m-d'))->assertJsonPath('meta.total', 4);
        $this->getJson('/api/v1/login-records?to='.now()->format('Y-m-d'))->assertJsonPath('meta.total', 4);
        $this->getJson('/api/v1/login-records?from=2030-01-02&to=2030-01-01')->assertUnprocessable();

        $teacherRole->update(['name' => '新教師名稱']);
        $this->getJson('/api/v1/login-records?role_id='.$teacherRole->id)->assertJsonPath('data.0.roles.0.name', '教師');
        $this->postJson('/api/v1/login-records')->assertStatus(405);
    }

    public function test_only_user_or_role_administrators_can_read_records_and_force_flag_blocks_them(): void
    {
        $readerRole = Role::create(['name' => '讀取使用者', 'slug' => 'user-reader', 'permissions' => ['users.read.all']]);
        $roleAdmin = Role::create(['name' => '角色管理', 'slug' => 'role-admin', 'permissions' => ['roles.manage.all']]);
        $memberRole = Role::create(['name' => '會員', 'slug' => 'member', 'permissions' => []]);
        $reader = $this->account('0900000003', [$readerRole]);
        $roles = $this->account('0900000004', [$roleAdmin]);
        $member = $this->account('0900000005', [$memberRole]);
        $this->actingAs($reader)->getJson('/api/v1/login-records')->assertOk();
        $this->actingAs($roles)->getJson('/api/v1/login-records')->assertOk();
        $this->actingAs($member)->getJson('/api/v1/login-records')->assertForbidden();
        $reader->update(['must_change_password' => true]);
        $this->actingAs($reader->fresh())->getJson('/api/v1/login-records')->assertStatus(403)->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->getJson('/api/v1/login-records?per_page=500')->assertStatus(403);
    }
}
