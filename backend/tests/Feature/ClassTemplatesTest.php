<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Role;
use App\Models\ServiceSession;
use App\Models\User;
use App\Services\ClassRecurrence;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_unique_occurrence_key_prevents_duplicate_rows(): void
    {
        $this->actingAs($this->account(true));
        $id = $this->postJson('/api/v1/class-templates', $this->body())->json('id');
        $this->postJson("/api/v1/class-templates/$id/generate", ['version' => 1])->assertOk();
        $s = ServiceSession::where('template_id', $id)->first();
        $this->expectException(QueryException::class);
        ServiceSession::create(['data' => $s->data, 'template_id' => $id, 'template_rule_id' => $s->template_rule_id, 'occurrence_date' => $s->occurrence_date]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2028-01-01 00:00:00', 'Asia/Taipei'));
    }

    private function account(bool $admin = false): User
    {
        $role = Role::firstOrCreate(['slug' => $admin ? 'system-admin' : 'teacher'], ['name' => '示範', 'permissions' => $admin ? [] : ['schedule.read.own', 'schedule.update.own']]);
        $u = User::create(['name' => '示範', 'email' => uniqid().'@demo.invalid', 'phone' => '09'.str_pad((string) (User::count() + 1), 8, '0', STR_PAD_LEFT), 'password' => 'Test-Only-Password']);
        $u->roles()->attach($role);

        return $u;
    }

    private function body(array $teachers = []): array
    {
        return ['name' => '示範班別', 'prison' => '示範監所', 'location' => '示範教室', 'participant_count' => 10, 'teacher_ids' => $teachers, 'active' => true, 'start_date' => '2028-01-01', 'end_date' => '2028-03-31', 'rules' => [['id' => 'weekly-a', 'frequency' => 'weekly', 'weekdays' => [1], 'start_time' => '09:00', 'end_time' => '11:00']]];
    }

    public function test_calendar_boundaries_month_end_fifth_week_and_last_week_never_shift(): void
    {
        $base = $this->body();
        $base['rules'] = [['id' => 'month31', 'frequency' => 'monthly_date', 'month_day' => 31, 'start_time' => '09:00', 'end_time' => '11:00'], ['id' => 'fifth', 'frequency' => 'monthly_weekday', 'week_of_month' => 5, 'weekday' => 1, 'start_time' => '12:00', 'end_time' => '13:00'], ['id' => 'last', 'frequency' => 'monthly_weekday', 'week_of_month' => -1, 'weekday' => 1, 'start_time' => '14:00', 'end_time' => '15:00']];
        $data = app(ClassRecurrence::class)->preview($base);
        $dates = collect($data['data']);
        $this->assertEquals(['2028-01-31', '2028-03-31'], $dates->where('rule_id', 'month31')->pluck('service_date')->values()->all());
        $this->assertEquals(['2028-01-31'], $dates->where('rule_id', 'fifth')->pluck('service_date')->values()->all());
        $this->assertEquals(['2028-01-31', '2028-02-28', '2028-03-27'], $dates->where('rule_id', 'last')->pluck('service_date')->values()->all());
        $base['rules'] = [['id' => 'leap', 'frequency' => 'monthly_date', 'month_day' => 29, 'start_time' => '09:00', 'end_time' => '11:00']];
        $this->assertContains('2028-02-29', array_column(app(ClassRecurrence::class)->preview($base)['data'], 'service_date'));
    }

    public function test_today_completed_slots_skipped_and_date_limits_applied(): void
    {
        $this->travelTo(Carbon::parse('2028-01-03 12:00:00', 'Asia/Taipei'));
        $body = $this->body();
        $body['end_date'] = '2028-01-03';
        $body['rules'][] = ['id' => 'afternoon', 'frequency' => 'weekly', 'weekdays' => [1], 'start_time' => '13:00', 'end_time' => '14:00'];
        $preview = app(ClassRecurrence::class)->preview($body);
        $this->assertCount(1, $preview['data']);
        $this->assertEquals('afternoon', $preview['data'][0]['rule_id']);
        $this->assertCount(1, $preview['skipped']);
        $this->assertEquals('2028-01-03', $preview['through']);
        $body['start_date'] = '2028-04-03';
        $body['end_date'] = null;
        $this->assertCount(0, app(ClassRecurrence::class)->preview($body)['data']);
    }

    public function test_generation_replays_and_template_edits_preserve_existing_session_changes(): void
    {
        $admin = $this->account(true);
        $teacher = $this->account();
        $body = $this->body([$teacher->id]);
        $this->actingAs($admin);
        $id = $this->postJson('/api/v1/class-templates', $body)->assertOk()->assertJsonPath('version', 1)->json('id');
        $result = $this->postJson("/api/v1/class-templates/$id/generate", ['version' => 1])->assertOk()->json();
        $this->assertGreaterThan(0, $result['created']);
        $this->postJson("/api/v1/class-templates/$id/generate", ['version' => 1])->assertOk()->assertJsonPath('created', 0)->assertJsonPath('existing', $result['created']);
        $s = ServiceSession::where('template_id', $id)->first();
        $d = $s->data;
        $d['status'] = 'cancelled';
        $d['service_date'] = '2028-06-01';
        $s->update(['data' => $d]);
        $body['location'] = '示範新教室';
        $body['rules'][0]['start_time'] = '10:00';
        $body['version'] = 1;
        $this->putJson('/api/v1/class-templates/'.$id, $body)->assertOk()->assertJsonPath('version', 2);
        $this->postJson("/api/v1/class-templates/$id/generate", ['version' => 1])->assertConflict();
        $this->postJson("/api/v1/class-templates/$id/generate", ['version' => 2])->assertOk()->assertJsonPath('created', 0);
        $this->assertEquals($d, $s->fresh()->data);
        $this->assertDatabaseCount('service_sessions', $result['created']);
        $this->assertTrue(Entity::where('type', 'audit')->get()->contains(fn ($e) => ($e->data['module'] ?? '') === 'class-templates'));
    }

    public function test_teacher_conflicts_and_revoked_teacher_skip_without_partial_assignment(): void
    {
        $admin = $this->account(true);
        $teacher = $this->account();
        $body = $this->body([$teacher->id]);
        $this->actingAs($admin);
        $first = $this->postJson('/api/v1/class-templates', $body)->json('id');
        $this->postJson("/api/v1/class-templates/$first/generate", ['version' => 1])->assertOk();
        $other = $this->postJson('/api/v1/class-templates', $body)->json('id');
        $result = $this->postJson("/api/v1/class-templates/$other/generate", ['version' => 1])->assertOk()->json();
        $this->assertEquals(0, $result['created']);
        $this->assertNotEmpty($result['skipped']);
        $body['rules'][0]['start_time'] = '12:00';
        $body['rules'][0]['end_time'] = '13:00';
        $third = $this->postJson('/api/v1/class-templates', $body)->json('id');
        $teacher->roles()->detach();
        $result = $this->postJson("/api/v1/class-templates/$third/generate", ['version' => 1])->assertOk()->json();
        $this->assertEquals(0, $result['created']);
        $this->assertStringContainsString('教師權限', $result['skipped'][0]['reason']);
    }

    public function test_empty_teacher_template_generates_vacancy_and_admin_can_assign_once(): void
    {
        $admin = $this->account(true);
        $teacher = $this->account();
        $this->actingAs($admin);
        $id = $this->postJson('/api/v1/class-templates', $this->body())->json('id');
        $this->postJson("/api/v1/class-templates/$id/generate", ['version' => 1])->assertOk();
        $s = ServiceSession::where('template_id', $id)->first();
        $this->assertEquals(1, $s->data['original_teacher_count']);
        $this->assertEquals(0, $s->assignments()->count());
        $this->postJson("/api/v1/sessions/$s->id/assign", ['version' => 1, 'teacher_id' => $teacher->id, 'reason' => '補齊示範教師'])->assertOk()->assertJsonPath('version', 2);
        $this->postJson("/api/v1/sessions/$s->id/assign", ['version' => 2, 'teacher_id' => $teacher->id, 'reason' => '重複'])->assertConflict();
    }

    public function test_teacher_cannot_manage_templates_and_invalid_rules_or_stale_versions_rejected(): void
    {
        $this->actingAs($this->account())->getJson('/api/v1/class-templates')->assertForbidden();
        $this->postJson('/api/v1/class-templates/preview', $this->body())->assertForbidden();
        $this->actingAs($this->account(true));
        $body = $this->body();
        $body['rules'][0]['weekdays'] = [8];
        $this->postJson('/api/v1/class-templates', $body)->assertUnprocessable();
        $body = $this->body();
        $body['end_date'] = '2027-01-01';
        $this->postJson('/api/v1/class-templates', $body)->assertUnprocessable();
        $body = $this->body();
        $id = $this->postJson('/api/v1/class-templates', $body)->json('id');
        $body['version'] = 2;
        $this->putJson('/api/v1/class-templates/'.$id, $body)->assertConflict();
    }

    public function test_daily_command_replays_and_inactive_template_stops_future_generation(): void
    {
        $this->actingAs($this->account(true));
        $body = $this->body();
        $body['end_date'] = null;
        $id = $this->postJson('/api/v1/class-templates', $body)->json('id');
        $this->artisan('classes:generate')->assertSuccessful();
        $count = ServiceSession::count();
        $this->artisan('classes:generate')->assertSuccessful();
        $this->assertDatabaseCount('service_sessions', $count);
        $body['active'] = false;
        $body['version'] = 1;
        $this->putJson('/api/v1/class-templates/'.$id, $body)->assertOk();
        $this->travel(7)->days();
        $this->artisan('classes:generate')->assertSuccessful();
        $this->assertDatabaseCount('service_sessions', $count);
    }

    public function test_rolling_window_adds_future_occurrences_and_mock_line_is_recorded(): void
    {
        $admin = $this->account(true);
        Entity::create(['type' => 'line', 'owner_id' => $admin->id, 'data' => ['bound' => true, 'subscribed' => true, 'mode' => 'mock']]);
        $this->actingAs($admin);
        $body = $this->body();
        $body['end_date'] = null;
        $id = $this->postJson('/api/v1/class-templates', $body)->assertOk()->json('id');
        $first = $this->postJson("/api/v1/class-templates/$id/generate", ['version' => 1])->assertOk()->json('created');
        $this->assertTrue(Entity::where('type', 'line-outbox')->exists());
        $this->travel(7)->days();
        $this->artisan('classes:generate')->assertSuccessful();
        $this->assertDatabaseCount('service_sessions', $first + 1);
    }

    public function test_invalid_teacher_is_rejected_when_saving_and_vacancy_requires_valid_future_version(): void
    {
        $admin = $this->account(true);
        $teacher = $this->account();
        $teacher->update(['active' => false]);
        $this->actingAs($admin)->postJson('/api/v1/class-templates', $this->body([$teacher->id]))->assertUnprocessable();
        $teacher->update(['active' => true]);
        $body = $this->body();
        $id = $this->postJson('/api/v1/class-templates', $body)->json('id');
        $this->postJson("/api/v1/class-templates/$id/generate", ['version' => 1])->assertOk();
        $s = ServiceSession::where('template_id', $id)->first();
        $payload = ['version' => 2, 'teacher_id' => $teacher->id, 'reason' => '示範'];
        $this->postJson("/api/v1/sessions/$s->id/assign", $payload)->assertConflict();
        $payload['version'] = 1;
        $this->actingAs($teacher)->postJson("/api/v1/sessions/$s->id/assign", $payload)->assertForbidden();
        $this->actingAs($admin);
        $d = $s->data;
        $d['status'] = 'cancelled';
        $s->update(['data' => $d]);
        $this->postJson("/api/v1/sessions/$s->id/assign", $payload)->assertConflict();
        $d['status'] = 'scheduled';
        $d['service_date'] = '2027-01-01';
        $s->update(['data' => $d]);
        $this->postJson("/api/v1/sessions/$s->id/assign", $payload)->assertConflict();
    }

    public function test_vacancy_conflict_requires_explicit_admin_override(): void
    {
        $admin = $this->account(true);
        $teacher = $this->account();
        $this->actingAs($admin);
        $id = $this->postJson('/api/v1/class-templates', $this->body())->json('id');
        $this->postJson("/api/v1/class-templates/$id/generate", ['version' => 1])->assertOk();
        $s = ServiceSession::where('template_id', $id)->first();
        $other = ServiceSession::create(['data' => $s->data]);
        $other->assignments()->create(['teacher_id' => $teacher->id]);
        $payload = ['version' => 1, 'teacher_id' => $teacher->id, 'reason' => '覆核示範衝突'];
        $this->postJson("/api/v1/sessions/$s->id/assign", $payload)->assertConflict();
        $payload['override_conflict'] = true;
        $this->postJson("/api/v1/sessions/$s->id/assign", $payload)->assertOk();
    }
}
