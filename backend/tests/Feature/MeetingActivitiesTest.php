<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingActivitiesTest extends TestCase
{
    use RefreshDatabase;

    private function person(array $permissions): User
    {
        $role = Role::create(['name' => '測試角色', 'slug' => uniqid('activity-'), 'permissions' => $permissions]);
        $u = User::create(['name' => '活動測試人員', 'phone' => '09'.str_pad((string) (User::count() + 1), 8, '0', STR_PAD_LEFT), 'email' => uniqid().'@example.test', 'password' => 'Test-only-password']);
        $u->roles()->attach($role);

        return $u;
    }

    public function test_activity_calendar_fields_persist_and_visibility_is_enforced(): void
    {
        $manager = $this->person(['meetings.create.all', 'meetings.read.all', 'meetings.update.all']);
        $viewer = $this->person(['meetings.read.own']);
        $outsider = $this->person(['meetings.read.own']);
        $body = ['title' => '志工培訓', 'kind' => 'activity', 'activity_type' => '培訓', 'meeting_date' => '2030-01-01', 'start_time' => '09:00', 'end_time' => '11:00', 'location' => '第一會議室', 'show_on_calendar' => true, 'color' => '#326e9c', 'agenda' => '合成活動內容', 'role_ids' => [$viewer->roles()->first()->id]];
        $id = $this->actingAs($manager)->postJson('/api/v1/meetings', $body)->assertOk()->json('id');
        $this->actingAs($viewer)->getJson('/api/v1/meetings')->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'activity')->assertJsonPath('data.0.show_on_calendar', true)->assertJsonPath('data.0.location', '第一會議室')->assertJsonPath('data.0.color', '#326e9c');
        $this->postJson('/api/v1/meetings', $body)->assertForbidden();
        $this->actingAs($outsider)->getJson('/api/v1/meetings')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/meetings/'.$id)->assertForbidden();
        $this->actingAs($manager)->putJson('/api/v1/meetings/'.$id, ['title' => '更新培訓', 'meeting_date' => '2030-01-02', 'show_on_calendar' => false])->assertOk()->assertJsonPath('kind', 'activity')->assertJsonPath('start_time', '09:00')->assertJsonPath('show_on_calendar', false);
        $this->actingAs($viewer)->getJson('/api/v1/meetings/'.$id)->assertOk()->assertJsonPath('meeting_date', '2030-01-02');
    }

    public function test_all_day_events_and_invalid_time_or_color(): void
    {
        $this->actingAs($this->person(['meetings.create.all', 'meetings.read.all']));
        $body = ['title' => '全天聚會', 'kind' => 'activity', 'meeting_date' => '2030-01-01', 'show_on_calendar' => true];
        $this->postJson('/api/v1/meetings', $body)->assertOk()->assertJsonPath('start_time', null)->assertJsonPath('end_time', null);
        $this->postJson('/api/v1/meetings', $body + ['start_time' => '11:00', 'end_time' => '09:00'])->assertUnprocessable();
        $this->postJson('/api/v1/meetings', $body + ['start_time' => '09:00'])->assertUnprocessable();
        $this->postJson('/api/v1/meetings', $body + ['color' => 'red'])->assertUnprocessable();
        $this->postJson('/api/v1/meetings', ['title' => '既有會議', 'meeting_date' => '2030-01-01'])->assertOk()->assertJsonPath('kind', 'meeting')->assertJsonPath('show_on_calendar', false);
    }
}
