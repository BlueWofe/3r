<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ServiceSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthorizationRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $slug, array $permissions = []): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug, 'permissions' => $permissions]);
        $u = User::create(['name' => '示範', 'phone' => '09'.str_pad((string) (User::count() + 1), 8, '0', STR_PAD_LEFT), 'email' => uniqid().'@demo.invalid', 'password' => 'Test-Only-Password']);
        $u->roles()->attach($role);

        return $u;
    }

    private function teacher(): User
    {
        return $this->user('teacher', ['schedule.read.own', 'schedule.update.own']);
    }

    private function course(User $u): ServiceSession
    {
        $s = ServiceSession::create(['data' => ['title' => '示範', 'prison' => '示範', 'location' => '示範', 'participant_count' => 1, 'service_date' => '2030-01-01', 'start_time' => '09:00', 'end_time' => '11:00', 'status' => 'scheduled', 'original_teacher_count' => 1]]);
        $s->assignments()->create(['teacher_id' => $u->id]);

        return $s;
    }

    public function test_user_manager_cannot_promote_self_or_assign_roles_on_create(): void
    {
        $admin = $this->user('system-admin');
        $manager = $this->user('user-manager', ['users.update.all', 'users.create.all']);
        $ownRole = $manager->roles->first()->id;
        $adminRole = $admin->roles->first()->id;
        $this->actingAs($manager)->putJson('/api/v1/users/'.$manager->id, ['name' => '更新名稱', 'active' => true, 'role_ids' => [$ownRole]])->assertOk();
        $this->putJson('/api/v1/users/'.$manager->id, ['name' => '提升', 'active' => true, 'role_ids' => [$ownRole, $adminRole]])->assertForbidden();
        $this->putJson('/api/v1/users/'.$admin->id, ['name' => '接管', 'active' => true, 'role_ids' => [$adminRole], 'password' => 'Changed-Test-Password'])->assertForbidden();
        $this->postJson('/api/v1/users', ['name' => '示範', 'active' => true, 'phone' => '0912345678', 'password' => 'Test-Only-Password', 'role_ids' => [$adminRole]])->assertForbidden();
        $this->assertFalse($manager->fresh()->roles->contains('slug', 'system-admin'));
    }

    public function test_teacher_cannot_restore_cancelled_session_or_edit_administrative_fields(): void
    {
        $u = $this->teacher();
        $s = $this->course($u);
        $this->actingAs($u)->putJson('/api/v1/sessions/'.$s->id, ['version' => 1, 'reason' => '示範', 'title' => '擅改'])->assertForbidden();
        $this->putJson('/api/v1/sessions/'.$s->id, ['version' => 1, 'reason' => '示範', 'participant_count' => 9])->assertForbidden();
        $this->putJson('/api/v1/sessions/'.$s->id, ['version' => 1, 'reason' => '示範', 'service_date' => '2020-01-01'])->assertUnprocessable();
        $this->putJson('/api/v1/sessions/'.$s->id, ['version' => 1, 'reason' => '示範', 'status' => 'cancelled'])->assertOk();
        $this->putJson('/api/v1/sessions/'.$s->id, ['version' => 2, 'reason' => '示範', 'status' => 'scheduled'])->assertConflict();
        $this->actingAs($this->user('system-admin'))->putJson('/api/v1/sessions/'.$s->id, ['version' => 2, 'reason' => '管理員恢復', 'status' => 'scheduled'])->assertOk();
    }

    public function test_revoked_teacher_cannot_read_or_respond_to_invitation(): void
    {
        $u = $this->teacher();
        $t = $this->teacher();
        $s = $this->course($u);
        $a = $s->assignments()->first();
        $this->actingAs($u)->postJson("/api/v1/assignments/$a->id/invite", ['version' => 1, 'reason' => '示範', 'teacher_id' => $t->id])->assertOk();
        $id = DB::table('invitations')->value('id');
        $t->roles()->detach();
        $this->actingAs($t)->getJson('/api/v1/invitations')->assertForbidden();
        $this->postJson("/api/v1/invitations/$id/respond", ['action' => 'accept'])->assertForbidden();
    }

    public function test_admin_cannot_issue_invitation_for_expired_or_attended_course(): void
    {
        $admin = $this->user('system-admin');
        $u = $this->teacher();
        $t = $this->teacher();
        $s = $this->course($u);
        $a = $s->assignments()->first();
        $a->update(['attendance' => ['present' => true]]);
        $this->actingAs($admin)->postJson("/api/v1/assignments/$a->id/invite", ['version' => 1, 'reason' => '示範', 'teacher_id' => $t->id])->assertConflict();
        $a->update(['attendance' => null]);
        $d = $s->data;
        $d['service_date'] = '2020-01-01';
        $s->update(['data' => $d]);
        $this->postJson("/api/v1/assignments/$a->id/invite", ['version' => 1, 'reason' => '示範', 'teacher_id' => $t->id])->assertConflict();
    }

    public function test_admin_restore_requires_explicit_attendance_resolution(): void
    {
        $admin = $this->user('system-admin');
        $s = $this->course($this->teacher());
        $d = $s->data;
        $d['status'] = 'cancelled';
        $s->update(['data' => $d]);
        $a = $s->assignments()->first();
        $a->update(['attendance' => ['present' => true]]);
        $this->actingAs($admin)->putJson('/api/v1/sessions/'.$s->id, ['version' => 1, 'reason' => '歷史資料更正', 'status' => 'scheduled'])->assertConflict();
        $this->putJson('/api/v1/sessions/'.$s->id, ['version' => 1, 'reason' => '歷史資料更正', 'status' => 'scheduled', 'attendance_resolution' => 'void'])->assertOk();
        $this->assertNull($a->fresh()->attendance);
    }

    public function test_create_conflict_override_requires_manager_permission_and_reason(): void
    {
        $admin = $this->user('system-admin');
        $u = $this->teacher();
        $this->course($u);
        $body = ['title' => '示範', 'prison' => '示範', 'location' => '示範', 'participant_count' => 1, 'service_date' => '2030-01-01', 'start_time' => '10:00', 'end_time' => '12:00', 'teacher_ids' => [$u->id], 'override_conflict' => true];
        $this->actingAs($admin)->postJson('/api/v1/sessions', $body)->assertUnprocessable();
        $body['reason'] = '明確覆核';
        $this->postJson('/api/v1/sessions', $body)->assertOk()->assertJsonPath('version', 1);
        $creator = $this->user('creator', ['schedule.create.all']);
        $this->actingAs($creator)->postJson('/api/v1/sessions', $body)->assertForbidden();
    }

    public function test_serialized_admin_removals_leave_one_active_admin(): void
    {
        $one = $this->user('system-admin');
        $two = $this->user('system-admin');
        $role = $one->roles->first()->id;
        $this->actingAs($one)->putJson('/api/v1/users/'.$two->id, ['name' => '停用', 'active' => false, 'role_ids' => [$role]])->assertOk();
        $this->actingAs($one)->putJson('/api/v1/users/'.$one->id, ['name' => '停用', 'active' => false, 'role_ids' => [$role]])->assertConflict();
        $this->assertEquals(1, User::where('active', true)->whereHas('roles', fn ($q) => $q->where('slug', 'system-admin')->where('active', true))->count());
    }
}
