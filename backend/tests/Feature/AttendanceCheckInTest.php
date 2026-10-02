<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Role;
use App\Models\ServiceSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttendanceCheckInTest extends TestCase
{
    use RefreshDatabase;

    private function teacher(bool $admin = false): User
    {
        $role = Role::firstOrCreate(['slug' => 'teacher'], ['name' => '示範教師', 'permissions' => ['schedule.read.own', 'attendance.create.own']]);
        $user = User::create(['name' => '示範教師', 'email' => uniqid().'@demo.invalid', 'phone' => '09'.str_pad((string) (User::count() + 1), 8, '0', STR_PAD_LEFT), 'password' => 'Test-Only-Password']);
        $user->roles()->attach($role);
        if ($admin) {
            $user->roles()->attach(Role::firstOrCreate(['slug' => 'system-admin'], ['name' => '示範管理員', 'permissions' => []]));
        }

        return $user;
    }

    private function assignment(User $teacher, string $date = '2026-10-02')
    {
        $session = ServiceSession::create(['data' => ['title' => '示範課程', 'prison' => '示範監所', 'location' => '示範教室', 'participant_count' => 1, 'service_date' => $date, 'start_time' => '09:00', 'end_time' => '11:00', 'status' => 'scheduled']]);

        return $session->assignments()->create(['teacher_id' => $teacher->id, 'status' => 'assigned']);
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1cAAAAASUVORK5CYII='));
    }

    public function test_admin_teacher_self_check_in_is_available_all_taiwan_day_without_reason(): void
    {
        $teacher = $this->teacher(true);
        foreach (['00:00:00', '12:00:00', '23:59:59'] as $time) {
            $this->travelTo(Carbon::parse('2026-10-02 '.$time, 'Asia/Taipei'));
            $assignment = $this->assignment($teacher);
            $response = $this->actingAs($teacher)->postJson('/api/v1/assignments/'.$assignment->id.'/attendance');
            $response->assertOk()->assertJsonPath('assignments.0.attendance.kind', 'check_in')
                ->assertJsonPath('assignments.0.attendance.at', '2026-10-02T'.$time.'+08:00')
                ->assertJsonPath('assignments.0.attendance.service_date', '2026-10-02')
                ->assertJsonPath('assignments.0.attendance.reason', null);
            $this->postJson('/api/v1/assignments/'.$assignment->id.'/attendance', ['reason' => '重複'])->assertConflict();
        }
        $this->assertSame(3, Entity::where('type', 'changes')->count());
        $this->assertSame(0, Entity::where('type', 'notifications')->count());
    }

    public function test_late_self_check_in_requires_reason_and_accepts_optional_private_photo(): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-02 20:30:00', 'Asia/Taipei'));
        $teacher = $this->teacher(true);
        $assignment = $this->assignment($teacher, '2026-10-01');
        $url = '/api/v1/assignments/'.$assignment->id.'/attendance';
        $this->actingAs($teacher)->postJson($url)->assertUnprocessable();
        $this->postJson($url, ['reason' => '   '])->assertUnprocessable();
        $this->postJson($url, ['reason' => '忘記簽到'])->assertOk()->assertJsonPath('assignments.0.attendance.kind', 'late_check_in')
            ->assertJsonPath('assignments.0.attendance.at', '2026-10-02T20:30:00+08:00')->assertJsonPath('assignments.0.attendance.photo_id', null);
        $withPhoto = $this->assignment($teacher, '2026-09-30');
        $this->post('/api/v1/assignments/'.$withPhoto->id.'/attendance', ['mode' => 'self', 'reason' => '補簽紀錄', 'photo' => $this->photo()], ['Accept' => 'application/json'])->assertOk();
        $file = Entity::where('type', 'files')->firstOrFail();
        $this->assertSame('private', $file->data['visibility']);
        $this->assertSame($withPhoto->id, $file->data['assignment_id']);
        $this->assertSame($file->id, $withPhoto->fresh()->attendance['photo_id']);
        $this->get('/api/v1/files/'.$file->id.'/download')->assertOk();
        $this->actingAs($this->teacher())->get('/api/v1/files/'.$file->id.'/download')->assertForbidden();
        $this->actingAs($this->teacher(true))->get('/api/v1/files/'.$file->id.'/download')->assertOk();
        $teacher->roles()->detach();
        $this->actingAs($teacher->fresh())->get('/api/v1/files/'.$file->id.'/download')->assertForbidden();
    }

    public function test_self_check_in_rejects_future_non_owner_false_present_and_invalid_assignment(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02 12:00:00', 'Asia/Taipei'));
        $teacher = $this->teacher();
        $future = $this->assignment($teacher, '2026-10-03');
        $this->actingAs($teacher)->postJson('/api/v1/assignments/'.$future->id.'/attendance', ['reason' => '提早'])->assertUnprocessable();
        $own = $this->assignment($teacher);
        $this->postJson('/api/v1/assignments/'.$own->id.'/attendance', ['present' => false])->assertUnprocessable();
        $other = $this->assignment($this->teacher());
        $this->postJson('/api/v1/assignments/'.$other->id.'/attendance', ['mode' => 'self'])->assertForbidden();
        $this->postJson('/api/v1/assignments/'.$other->id.'/attendance', ['reason' => '冒簽'])->assertForbidden();
        $this->actingAs($this->teacher(true))->postJson('/api/v1/assignments/'.$other->id.'/attendance', ['mode' => 'self'])->assertForbidden();
        $this->actingAs($teacher)->postJson('/api/v1/assignments/'.$own->id.'/attendance', ['mode' => 'admin', 'reason' => '更正'])->assertForbidden();
        foreach (['leave', 'replaced'] as $status) {
            $own->update(['status' => $status]);
            $this->postJson('/api/v1/assignments/'.$own->id.'/attendance')->assertConflict();
        }
        $own->update(['status' => 'assigned']);
        $session = $own->session;
        $session->update(['data' => array_merge($session->data, ['status' => 'cancelled'])]);
        $this->postJson('/api/v1/assignments/'.$own->id.'/attendance')->assertConflict();
        $this->assertNull($own->fresh()->attendance);
        $this->assertSame(0, Entity::where('type', 'changes')->count());
    }

    public function test_explicit_admin_correction_preserves_photo_and_audits_previous_attendance(): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-02 12:00:00', 'Asia/Taipei'));
        $admin = $this->teacher(true);
        $assignment = $this->assignment($admin);
        $url = '/api/v1/assignments/'.$assignment->id.'/attendance';
        $this->actingAs($admin)->post($url, ['photo' => $this->photo()], ['Accept' => 'application/json'])->assertOk();
        $before = $assignment->fresh()->attendance;
        $this->postJson($url, ['mode' => 'admin', 'present' => false])->assertUnprocessable();
        $this->travelTo(Carbon::parse('2026-10-03 08:00:00', 'Asia/Taipei'));
        $this->postJson($url, ['mode' => 'admin', 'present' => false, 'reason' => '確認未出席'])->assertOk()
            ->assertJsonPath('assignments.0.attendance.kind', 'admin_adjustment')
            ->assertJsonPath('assignments.0.attendance.present', false)
            ->assertJsonPath('assignments.0.attendance.photo_id', $before['photo_id'])
            ->assertJsonPath('assignments.0.attendance.at', '2026-10-03T08:00:00+08:00');
        $event = Entity::where('type', 'changes')->latest('id')->firstOrFail();
        $this->assertSame($before, $event->data['before']['assignments'][0]['attendance']);
        $this->assertSame(false, $event->data['after']['assignments'][0]['attendance']['present']);
        $other = $this->assignment($this->teacher(), '2026-10-01');
        $this->postJson('/api/v1/assignments/'.$other->id.'/attendance', ['reason' => '管理員補登', 'present' => true])->assertOk()
            ->assertJsonPath('assignments.0.attendance.kind', 'admin_adjustment');
        $this->assertSame(0, Entity::where('type', 'notifications')->count());
    }

    public function test_self_photo_rejects_non_image_and_over_five_megabytes(): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-02 12:00:00', 'Asia/Taipei'));
        $teacher = $this->teacher();
        $assignment = $this->assignment($teacher);
        $url = '/api/v1/assignments/'.$assignment->id.'/attendance';
        $this->actingAs($teacher)->post($url, ['photo' => UploadedFile::fake()->create('proof.txt', 1, 'text/plain')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($url, ['photo' => $this->photo()->size(5121)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertNull($assignment->fresh()->attendance);
        $this->assertSame(0, Entity::where('type', 'files')->count());
    }
}
