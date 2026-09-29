<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Role;
use App\Models\ServiceSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ModuleSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'ministry.demo_seed' => true,
            'ministry.demo_password' => 'synthetic-module-password',
        ]);
        $this->seed();
    }

    public function test_report_filters_completed_assignments_and_keeps_financial_totals_private(): void
    {
        $teacherRole = Role::where('slug', 'teacher')->firstOrFail();
        $teacher = User::create([
            'name' => '報表測試教師',
            'email' => 'report-teacher@example.test',
            'phone' => '0911111111',
            'password' => Hash::make('synthetic-module-password'),
            'active' => true,
        ]);
        $teacher->roles()->attach($teacherRole);
        $date = now('Asia/Taipei')->subDays(40)->toDateString();

        $assigned = $this->makeSession($date);
        $assigned->assignments()->create(['teacher_id' => $teacher->id, 'status' => 'assigned', 'attendance' => ['present' => false]]);
        $leave = $this->makeSession($date);
        $leave->assignments()->create(['teacher_id' => $teacher->id, 'status' => 'leave']);
        $coveringTeacher = User::where('phone', '0900000001')->firstOrFail();
        $leave->assignments()->create(['teacher_id' => $coveringTeacher->id, 'status' => 'assigned']);
        $replaced = $this->makeSession($date);
        $replaced->assignments()->create(['teacher_id' => $teacher->id, 'status' => 'replaced']);
        $replaced->assignments()->create(['teacher_id' => $coveringTeacher->id, 'status' => 'assigned']);
        $vacant = $this->makeSession($date);
        $cancelled = $this->makeSession($date, 'cancelled');
        $cancelled->assignments()->create(['teacher_id' => $teacher->id, 'status' => 'assigned']);
        $futureForFilter = $this->makeSession(now('Asia/Taipei')->subDays(39)->toDateString());
        $futureForFilter->assignments()->create(['teacher_id' => $teacher->id, 'status' => 'assigned']);

        Entity::create(['type' => 'donations', 'owner_id' => $teacher->id, 'data' => [
            'amount' => 250, 'status' => 'success', 'provider' => 'mock', 'simulated_at' => $date.'T10:00:00+08:00',
        ]]);
        Entity::create(['type' => 'donations', 'owner_id' => $teacher->id, 'data' => [
            'amount' => 900, 'status' => 'success', 'provider' => 'live-provider', 'simulated_at' => $date.'T10:00:00+08:00',
        ]]);
        Entity::create(['type' => 'contents', 'owner_id' => $teacher->id, 'data' => [
            'kind' => 'product', 'status' => 'published', 'views' => 13,
        ]]);

        $admin = User::where('phone', '0900000001')->firstOrFail();
        $all = $this->actingAs($admin)->getJson('/api/v1/reports?from='.$date.'&to='.$date);
        $all->assertOk()
            ->assertJsonPath('summary.completed_sessions', 4)
            ->assertJsonPath('summary.vacancies', 1)
            ->assertJsonPath('summary.assigned_denominator', 3)
            ->assertJsonPath('summary.attendance_count', 0)
            ->assertJsonPath('summary.attendance_rate', 0)
            ->assertJsonPath('summary.leave_count', 1)
            ->assertJsonPath('summary.replaced_count', 1)
            ->assertJsonPath('summary.successful_test_donations_sum', 250)
            ->assertJsonPath('summary.product_views', 13);
        $teacherReport = collect($all->json('teachers'))->firstWhere('teacher_id', $teacher->id);
        $this->assertSame(1, $teacherReport['assigned']);
        $this->assertSame(1, $teacherReport['completed_sessions']);

        $filtered = $this->actingAs($admin)->getJson('/api/v1/reports?from='.$date.'&to='.$date.'&teacher_id='.$teacher->id);
        $filtered->assertOk()
            ->assertJsonPath('filters.teacher_id', $teacher->id)
            ->assertJsonPath('summary.completed_sessions', 3)
            ->assertJsonPath('summary.vacancies', 0)
            ->assertJsonPath('summary.assigned_denominator', 1);

        $own = $this->actingAs($teacher)->getJson('/api/v1/reports?from='.$date.'&to='.$date);
        $own->assertOk()
            ->assertJsonMissingPath('summary.successful_test_donations_sum')
            ->assertJsonMissingPath('summary.product_views')
            ->assertJsonPath('teachers.0.teacher_id', $teacher->id)
            ->assertJsonPath('summary.completed_sessions', 3);
        $this->getJson('/api/v1/reports?teacher_id='.$admin->id)->assertForbidden();

        $reportManager = User::where('phone', '0900000005')->firstOrFail();
        $reportsRole = Role::create(['name' => '僅報表管理', 'slug' => 'test-reports-only', 'active' => true, 'permissions' => ['reports.read.all']]);
        $reportManager->roles()->attach($reportsRole);
        $withoutFinance = $this->actingAs($reportManager)->getJson('/api/v1/reports?from='.$date.'&to='.$date.'&teacher_id='.$teacher->id);
        $withoutFinance->assertOk()
            ->assertJsonPath('summary.product_views', 13)
            ->assertJsonMissingPath('summary.successful_test_donations_sum');

        $financeRole = Role::create(['name' => '奉獻報表檢視', 'slug' => 'test-donation-report-reader', 'active' => true, 'permissions' => ['donations.read.all']]);
        $reportManager->roles()->attach($financeRole);
        $withFinance = $this->actingAs($reportManager)->getJson('/api/v1/reports?from='.$date.'&to='.$date.'&teacher_id='.$teacher->id);
        $withFinance->assertOk()->assertJsonPath('summary.successful_test_donations_sum', 250);
    }

    public function test_unlinked_private_files_are_not_readable_by_global_resource_readers(): void
    {
        Storage::fake('local');
        $contentManager = User::where('phone', '0900000005')->firstOrFail();
        $admin = User::where('phone', '0900000001')->firstOrFail();

        $upload = $this->actingAs($contentManager)->post('/api/v1/files', [
            'file' => UploadedFile::fake()->create('orphan.txt', 1, 'text/plain'),
            'visibility' => 'private',
        ])->assertOk();
        $fileId = $upload->json('id');

        $this->actingAs($contentManager)->get('/api/v1/files/'.$fileId.'/download')->assertOk();
        $this->actingAs($admin)->get('/api/v1/files/'.$fileId.'/download')->assertForbidden();
    }

    public function test_form_file_upload_is_bound_to_a_published_file_field_and_downloadable_by_its_manager(): void
    {
        Storage::fake('local');
        $admin = User::where('phone', '0900000001')->firstOrFail();
        $member = User::where('phone', '0900000003')->firstOrFail();
        $manager = User::where('phone', '0900000005')->firstOrFail();
        $formManagerRole = Role::create(['name' => '表單管理員', 'slug' => 'test-form-manager', 'active' => true, 'permissions' => ['forms.read.all', 'forms.export.all']]);
        $manager->roles()->attach($formManagerRole);
        $resourceReader = User::where('phone', '0900000004')->firstOrFail();
        $resourceReaderRole = Role::create(['name' => '只有資源全讀', 'slug' => 'test-resource-reader', 'active' => true, 'permissions' => ['resources.read.all']]);
        $resourceReader->roles()->sync([$resourceReaderRole->id]);
        $form = Entity::create(['type' => 'forms', 'owner_id' => $admin->id, 'data' => [
            'title' => '附件回覆表單',
            'status' => 'published',
            'version' => 1,
            'role_ids' => [],
            'fields' => [
                ['key' => 'attachment', 'label' => '附件', 'type' => 'file', 'required' => true],
                ['key' => 'optional_note', 'label' => '補充說明', 'type' => 'text', 'required' => false],
            ],
            'snapshots' => [['version' => 1, 'fields' => [], 'published_at' => now()->toIso8601String()]],
        ]]);

        $upload = $this->actingAs($member)->post('/api/v1/files', [
            'file' => UploadedFile::fake()->create('response.txt', 1, 'text/plain'),
            'visibility' => 'private',
            'form_id' => $form->id,
            'field_key' => 'attachment',
        ])->assertOk();
        $fileId = $upload->json('id');
        $this->assertSame('private', Entity::where('type', 'files')->findOrFail($fileId)->data['visibility']);

        $response = $this->actingAs($member)->postJson('/api/v1/forms/'.$form->id.'/responses', [
            'answers' => ['attachment' => $fileId],
        ])->assertOk();
        $this->assertSame(1, $response->json('version'));
        $this->actingAs($manager)->get('/api/v1/files/'.$fileId.'/download')->assertOk();
        $this->actingAs($resourceReader)->get('/api/v1/files/'.$fileId.'/download')->assertForbidden();

        $unrelatedUpload = $this->actingAs($admin)->post('/api/v1/files', [
            'file' => UploadedFile::fake()->create('unrelated.txt', 1, 'text/plain'),
            'visibility' => 'private',
        ])->assertOk();
        $this->actingAs($member)->postJson('/api/v1/forms/'.$form->id.'/responses', [
            'answers' => ['attachment' => $unrelatedUpload->json('id')],
        ])->assertUnprocessable();

        $this->actingAs($member)->post('/api/v1/files', [
            'file' => UploadedFile::fake()->create('wrong-field.txt', 1, 'text/plain'),
            'visibility' => 'private',
            'form_id' => $form->id,
            'field_key' => 'optional_note',
        ])->assertUnprocessable();
    }

    public function test_form_export_includes_the_matching_snapshot_and_neutralizes_formula_values(): void
    {
        $admin = User::where('phone', '0900000001')->firstOrFail();
        $form = Entity::create(['type' => 'forms', 'owner_id' => $admin->id, 'data' => [
            'title' => 'CSV 安全驗收', 'status' => 'published', 'version' => 1, 'role_ids' => [],
            'fields' => [['key' => 'comment', 'label' => '回饋', 'type' => 'text', 'required' => true]],
            'snapshots' => [['version' => 1, 'fields' => [['key' => 'comment', 'label' => '=欄位', 'type' => 'text']], 'published_at' => now()->toIso8601String()]],
        ]]);
        Entity::create(['type' => 'responses', 'owner_id' => User::where('phone', '0900000003')->value('id'), 'data' => [
            'form_id' => $form->id, 'version' => 1, 'answers' => ['comment' => '=HYPERLINK("https://example.test")'],
        ]]);

        $csv = $this->actingAs($admin)->get('/api/v1/forms/'.$form->id.'/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('欄位快照', $csv);
        $this->assertStringContainsString("'=欄位", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_case_records_are_appended_under_lock_and_audited_before_and_after(): void
    {
        $admin = User::where('phone', '0900000001')->firstOrFail();
        $case = Entity::where('type', 'cases')->firstOrFail();

        $first = $this->actingAs($admin)->postJson('/api/v1/cases/'.$case->id.'/records', [
            'service_date' => now('Asia/Taipei')->toDateString(), 'type' => '探訪', 'summary' => '第一筆合成紀錄',
        ])->assertOk();
        $first->assertJsonCount(1, 'records');
        $second = $this->actingAs($admin)->postJson('/api/v1/cases/'.$case->id.'/records', [
            'service_date' => now('Asia/Taipei')->toDateString(), 'type' => '電話關懷', 'summary' => '第二筆合成紀錄', 'follow_up' => '下月追蹤',
        ])->assertOk();
        $second->assertJsonCount(2, 'records');

        $audits = Entity::where('type', 'audit')->where('data->module', 'cases')->where('data->subject_id', $case->id)->get();
        $this->assertCount(2, $audits);
        $this->assertSame([], $audits[0]->data['before']);
        $this->assertCount(1, $audits[0]->data['after']);
        $this->assertCount(1, $audits[1]->data['before']);
        $this->assertCount(2, $audits[1]->data['after']);
    }

    public function test_change_inbox_and_acknowledgement_require_own_schedule_permission(): void
    {
        $teacher = User::where('phone', '0900000002')->firstOrFail();
        $event = Entity::create(['type' => 'changes', 'owner_id' => $teacher->id, 'data' => [
            'session_id' => ServiceSession::query()->firstOrFail()->id,
            'acknowledged_by' => [],
        ]]);

        $teacher->roles()->detach(Role::where('slug', 'teacher')->value('id'));
        $this->actingAs($teacher)->getJson('/api/v1/changes')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/changes/'.$event->id.'/acknowledge')->assertForbidden();
    }

    public function test_settings_use_the_public_association_name_by_default(): void
    {
        $admin = User::where('phone', '0900000001')->firstOrFail();

        $this->actingAs($admin)->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('association_name', '中華復甦更新發展協會');
    }

    private function makeSession(string $date, string $status = 'scheduled'): ServiceSession
    {
        return ServiceSession::create(['data' => [
            'title' => '報表範圍測試',
            'prison' => '合成場域',
            'location' => '測試室',
            'participant_count' => 0,
            'service_date' => $date,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'status' => $status,
        ]]);
    }
}
