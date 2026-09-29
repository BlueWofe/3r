<?php

namespace Tests\Feature;

use App\Models\ClassTemplate;
use App\Models\Entity;
use App\Models\Prison;
use App\Models\Role;
use App\Models\ServiceSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrisonDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $permissions = [], bool $admin = false): User
    {
        $u = User::create(['name' => '承辦人'.User::count(), 'phone' => '09'.str_pad((string) (User::count() + 1), 8, '0', STR_PAD_LEFT), 'email' => uniqid().'@example.test', 'password' => 'Test-only-password']);
        $u->roles()->attach(Role::create(['name' => '測試角色', 'slug' => $admin ? 'system-admin' : uniqid('role'), 'active' => true, 'permissions' => $permissions]));

        return $u;
    }

    private function caseBody(array $extra = []): array
    {
        return array_merge(['code' => 'TEST-1', 'name' => '合成個案', 'status' => '服务中'], $extra);
    }

    private function sessionBody(int $teacher, array $extra = []): array
    {
        return array_merge(['title' => '示範場次', 'prison' => '相容監所', 'location' => '教室', 'participant_count' => 1, 'service_date' => '2035-01-01', 'start_time' => '09:00', 'end_time' => '10:00', 'teacher_ids' => [$teacher]], $extra);
    }

    public function test_prison_management_permissions_version_uniqueness_and_options_privacy(): void
    {
        $member = $this->account();
        $this->getJson('/api/v1/prisons/options')->assertUnauthorized();
        $this->actingAs($member)->getJson('/api/v1/prisons/options')->assertForbidden();
        $this->postJson('/api/v1/prisons', ['name' => '禁止建立'])->assertForbidden();
        $manager = $this->account(['prisons.manage.all']);
        $this->actingAs($manager);
        $id = $this->postJson('/api/v1/prisons', ['name' => '測試監所', 'address' => '私有地址'])->assertOk()->assertJsonPath('active', true)->assertJsonPath('version', 1)->json('id');
        $this->postJson('/api/v1/prisons', ['name' => '測試監所'])->assertUnprocessable();
        $this->putJson('/api/v1/prisons/'.$id, ['name' => '更新名稱', 'active' => false, 'version' => 1])->assertOk()->assertJsonPath('version', 2);
        $this->putJson('/api/v1/prisons/'.$id, ['name' => '衝突名稱', 'active' => true, 'version' => 1])->assertConflict();
        $teacher = $this->account(['schedule.read.own']);
        $this->actingAs($teacher)->getJson('/api/v1/prisons/options')->assertOk()->assertJsonPath('data.0.name', '更新名稱')->assertJsonPath('data.0.active', false)->assertJsonMissing(['address' => '私有地址']);
        $this->getJson('/api/v1/prisons')->assertForbidden();
    }

    public function test_scoped_case_creator_can_see_new_case_and_author_names_without_users_permission(): void
    {
        $owner = $this->account(['cases.create.all', 'cases.read.assigned', 'cases.update.assigned']);
        $other = $this->account(['cases.read.assigned']);
        $this->actingAs($owner);
        $id = $this->postJson('/api/v1/cases', $this->caseBody(['assigned_user_id' => null, 'prison' => '新監所']))->assertOk()->assertJsonPath('assigned_user_id', $owner->id)->assertJsonPath('assigned_user_name', $owner->name)->json('id');
        $this->getJson('/api/v1/cases')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/cases/'.$id.'/records', ['service_date' => '2026-09-29', 'type' => '陪伴', 'summary' => '合成紀錄'])->assertOk()->assertJsonPath('records.0.author_name', $owner->name)->assertJsonPath('records.0.author_id', $owner->id);
        $this->putJson('/api/v1/cases/'.$id, $this->caseBody())->assertOk()->assertJsonPath('assigned_user_id', $owner->id)->assertJsonCount(1, 'records');
        $this->actingAs($other)->getJson('/api/v1/cases/'.$id)->assertForbidden();
        $this->getJson('/api/v1/cases')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/cases/'.$id.'/records', ['service_date' => '2026-09-29', 'type' => '陪伴', 'summary' => '越權'])->assertForbidden();
    }

    public function test_ids_and_renames_sync_cases_classes_generated_sessions_and_filters(): void
    {
        $admin = $this->account([], true);
        $prison = Prison::create(['name' => '原監所']);
        $this->actingAs($admin);
        $caseId = $this->postJson('/api/v1/cases', $this->caseBody(['prison_id' => $prison->id]))->assertOk()->assertJsonPath('prison', '原監所')->json('id');
        $templateBody = ['name' => '班別', 'prison_id' => $prison->id, 'location' => '教室', 'participant_count' => 1, 'teacher_ids' => [], 'active' => true, 'start_date' => '2030-01-01', 'end_date' => '2030-02-01', 'rules' => [['id' => 'r1', 'frequency' => 'weekly', 'weekdays' => [1], 'start_time' => '09:00', 'end_time' => '10:00']]];
        $this->travelTo(now('Asia/Taipei')->setDate(2030, 1, 1)->startOfDay());
        $templateId = $this->postJson('/api/v1/class-templates', $templateBody)->assertOk()->assertJsonPath('prison_id', $prison->id)->json('id');
        $this->postJson('/api/v1/class-templates/'.$templateId.'/generate', ['version' => 1])->assertOk();
        $s = ServiceSession::firstOrFail();
        $this->assertSame($prison->id, $s->prison_id);
        $this->putJson('/api/v1/prisons/'.$prison->id, ['name' => '新監所名稱', 'active' => true, 'version' => 1])->assertOk();
        $this->getJson('/api/v1/cases/'.$caseId)->assertJsonPath('prison', '新監所名稱');
        $this->getJson('/api/v1/class-templates/'.$templateId)->assertJsonPath('prison', '新監所名稱');
        $this->getJson('/api/v1/sessions/'.$s->id)->assertJsonPath('prison', '新監所名稱');
        $this->getJson('/api/v1/sessions?prison_id='.$prison->id)->assertJsonPath('data.0.prison', '新監所名稱');
        $this->getJson('/api/v1/sessions?prison_id=999')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/sessions?prison=新監所')->assertJsonPath('data.0.prison', '新監所名稱');
        $this->putJson('/api/v1/cases/'.$caseId, $this->caseBody(['prison' => '原監所']))->assertOk()->assertJsonPath('prison_id', $prison->id)->assertJsonPath('prison', '新監所名稱');
        unset($templateBody['prison_id']);
        $templateBody['prison'] = '原監所';
        $this->putJson('/api/v1/class-templates/'.$templateId, $templateBody + ['version' => 1])->assertOk()->assertJsonPath('prison_id', $prison->id);
        $this->assertSame(1, Prison::count());
        $prison->update(['active' => false]);
        unset($templateBody['prison']);
        $templateBody['prison_id'] = $prison->id;
        $templateBody['end_date'] = '2030-03-01';
        $this->putJson('/api/v1/class-templates/'.$templateId, $templateBody + ['version' => 2])->assertOk();
        $result = $this->postJson('/api/v1/class-templates/'.$templateId.'/generate', ['version' => 3])->assertOk()->assertJsonPath('created', 0)->json();
        $this->assertNotEmpty($result['skipped']);
        $this->postJson('/api/v1/class-templates', $templateBody)->assertUnprocessable()->assertJsonValidationErrors('prison_id');
        $this->travelBack();
    }

    public function test_inactive_prison_retained_but_not_newly_assigned_and_teacher_cannot_change_identity(): void
    {
        $admin = $this->account([], true);
        $teacher = $this->account(['schedule.read.own', 'schedule.update.own']);
        $prison = Prison::create(['name' => '歷史監所']);
        $other = Prison::create(['name' => '另一監所']);
        $this->actingAs($admin);
        $body = $this->sessionBody($teacher->id, ['prison_id' => $prison->id]);
        unset($body['prison']);
        $id = $this->postJson('/api/v1/sessions', $body)->assertOk()->json('id');
        $caseId = $this->postJson('/api/v1/cases', $this->caseBody(['prison_id' => $prison->id]))->json('id');
        $prison->update(['active' => false]);
        $this->postJson('/api/v1/sessions', $body)->assertUnprocessable()->assertJsonValidationErrors('prison_id');
        $this->postJson('/api/v1/cases', $this->caseBody(['prison_id' => $prison->id]))->assertUnprocessable();
        $this->putJson('/api/v1/cases/'.$caseId, $this->caseBody(['prison_id' => $prison->id]))->assertOk();
        $this->actingAs($teacher)->putJson('/api/v1/sessions/'.$id, ['version' => 1, 'reason' => '換教室', 'location' => '教室二', 'prison_id' => $prison->id])->assertOk()->assertJsonPath('prison_id', $prison->id);
        $this->putJson('/api/v1/sessions/'.$id, ['version' => 2, 'reason' => '越權', 'prison_id' => $other->id])->assertForbidden();
        $this->putJson('/api/v1/sessions/'.$id, ['version' => 2, 'reason' => '越權', 'prison' => '自行建立新監所'])->assertForbidden();
        $this->assertDatabaseMissing('prisons', ['name' => '自行建立新監所']);
    }

    public function test_backfill_deduplicates_trimmed_names_and_preserves_history(): void
    {
        $migration = require database_path('migrations/2026_10_02_000001_create_prisons_directory.php');
        $migration->down();
        $original = ['prison' => '  原有監所  ', 'service_date' => '2000-01-01', 'status' => 'cancelled', 'body' => '舊內容'];
        $sessionId = DB::table('service_sessions')->insertGetId(['data' => json_encode($original), 'version' => 7, 'created_at' => '2000-01-01', 'updated_at' => '2000-01-01']);
        $caseId = DB::table('entities')->insertGetId(['type' => 'cases', 'data' => json_encode(['prison' => '原有監所', 'records' => [['summary' => '保留紀錄']]]), 'created_at' => '2000-01-01', 'updated_at' => '2000-01-01']);
        DB::table('class_templates')->insert(['data' => json_encode(['prison' => '原有監所']), 'active' => false, 'version' => 5, 'created_at' => '2000-01-01', 'updated_at' => '2000-01-01']);
        $migration->up();
        $this->assertSame(1, Prison::count());
        $id = Prison::first()->id;
        $s = ServiceSession::find($sessionId);
        $this->assertSame($id, $s->prison_id);
        $this->assertSame(7, $s->version);
        $this->assertSame('cancelled', $s->data['status']);
        $this->assertSame('2000-01-01', $s->data['service_date']);
        $this->assertSame($id, Entity::find($caseId)->prison_id);
        $this->assertSame('保留紀錄', Entity::find($caseId)->data['records'][0]['summary']);
        $this->assertSame($id, ClassTemplate::first()->prison_id);
        $this->assertFalse(ClassTemplate::first()->active);
    }
}
