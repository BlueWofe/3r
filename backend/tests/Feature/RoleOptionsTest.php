<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $permissions): User
    {
        $role = Role::create(['name' => '示範管理角色', 'slug' => uniqid('manager-'), 'permissions' => $permissions]);
        $user = User::create(['name' => '示範', 'phone' => '09'.str_pad((string) (User::count() + 1), 8, '0', STR_PAD_LEFT), 'email' => uniqid().'@demo.invalid', 'password' => 'Test-Only-Password']);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_only_authorized_managers_can_list_minimal_active_role_options(): void
    {
        Role::create(['name' => '停用角色', 'slug' => 'inactive', 'active' => false, 'permissions' => ['settings.manage.all']]);
        foreach (['roles.manage.all', 'forms.create.all', 'forms.update.all', 'meetings.create.all', 'meetings.update.all', 'resources.create.all'] as $permission) {
            $user = $this->user([$permission]);
            $response = $this->actingAs($user)->getJson('/api/v1/role-options')->assertOk();
            $options = $response->json('data');
            $this->assertNotEmpty($options);
            foreach ($options as $option) {
                $this->assertSame(['id', 'name'], array_keys($option));
            }$response->assertJsonMissing(['name' => '停用角色']);
            if ($permission !== 'roles.manage.all') {
                $this->getJson('/api/v1/roles')->assertForbidden();
            }
        }
    }

    public function test_teacher_member_and_resource_own_creator_cannot_enumerate_roles(): void
    {
        $this->getJson('/api/v1/role-options')->assertUnauthorized();
        foreach ([['forms.read.own'], ['schedule.read.own'], ['resources.create.own'], ['resources.read.all'], ['content.create.all']] as $permissions) {
            $this->actingAs($this->user($permissions))->getJson('/api/v1/role-options')->assertForbidden();
        }
    }
}
