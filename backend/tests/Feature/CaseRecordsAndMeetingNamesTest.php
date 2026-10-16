<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CaseRecordsAndMeetingNamesTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $permissions): User
    {
        $u = User::create(['name' => '合成同工', 'phone' => '09'.str_pad((string) User::count(), 8, '0', STR_PAD_LEFT), 'email' => uniqid().'@example.test', 'password' => 'test-password']);
        $u->roles()->attach(Role::create(['name' => '服務同工', 'slug' => uniqid(), 'active' => true, 'permissions' => $permissions]));

        return $u;
    }

    public function test_appended_records_survive_fresh_reads_case_edits_and_later_appends(): void
    {
        $actor = $this->account(['cases.create.all', 'cases.read.assigned', 'cases.update.assigned']);
        $this->actingAs($actor);
        $body = ['code' => 'SYNTHETIC', 'name' => '合成個案', 'status' => '服務中'];
        $id = $this->postJson('/api/v1/cases', $body)->assertOk()->json('id');
        $record = ['service_date' => '2026-09-30', 'type' => '關懷', 'summary' => '第一筆合成服務', 'follow_up' => '下次追蹤'];
        $first = $this->postJson('/api/v1/cases/'.$id.'/records', $record)->assertOk()->assertJsonPath('records.0.author_name', $actor->name)->json('records.0');
        $this->assertTrue(Str::isUuid($first['id']));
        $this->assertSame($first['summary'], Entity::findOrFail($id)->data['records'][0]['summary']);
        $this->getJson('/api/v1/cases/'.$id)->assertOk()->assertJsonPath('records.0.id', $first['id'])->assertJsonPath('records.0.follow_up', '下次追蹤');
        $this->putJson('/api/v1/cases/'.$id, $body + ['contact' => '已更新聯絡資料', 'records' => []])->assertOk()->assertJsonCount(1, 'records');
        $this->postJson('/api/v1/cases/'.$id.'/records', array_replace($record, ['summary' => '第二筆合成服務']))->assertOk()->assertJsonCount(2, 'records');
        $this->getJson('/api/v1/cases')->assertJsonPath('data.0.records.0.id', $first['id'])->assertJsonPath('data.0.records.1.summary', '第二筆合成服務');
        $this->assertCount(2, Entity::findOrFail($id)->data['records']);
        $outsider = $this->account(['cases.update.assigned', 'cases.read.assigned']);
        $this->actingAs($outsider)->postJson('/api/v1/cases/'.$id.'/records', $record)->assertForbidden();
        $this->assertCount(2, Entity::findOrFail($id)->data['records']);
    }

    public function test_meeting_reader_receives_role_names_without_role_management_access(): void
    {
        $reader = $this->account(['meetings.read.own']);
        $role = $reader->roles()->first();
        $other = Role::create(['name' => '合成小組長', 'slug' => uniqid(), 'active' => false, 'permissions' => ['roles.manage.all']]);
        $meeting = Entity::create(['type' => 'meetings', 'data' => ['title' => '合成會議', 'meeting_date' => '2026-09-30', 'role_ids' => [$other->id, $role->id, $role->id, 999999]]]);
        $this->actingAs($reader)->getJson('/api/v1/roles')->assertForbidden();
        $names = [$other->name, $role->name];
        $this->getJson('/api/v1/meetings')->assertOk()->assertJsonPath('data.0.role_names', $names)->assertJsonPath('data.0.role_ids', [$other->id, $role->id, $role->id, 999999]);
        $this->getJson('/api/v1/meetings/'.$meeting->id)->assertOk()->assertJsonPath('role_names', $names)->assertJsonMissingPath('permissions')->assertJsonMissingPath('members');
        $outsider = $this->account(['meetings.read.own']);
        $this->actingAs($outsider)->getJson('/api/v1/meetings')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/meetings/'.$meeting->id)->assertForbidden();
    }
}
