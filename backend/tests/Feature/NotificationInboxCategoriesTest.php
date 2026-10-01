<?php

namespace Tests\Feature;

use App\Models\ContactInquiry;
use App\Models\Entity;
use App\Models\Group;
use App\Models\Order;
use App\Models\Role;
use App\Models\ServiceSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationInboxCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $permissions): User
    {
        $user = User::create([
            'name' => '合成通知同工',
            'phone' => '09'.str_pad((string) (User::count() + 1), 8, '0', STR_PAD_LEFT),
            'email' => uniqid().'@example.test',
            'password' => 'test-password',
        ]);
        $user->roles()->attach(Role::create([
            'name' => '測試通知角色',
            'slug' => uniqid('notification-test-'),
            'active' => true,
            'permissions' => $permissions,
        ]));

        return $user;
    }

    private function notification(User $owner, array $data): Entity
    {
        return Entity::create(['type' => 'notifications', 'owner_id' => $owner->id, 'data' => $data + ['read' => false]]);
    }

    private function order(): Order
    {
        return Order::create([
            'order_number' => 'E2E-'.Str::upper(Str::random(12)),
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random(20)),
            'customer_name' => '合成訂購人',
            'customer_phone' => '0900000000',
            'delivery_method' => 'pickup',
            'items' => [],
            'subtotal_cents' => 100,
            'shipping_fee_cents' => 0,
            'total_cents' => 100,
        ]);
    }

    private function inquiry(string $category = '其他諮詢'): ContactInquiry
    {
        return ContactInquiry::create([
            'submission_token' => (string) Str::uuid(),
            'reference' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random(20)),
            'name' => '合成聯絡人',
            'phone' => '0900000000',
            'category' => $category,
            'message' => '合成測試內容',
        ]);
    }

    public function test_inbox_returns_categories_and_read_all_only_changes_owned_visible_items(): void
    {
        $owner = $this->account(['schedule.read.own', 'content.read.all', 'orders.read.all', 'contacts.read.all']);
        $other = $this->account(['orders.read.all']);

        $session = ServiceSession::create(['data' => ['title' => '合成課程異動', 'service_date' => '2035-01-01', 'start_time' => '09:00', 'end_time' => '10:00', 'status' => 'scheduled']]);
        $session->assignments()->create(['teacher_id' => $owner->id, 'status' => 'assigned']);
        $course = $this->notification($owner, ['session_id' => $session->id, 'title' => '課程異動']);

        $group = Group::create(['name' => '合成通知小組']);
        $group->members()->attach($owner);
        $article = Entity::create(['type' => 'contents', 'data' => ['kind' => 'news', 'title' => '合成小組消息', 'body' => '合成內文', 'status' => 'published', 'visibility' => 'groups', 'group_ids' => [$group->id]]]);
        $message = $this->notification($owner, ['content_id' => $article->id, 'title' => '小組消息']);

        $inquiry = $this->inquiry();
        $contact = $this->notification($owner, ['contact_inquiry_id' => $inquiry->id, 'title' => '聯絡通知']);
        $order = $this->order();
        $product = $this->notification($owner, ['order_id' => $order->id, 'title' => '訂單通知']);
        $staleInquiry = $this->notification($owner, ['contact_inquiry_id' => 999999, 'title' => '過期聯絡通知']);
        $otherOrder = $this->order();
        $otherOwner = $this->notification($other, ['order_id' => $otherOrder->id, 'title' => '其他帳號通知']);

        $inbox = $this->actingAs($owner)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 4)
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.category', 'product')
            ->assertJsonPath('data.1.category', 'contact')
            ->assertJsonPath('data.2.category', 'message')
            ->assertJsonPath('data.3.category', 'course');

        $rows = $inbox->json('data');
        $this->assertSame([$product->id, $contact->id, $message->id, $course->id], array_column($rows, 'id'));

        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertTrue((bool) Entity::find($course->id)->data['read']);
        $this->assertTrue((bool) Entity::find($message->id)->data['read']);
        $this->assertFalse((bool) Entity::find($staleInquiry->id)->data['read']);
        $this->assertFalse((bool) Entity::find($otherOwner->id)->data['read']);
    }

    public function test_read_all_requires_authentication_and_stale_permission_does_not_mark_item_read(): void
    {
        $owner = $this->account(['contacts.read.all']);
        $inquiry = $this->inquiry();
        $notice = $this->notification($owner, ['contact_inquiry_id' => $inquiry->id, 'title' => '新聯絡']);

        $this->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
        $this->actingAs($owner)->getJson('/api/v1/notifications')->assertJsonCount(0, 'data');
        $owner->roles()->first()->update(['permissions' => []]);
        $this->actingAs($owner->fresh())->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertFalse((bool) Entity::find($notice->id)->data['read']);
    }
}
