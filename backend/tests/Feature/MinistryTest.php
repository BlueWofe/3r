<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Role;
use App\Models\ServiceSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MinistryTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $permissions = [], string $slug = 'teacher'): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug, 'permissions' => $permissions]);
        $u = User::create(['phone' => '09'.str_pad((string) (User::count() + 1), 8, '0', STR_PAD_LEFT), 'name' => '示範', 'email' => uniqid().'@demo.invalid', 'password' => 'Temporary-Test-Only']);
        $u->roles()->attach($role);

        return $u;
    }

    private function admin(): User
    {
        return $this->account([], 'system-admin');
    }

    private function teacher(): User
    {
        return $this->account(['schedule.read.own', 'schedule.update.own', 'attendance.create.own', 'reports.read.own']);
    }

    private function makeSession(User $u, string $date = '2030-01-01'): ServiceSession
    {
        $s = ServiceSession::create(['data' => ['title' => '示範', 'prison' => '示範', 'location' => '示範', 'participant_count' => 1, 'service_date' => $date, 'start_time' => '09:00', 'end_time' => '11:00', 'status' => 'scheduled']]);
        $s->assignments()->create(['teacher_id' => $u->id]);

        return $s;
    }

    public function test_permissions_union_and_inactive_roles(): void
    {
        $u = $this->teacher();
        $role = Role::create(['name' => '編輯', 'slug' => 'editor', 'permissions' => ['content.read.all'], 'active' => true]);
        $u->roles()->attach($role);
        $this->actingAs($u)->getJson('/api/v1/auth/me')->assertOk()->assertJsonFragment(['content.read.all']);
        $role->update(['active' => false]);
        $this->actingAs($u->fresh())->getJson('/api/v1/auth/me')->assertJsonMissing(['content.read.all']);
    }

    public function test_teacher_cannot_read_other_session_or_create(): void
    {
        $u = $this->teacher();
        $s = $this->makeSession($this->teacher());
        $this->actingAs($u)->getJson('/api/v1/sessions/'.$s->id)->assertForbidden();
        $this->postJson('/api/v1/sessions', [])->assertForbidden();
        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_leave_version_withdraw_and_owner_checks(): void
    {
        $u = $this->teacher();
        $s = $this->makeSession($u);
        $a = $s->assignments()->first();
        $this->actingAs($u)->postJson("/api/v1/assignments/$a->id/leave", ['version' => 1, 'reason' => '示範'])->assertOk()->assertJsonPath('version', 2);
        $this->postJson("/api/v1/assignments/$a->id/withdraw-leave", ['version' => 1, 'reason' => '示範'])->assertConflict();
        $this->postJson("/api/v1/assignments/$a->id/withdraw-leave", ['version' => 2, 'reason' => '示範'])->assertOk()->assertJsonPath('assignments.0.status', 'assigned');
        $this->actingAs($this->teacher())->postJson("/api/v1/assignments/$a->id/leave", ['version' => 3, 'reason' => '示範'])->assertForbidden();
    }

    public function test_only_one_invitation_accepts_and_other_is_cancelled(): void
    {
        $u = $this->teacher();
        $t = $this->teacher();
        $other = $this->teacher();
        $s = $this->makeSession($u);
        $a = $s->assignments()->first();
        $this->actingAs($u)->postJson("/api/v1/assignments/$a->id/leave", ['version' => 1, 'reason' => '示範'])->assertOk();
        $this->postJson("/api/v1/assignments/$a->id/invite", ['version' => 2, 'reason' => '示範', 'teacher_id' => $t->id])->assertOk();
        $this->postJson("/api/v1/assignments/$a->id/invite", ['version' => 3, 'reason' => '示範', 'teacher_id' => $other->id])->assertConflict();
        $ids = DB::table('invitations')->pluck('id');
        $this->actingAs($t)->postJson('/api/v1/invitations/'.$ids[0].'/respond', ['action' => 'accept'])->assertOk();
        $this->actingAs($other)->postJson('/api/v1/invitations/'.$ids[0].'/respond', ['action' => 'accept'])->assertForbidden();
        $this->assertDatabaseHas('assignments', ['id' => $a->id, 'status' => 'replaced']);
        $this->assertDatabaseHas('assignments', ['session_id' => $s->id, 'teacher_id' => $t->id, 'status' => 'assigned']);
    }

    public function test_overlap_blocks_creation_and_repeat_is_atomic(): void
    {
        $admin = $this->admin();
        $u = $this->teacher();
        $this->makeSession($u);
        $body = ['title' => '示範', 'prison' => '示範', 'location' => '示範', 'participant_count' => 1, 'service_date' => '2029-12-25', 'start_time' => '10:00', 'end_time' => '12:00', 'teacher_ids' => [$u->id], 'repeat_weeks' => 2];
        $this->actingAs($admin)->postJson('/api/v1/sessions', $body)->assertConflict();
        $this->assertDatabaseCount('service_sessions', 1);
    }

    public function test_attendance_only_whole_taiwan_day_and_no_duplicate(): void
    {
        $u = $this->teacher();
        $s = $this->makeSession($u, now('Asia/Taipei')->format('Y-m-d'));
        $a = $s->assignments()->first();
        $this->actingAs($u)->postJson("/api/v1/assignments/$a->id/attendance")->assertOk();
        $this->postJson("/api/v1/assignments/$a->id/attendance")->assertConflict();
        $s2 = $this->makeSession($u, '2030-01-01');
        $a2 = $s2->assignments()->first();
        $this->postJson("/api/v1/assignments/$a2->id/attendance")->assertUnprocessable();
    }

    public function test_attendance_requires_explicit_void_for_reschedule(): void
    {
        $admin = $this->admin();
        $u = $this->teacher();
        $s = $this->makeSession($u);
        $a = $s->assignments()->first();
        $a->update(['attendance' => ['present' => true]]);
        $this->actingAs($admin)->putJson('/api/v1/sessions/'.$s->id, ['version' => 1, 'reason' => '示範', 'service_date' => '2030-01-02'])->assertConflict();
        $this->putJson('/api/v1/sessions/'.$s->id, ['version' => 1, 'reason' => '示範', 'service_date' => '2030-01-02', 'attendance_resolution' => 'void'])->assertOk();
        $this->assertNull($a->fresh()->attendance);
    }

    public function test_last_admin_cannot_be_disabled_or_role_deactivated(): void
    {
        $u = $this->admin();
        $role = $u->roles->first();
        $this->actingAs($u)->putJson('/api/v1/users/'.$u->id, ['name' => '示範', 'active' => false, 'role_ids' => [$role->id]])->assertConflict();
        $this->assertTrue($u->fresh()->active);
        $this->putJson('/api/v1/roles/'.$role->id, ['name' => '管理員', 'slug' => 'system-admin', 'active' => false, 'permissions' => []])->assertConflict();
        $this->assertTrue($role->fresh()->active);
    }

    public function test_private_files_forbidden_even_when_id_known(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('private/test', 'private');
        $owner = $this->teacher();
        $file = Entity::create(['type' => 'files', 'owner_id' => $owner->id, 'data' => ['path' => 'private/test', 'name' => 'test.txt', 'visibility' => 'private']]);
        $this->getJson('/api/v1/files/'.$file->id.'/download')->assertForbidden();
        $this->actingAs($this->teacher())->get('/api/v1/files/'.$file->id.'/download')->assertForbidden();
        $this->actingAs($owner)->get('/api/v1/files/'.$file->id.'/download')->assertOk();
    }

    public function test_case_assignment_scope_and_record_authorization(): void
    {
        $u = $this->account(['cases.read.assigned', 'cases.update.assigned'], 'caseworker');
        $other = $this->account([], 'member');
        $e = Entity::create(['type' => 'cases', 'data' => ['assigned_user_id' => $u->id]]);
        $this->actingAs($u)->getJson('/api/v1/cases/'.$e->id)->assertOk();
        $this->actingAs($other)->postJson('/api/v1/cases/'.$e->id.'/records', ['service_date' => '2030-01-01', 'type' => '示範', 'summary' => '示範'])->assertForbidden();
    }

    public function test_form_versions_and_required_answers(): void
    {
        $admin = $this->admin();
        $body = ['title' => '示範', 'status' => 'published', 'role_ids' => [], 'fields' => [['key' => 'name', 'label' => '示範', 'type' => 'text', 'required' => true]]];
        $id = $this->actingAs($admin)->postJson('/api/v1/forms', $body)->assertOk()->json('id');
        $u = $this->account(['forms.read.own'], 'member');
        $this->actingAs($u)->postJson("/api/v1/forms/$id/responses", ['answers' => []])->assertUnprocessable();
        $this->postJson("/api/v1/forms/$id/responses", ['answers' => ['name' => '示範']])->assertOk()->assertJsonPath('version', 1);
        $this->actingAs($admin)->putJson("/api/v1/forms/$id", $body)->assertOk()->assertJsonPath('version', 2);
        $this->assertCount(2, Entity::find($id)->data['snapshots']);
    }

    public function test_mock_payment_idempotency_and_scope(): void
    {
        $u = $this->account(['donations.read.own'], 'member');
        $id = $this->actingAs($u)->postJson('/api/v1/donations', ['amount' => 100, 'purpose' => '示範'])->assertOk()->json('id');
        $this->postJson("/api/v1/donations/$id/simulate", ['result' => 'success'])->assertOk()->assertJsonPath('status', 'success');
        $this->postJson("/api/v1/donations/$id/simulate", ['result' => 'failed'])->assertOk()->assertJsonPath('status', 'success');
        $this->actingAs($this->teacher())->postJson("/api/v1/donations/$id/simulate", ['result' => 'success'])->assertForbidden();
        $this->assertDatabaseCount('entities', 1);
    }

    public function test_public_drafts_hidden_and_markup_removed(): void
    {
        $admin = $this->admin();
        $body = ['kind' => 'product', 'title' => '示範', 'slug' => 'example', 'body' => '<script>alert(1)</script><b>示範</b>', 'status' => 'draft', 'metadata' => ['unit' => '盒', 'currency' => 'TWD', 'gallery_ids' => [], 'spec_axes' => [], 'variants' => [['id' => 'default', 'sku' => 'DEMO', 'options' => [], 'price' => 0, 'stock' => 0, 'active' => true, 'wholesale' => []]]]];
        $id = $this->actingAs($admin)->postJson('/api/v1/contents', $body)->assertOk()->json('id');
        $this->getJson('/api/v1/public/products/'.$id)->assertNotFound();
        $body['status'] = 'published';
        $this->putJson('/api/v1/contents/'.$id, $body)->assertOk();
        $this->getJson('/api/v1/public/products/'.$id)->assertOk()->assertJsonPath('data.body', 'alert(1)示範');
    }

    public function test_mock_otp_private_mailbox_allowlist_and_replay_block(): void
    {
        Storage::fake('local');
        config(['ministry.otp_test_phones' => '0912345678', 'ministry.sms_driver' => 'mock']);
        $this->postJson('/api/v1/auth/otp', ['phone' => '0987654321', 'purpose' => 'register'])->assertUnprocessable();
        $response = $this->postJson('/api/v1/auth/otp', ['phone' => '0912345678', 'purpose' => 'register'])->assertOk();
        $this->assertArrayNotHasKey('code', $response->json());
        $code = json_decode(Storage::disk('local')->get('otp-mailbox/0912345678.json'), true)['code'];
        $body = ['phone' => '0912345678', 'name' => '示範', 'password' => 'Temporary-Test-Only', 'password_confirmation' => 'Temporary-Test-Only', 'code' => $code];
        $this->postJson('/api/v1/auth/register', $body)->assertOk();
        $this->postJson('/api/v1/auth/register', $body)->assertUnprocessable();
    }
}
