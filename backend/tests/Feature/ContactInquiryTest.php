<?php

namespace Tests\Feature;

use App\Models\ContactInquiry;
use App\Models\Entity;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContactInquiryTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['name' => '合成聯絡人', 'phone' => '0900000000', 'email' => 'test@example.test', 'category' => '志工加入', 'message' => '<script>僅作純文字</script>', 'submission_token' => (string) Str::uuid()];
    }

    private function actor(array $permissions): User
    {
        $u = User::create(['name' => '合成人員', 'email' => uniqid().'@example.test', 'phone' => '09'.str_pad((string) User::count(), 8, '0', STR_PAD_LEFT), 'password' => 'test-password']);
        $u->roles()->attach(Role::create(['name' => '測試角色', 'slug' => uniqid(), 'active' => true, 'permissions' => $permissions]));

        return $u;
    }

    public function test_public_submission_is_private_idempotent_and_conflicting_reuse_is_rejected(): void
    {
        $v = $this->payload();
        $first = $this->postJson('/api/v1/public/contact', $v)->assertOk()->assertExactJson(['message' => '已收到您的訊息', 'reference' => ContactInquiry::first()->reference]);
        $this->postJson('/api/v1/public/contact', $v)->assertOk()->assertJson($first->json());
        $this->assertDatabaseCount('contact_inquiries', 1);
        $this->postJson('/api/v1/public/contact', array_replace($v, ['message' => '另一則']))->assertConflict();
        $this->assertDatabaseCount('contact_inquiries', 1);
        $this->getJson('/api/v1/contact-inquiries')->assertUnauthorized();
        $this->getJson('/api/v1/public/search?q=合成聯絡人')->assertJsonCount(0, 'data');
        $this->assertSame('<script>僅作純文字</script>', ContactInquiry::first()->message);
        $this->assertSame(0, Entity::whereIn('type', ['notifications', 'line-outbox', 'contents'])->count());
        $withoutEmail = $this->payload();
        unset($withoutEmail['email']);
        $confirmation = $this->postJson('/api/v1/public/contact', $withoutEmail)->assertOk()->json();
        $this->postJson('/api/v1/public/contact', array_replace($withoutEmail, ['email' => null, 'submission_token' => strtoupper($withoutEmail['submission_token'])]))->assertOk()->assertExactJson($confirmation);
    }

    public function test_validation_honeypot_and_isolated_ip_rate_limit(): void
    {
        $this->postJson('/api/v1/public/contact', array_replace($this->payload(), ['website' => 'bot']))->assertUnprocessable();
        $this->postJson('/api/v1/public/contact', array_replace($this->payload(), ['category' => '不允許']))->assertUnprocessable();
        $this->assertDatabaseCount('contact_inquiries', 0);
        for ($i = 0; $i < 18; $i++) {
            $this->postJson('/api/v1/public/contact', $this->payload())->assertOk();
        }
        $this->postJson('/api/v1/public/contact', $this->payload())->assertStatus(429);
    }

    public function test_permission_filters_versions_and_audit_preserve_original_input(): void
    {
        $this->postJson('/api/v1/public/contact', $this->payload())->assertOk();
        $item = ContactInquiry::first();
        $outsider = $this->actor(['content.read.all']);
        $this->actingAs($outsider)->getJson('/api/v1/contact-inquiries')->assertForbidden();
        $this->putJson('/api/v1/contact-inquiries/'.$item->id, ['version' => 1, 'status' => 'closed'])->assertForbidden();
        $reader = $this->actor(['contacts.read.all']);
        $this->actingAs($reader)->getJson('/api/v1/contact-inquiries?category=志工加入&status=new&q=合成')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.version', 1);
        $this->getJson('/api/v1/contact-inquiries?category=&status=&q=')->assertOk();
        $this->putJson('/api/v1/contact-inquiries/'.$item->id, ['version' => 1, 'status' => 'closed'])->assertForbidden();
        $handler = $this->actor(['contacts.update.all']);
        $this->actingAs($handler)->putJson('/api/v1/contact-inquiries/'.$item->id, ['version' => 1, 'status' => 'processing', 'staff_note' => '已受理', 'message' => '不可覆寫'])->assertOk()->assertJsonPath('version', 2)->assertJsonPath('handled_by_name', $handler->name)->assertJsonPath('message', $item->message);
        $this->putJson('/api/v1/contact-inquiries/'.$item->id, ['version' => 1, 'status' => 'closed'])->assertConflict();
        $this->putJson('/api/v1/contact-inquiries/'.$item->id, ['version' => 2, 'status' => 'closed'])->assertOk()->assertJsonPath('version', 3);
        $this->assertSame(2, Entity::where('type', 'audit')->count());
        $this->actingAs($reader)->getJson('/api/v1/contact-inquiries?status=new')->assertJsonCount(0, 'data');
    }
}
