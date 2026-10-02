<?php

namespace Tests\Feature;

use App\Models\Prison;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduleReadPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_month_read_has_bounded_queries_and_preserves_scopes_and_complete_payload(): void
    {
        $role = Role::create(['name' => '教師', 'slug' => 'teacher', 'permissions' => ['schedule.read.own'], 'active' => true]);
        $teacher = User::create(['name' => '我的教師', 'email' => 'teacher@example.test', 'phone' => '0912345678', 'password' => 'Test-only-password']);
        $teacher->roles()->attach($role);
        $other = User::create(['name' => '其他教師', 'email' => 'other@example.test', 'phone' => '0987654321', 'password' => 'Test-only-password']);
        $other->roles()->attach($role);
        $prison = Prison::create(['name' => '最新監所名稱']);
        $sessions = [];
        $assignments = [];
        $events = [];
        $invitations = [];
        for ($id = 1; $id <= 400; $id++) {
            // Forty-three visible October rows, plus one unrelated October row.
            $date = $id <= 44 ? '2026-10-01' : '2027-10-01';
            $sessions[] = ['id' => $id, 'prison_id' => $prison->id, 'version' => 1, 'data' => json_encode(['title' => '合成場次'.$id, 'prison' => '舊監所名稱', 'prison_id' => $prison->id, 'service_date' => $date, 'start_time' => '09:00', 'end_time' => '10:00', 'location' => '教室', 'status' => 'scheduled', 'participant_count' => 1])];
            $assignments[] = ['id' => $id, 'session_id' => $id, 'teacher_id' => $id <= 43 ? $teacher->id : $other->id, 'status' => 'assigned', 'attendance' => $id === 1 ? json_encode(['present' => true]) : null];
            $events[] = ['type' => 'changes', 'owner_id' => $id <= 43 ? $teacher->id : $other->id, 'data' => json_encode(['session_id' => $id, 'action' => '合成異動'.$id])];
            $invitations[] = ['assignment_id' => $id, 'teacher_id' => $other->id, 'status' => 'pending'];
        }
        DB::table('service_sessions')->insert($sessions);
        DB::table('assignments')->insert($assignments);
        DB::table('entities')->insert($events);
        DB::table('invitations')->insert($invitations);
        $this->actingAs($teacher);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->getJson('/api/v1/sessions?from=2026-09-27&to=2026-11-07');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $response->assertOk()->assertJsonCount(43, 'data')->assertJsonPath('data.0.prison', '最新監所名稱')->assertJsonPath('data.0.assignments.0.teacher.name', '我的教師')->assertJsonPath('data.0.assignments.0.attendance.present', true)->assertJsonPath('data.0.invitations.0.assignment_id', 1)->assertJsonPath('data.0.events.0.action', '合成異動1');
        $response->assertJsonMissing(['action' => '合成異動44'])->assertJsonMissing(['email' => 'teacher@example.test']);
        $this->assertLessThanOrEqual(10, count($queries), 'Calendar reads must batch related records rather than issue queries per session.');
        $this->getJson('/api/v1/sessions?from=2026-09-27&to=2026-11-07&teacher_id='.$other->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/sessions?from=2026-09-27&to=2026-11-07&prison='.urlencode('最新監所'))->assertJsonCount(43, 'data');
        $this->getJson('/api/v1/sessions?from=2026-09-27&to=2026-11-07&q='.urlencode('舊監所'))->assertJsonCount(0, 'data');
        $adminRole = Role::create(['name' => '系統管理員', 'slug' => 'system-admin', 'permissions' => [], 'active' => true]);
        $other->roles()->attach($adminRole);
        $this->actingAs($other->fresh())->getJson('/api/v1/sessions?from=2026-09-27&to=2026-11-07')->assertJsonCount(44, 'data');
        $role->update(['active' => false]);
        $this->actingAs($teacher->fresh())->getJson('/api/v1/sessions?from=2026-09-27&to=2026-11-07')->assertForbidden();
    }
}
