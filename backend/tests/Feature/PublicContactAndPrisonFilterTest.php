<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Role;
use App\Models\ServiceSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicContactAndPrisonFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_contact_whitelists_settings_without_authentication(): void
    {
        $public = ['association_name' => '示範協會', 'contact_phone' => '0900000000', 'contact_email' => 'demo@example.invalid', 'address' => '示範地址'];
        Entity::create(['type' => 'settings', 'data' => $public + ['internal_note' => 'private', 'payment_secret' => 'never-public']]);
        $this->getJson('/api/v1/public/contact')->assertOk()->assertExactJson(['data' => $public]);
        $this->getJson('/api/v1/settings')->assertUnauthorized();
    }

    public function test_public_contact_has_safe_defaults_when_settings_do_not_exist(): void
    {
        $this->getJson('/api/v1/public/contact')->assertOk()->assertExactJson(['data' => ['association_name' => '示範監獄福音協會', 'contact_phone' => '', 'contact_email' => '', 'address' => '']]);
        Entity::create(['type' => 'settings', 'data' => ['association_name' => '示範新名稱', 'contact_phone' => null, 'private_note' => 'private']]);
        $this->getJson('/api/v1/public/contact')->assertOk()->assertExactJson(['data' => ['association_name' => '示範新名稱', 'contact_phone' => '', 'contact_email' => '', 'address' => '']]);
    }

    public function test_prison_filter_uses_contains_matching_and_retains_teacher_scope(): void
    {
        $role = Role::create(['name' => '教師', 'slug' => 'teacher', 'permissions' => ['schedule.read.own']]);
        $teacher = User::create(['name' => '示範', 'phone' => '0912345678', 'email' => 'filter@example.invalid', 'password' => 'Test-Only-Password']);
        $teacher->roles()->attach($role);
        foreach (['示範臺北監所', '示範臺中監所', '示範臺北監所秘密排課'] as $index => $prison) {
            $s = ServiceSession::create(['data' => ['title' => '示範', 'prison' => $prison, 'location' => '示範', 'service_date' => '2030-01-01', 'start_time' => '09:00', 'end_time' => '11:00', 'participant_count' => 1, 'status' => 'scheduled']]);
            if ($index < 2) {
                $s->assignments()->create(['teacher_id' => $teacher->id]);
            }
        }
        $this->actingAs($teacher)->getJson('/api/v1/sessions?prison='.urlencode('臺北'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.prison', '示範臺北監所');
        $this->getJson('/api/v1/sessions?prison='.urlencode('不存在'))->assertOk()->assertJsonCount(0, 'data');
    }
}
