<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Role;
use App\Models\ServiceSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchedulePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_return_an_original_teacher_without_duplicate_assignment(): void
    {
        $admin = $this->teacher();
        $role = Role::create(['name' => '管理員', 'slug' => 'system-admin', 'permissions' => []]);
        $admin->roles()->attach($role);
        $original = $this->teacher();
        $sub = $this->teacher();
        $s = $this->course([$original]);
        $a = $s->assignments()->first();
        $this->actingAs($admin)->postJson("/api/v1/assignments/$a->id/replace", ['version' => 1, 'reason' => '示範', 'teacher_id' => $sub->id])->assertOk();
        $replacement = $s->assignments()->where('teacher_id', $sub->id)->first();
        $this->postJson("/api/v1/assignments/$replacement->id/replace", ['version' => 2, 'reason' => '恢復原教師', 'teacher_id' => $original->id])->assertOk();
        $this->assertEquals('assigned', $a->fresh()->status);
        $this->assertEquals(2, $s->assignments()->count());
    }

    public function test_course_change_notifies_only_related_teachers_and_active_system_admins(): void
    {
        $admin = $this->teacher();
        $admin->roles()->attach(Role::create(['name' => '系統管理員', 'slug' => 'system-admin', 'permissions' => []]));
        $old = $this->teacher();
        $replacement = $this->teacher();
        $unrelated = $this->teacher();
        $manager = $this->teacher();
        $manager->roles()->attach(Role::create(['name' => '排課管理', 'slug' => 'schedule-manager', 'permissions' => ['schedule.read.all', 'schedule.update.all']]));
        $session = $this->course([$old]);
        $assignment = $session->assignments()->first();

        $this->actingAs($admin)->postJson('/api/v1/assignments/'.$assignment->id.'/replace', ['version' => 1, 'reason' => '示範代課', 'teacher_id' => $replacement->id])->assertOk();
        $owners = Entity::where('type', 'notifications')->pluck('owner_id')->sort()->values()->all();
        $this->assertSame(collect([$admin->id, $old->id, $replacement->id])->sort()->values()->all(), $owners);
        $this->assertNotContains($unrelated->id, $owners);
        $this->assertNotContains($manager->id, $owners);

        $this->putJson('/api/v1/sessions/'.$session->id, ['version' => 2, 'reason' => '示範改期', 'service_date' => '2030-01-02'])->assertOk();
        $this->assertSame(1, Entity::where('type', 'notifications')->where('owner_id', $old->id)->count());
        $this->assertSame(2, Entity::where('type', 'notifications')->where('owner_id', $replacement->id)->count());

        $current = $session->assignments()->where('teacher_id', $replacement->id)->first();
        $this->postJson('/api/v1/assignments/'.$current->id.'/attendance', ['reason' => '示範補登'])->assertOk();
        $this->assertSame(5, Entity::where('type', 'notifications')->count());
        $this->assertSame(3, Entity::where('type', 'changes')->count());
    }

    private function teacher(): User
    {
        $role = Role::firstOrCreate(['slug' => 'teacher'], ['name' => '教師', 'permissions' => ['schedule.read.own', 'schedule.update.own', 'attendance.create.own']]);
        $u = User::create(['name' => '示範', 'phone' => '09'.str_pad((string) (User::count() + 1), 8, '0', STR_PAD_LEFT), 'email' => uniqid().'@demo.invalid', 'password' => 'Test-Only-Password']);
        $u->roles()->attach($role);

        return $u;
    }

    private function course(array $teachers): ServiceSession
    {
        $s = ServiceSession::create(['data' => ['title' => '示範', 'prison' => '示範', 'location' => '示範', 'participant_count' => 1, 'service_date' => '2030-01-01', 'start_time' => '09:00', 'end_time' => '11:00', 'status' => 'scheduled', 'original_teacher_count' => count($teachers)]]);
        foreach ($teachers as $u) {
            $s->assignments()->create(['teacher_id' => $u->id]);
        }

        return $s;
    }

    public function test_sole_teacher_can_modify_but_shared_course_stays_shared_after_leave(): void
    {
        $u = $this->teacher();
        $other = $this->teacher();
        $s = $this->course([$u]);
        $this->actingAs($u)->putJson('/api/v1/sessions/'.$s->id, ['version' => 1, 'reason' => '示範', 'location' => '示範新地點'])->assertOk();
        $shared = $this->course([$u, $other]);
        $a = $shared->assignments()->where('teacher_id', $other->id)->first();
        $this->actingAs($other)->postJson("/api/v1/assignments/$a->id/leave", ['version' => 1, 'reason' => '示範'])->assertOk();
        $this->actingAs($u)->putJson('/api/v1/sessions/'.$shared->id, ['version' => 2, 'reason' => '示範', 'location' => '示範'])->assertForbidden();
    }

    public function test_assigned_teacher_can_invite_without_abandoning_assignment_and_change_cancels_pending(): void
    {
        $u = $this->teacher();
        $t = $this->teacher();
        $s = $this->course([$u]);
        $a = $s->assignments()->first();
        $this->actingAs($u)->postJson("/api/v1/assignments/$a->id/invite", ['version' => 1, 'reason' => '示範', 'teacher_id' => $t->id])->assertOk();
        $this->assertEquals('assigned', $a->fresh()->status);
        $id = DB::table('invitations')->value('id');
        $this->putJson('/api/v1/sessions/'.$s->id, ['version' => 2, 'reason' => '示範', 'location' => '新地點'])->assertOk();
        $this->actingAs($t)->postJson("/api/v1/invitations/$id/respond", ['action' => 'accept'])->assertConflict();
    }

    public function test_expired_course_and_attended_course_block_teacher_changes(): void
    {
        $u = $this->teacher();
        $s = $this->course([$u]);
        $d = $s->data;
        $d['service_date'] = '2020-01-01';
        $s->update(['data' => $d]);
        $a = $s->assignments()->first();
        $this->actingAs($u)->postJson("/api/v1/assignments/$a->id/leave", ['version' => 1, 'reason' => '示範'])->assertConflict();
        $future = $this->course([$u]);
        $a = $future->assignments()->first();
        $a->update(['attendance' => ['present' => true]]);
        $this->putJson('/api/v1/sessions/'.$future->id, ['version' => 1, 'reason' => '示範', 'location' => '新地點'])->assertConflict();
    }

    public function test_invitation_replay_conflicts_and_snapshot_retains_original_assignment(): void
    {
        $u = $this->teacher();
        $t = $this->teacher();
        $s = $this->course([$u]);
        $a = $s->assignments()->first();
        $this->actingAs($u)->postJson("/api/v1/assignments/$a->id/invite", ['version' => 1, 'reason' => '示範', 'teacher_id' => $t->id])->assertOk();
        $id = DB::table('invitations')->value('id');
        $this->actingAs($t)->postJson("/api/v1/invitations/$id/respond", ['action' => 'accept'])->assertOk();
        $this->postJson("/api/v1/invitations/$id/respond", ['action' => 'accept'])->assertConflict();
        $event = Entity::where('type', 'changes')->latest('id')->first();
        $this->assertEquals('assigned', $event->data['before']['assignments'][0]['status']);
        $this->assertEquals('replaced', $event->data['after']['assignments'][0]['status']);
    }

    public function test_product_view_dedup_expires_after_thirty_minutes(): void
    {
        $e = Entity::create(['type' => 'contents', 'data' => ['kind' => 'product', 'title' => '示範', 'body' => '示範', 'status' => 'published', 'views' => 0]]);
        $this->getJson('/api/v1/public/products/'.$e->id)->assertJsonPath('data.views', 1);
        $this->getJson('/api/v1/public/products/'.$e->id)->assertJsonPath('data.views', 1);
        $this->travel(31)->minutes();
        $this->getJson('/api/v1/public/products/'.$e->id)->assertJsonPath('data.views', 2);
    }

    public function test_case_updates_preserve_records_and_meeting_cannot_publish_another_private_file(): void
    {
        $u = $this->teacher();
        $admin = Role::create(['name' => '管理員', 'slug' => 'system-admin', 'permissions' => []]);
        $u->roles()->attach($admin);
        $other = $this->teacher();
        $c = Entity::create(['type' => 'cases', 'owner_id' => $u->id, 'data' => ['code' => 'DEMO', 'name' => '示範', 'status' => '服務中', 'assigned_user_id' => $u->id, 'records' => [['summary' => '原紀錄']]]]);
        $this->actingAs($u)->putJson('/api/v1/cases/'.$c->id, ['code' => 'DEMO', 'name' => '示範更新', 'status' => '服務中', 'assigned_user_id' => $u->id])->assertOk()->assertJsonPath('records.0.summary', '原紀錄');
        $file = Entity::create(['type' => 'files', 'owner_id' => $other->id, 'data' => ['name' => 'private.txt', 'path' => 'private/file', 'visibility' => 'private']]);
        $this->postJson('/api/v1/meetings', ['title' => '示範', 'meeting_date' => '2030-01-01', 'role_ids' => [], 'file_ids' => [$file->id]])->assertForbidden();
    }
}
