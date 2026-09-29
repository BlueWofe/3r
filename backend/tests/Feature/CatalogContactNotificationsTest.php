<?php

namespace Tests\Feature;

use App\Models\ContactInquiry;
use App\Models\Entity;
use App\Models\Group;
use App\Models\Role;
use App\Models\ServiceSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogContactNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $permissions): User
    {
        $u = User::create(['name' => '合成同工', 'phone' => '09'.str_pad((string) User::count(), 8, '0', STR_PAD_LEFT), 'email' => uniqid().'@example.test', 'password' => 'test-password']);
        $u->roles()->attach(Role::create(['name' => '測試', 'slug' => uniqid(), 'active' => true, 'permissions' => $permissions]));

        return $u;
    }

    private function contact(string $category = '試吃'): array
    {
        return ['name' => '私人合成姓名', 'phone' => '0900000000', 'category' => $category, 'message' => '私人合成訊息', 'submission_token' => (string) Str::uuid()];
    }

    public function test_product_categories_are_public_only_and_filter_before_limit_without_view_increment(): void
    {
        foreach ([['食品', 'published'], ['禮盒', 'published'], ['僅草稿', 'draft']] as [$category,$status]) {
            Entity::create(['type' => 'contents', 'data' => ['kind' => 'product', 'title' => $category, 'body' => '文字', 'category' => $category, 'status' => $status, 'views' => 7]]);
        }
        Entity::create(['type' => 'contents', 'data' => ['kind' => 'news', 'title' => '消息', 'body' => '文字', 'category' => '非商品', 'status' => 'published']]);
        Entity::create(['type' => 'contents', 'data' => ['kind' => 'product', 'title' => '未到期', 'body' => '文字', 'category' => '未到期', 'status' => 'published', 'published_at' => '2099-01-01T00:00:00+08:00']]);
        $this->getJson('/api/v1/public/products?category=禮盒&limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.category', '禮盒')->assertJsonPath('data.0.views', 7)->assertJsonCount(2, 'categories');
        $this->getJson('/api/v1/public/products?category=&limit=100')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/public/products?limit=101')->assertUnprocessable();
        $this->getJson('/api/v1/public/products?category=不存在')->assertJsonCount(0, 'data')->assertJsonCount(2, 'categories');
        $this->assertSame(7, Entity::where('type', 'contents')->first()->data['views']);
    }

    public function test_contact_categories_deduplicate_content_across_tokens_and_keep_generic_authorized_notifications(): void
    {
        $reader = $this->account(['contacts.read.all']);
        $member = $this->account([]);
        $disabled = $this->account(['contacts.read.all']);
        $disabled->update(['active' => false]);
        $v = $this->contact();
        $response = $this->postJson('/api/v1/public/contact', $v)->assertOk()->json();
        $alias = array_replace($v, ['submission_token' => (string) Str::uuid()]);
        $this->postJson('/api/v1/public/contact', $alias)->assertOk()->assertExactJson($response);
        $this->postJson('/api/v1/public/contact', array_replace($alias, ['message' => '不同內容']))->assertConflict();
        $this->assertDatabaseCount('contact_inquiries', 1);
        $this->assertDatabaseCount('contact_submission_tokens', 2);
        $this->assertSame(1, Entity::where('type', 'notifications')->count());
        $id = ContactInquiry::first()->id;
        $row = $this->actingAs($reader)->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread_count', 1)->assertJsonPath('data.0.url', '/app/admin/contact-inquiries?id='.$id)->json('data.0');
        $this->assertStringNotContainsString('私人合成', json_encode($row, JSON_UNESCAPED_UNICODE));
        $this->postJson('/api/v1/notifications/'.$row['id'].'/read')->assertOk()->assertJsonPath('read', true);
        $this->getJson('/api/v1/notifications')->assertJsonPath('unread_count', 0);
        $this->actingAs($member)->getJson('/api/v1/notifications')->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0);
        $this->postJson('/api/v1/notifications/'.$row['id'].'/read')->assertForbidden();
        $reader->roles()->first()->update(['permissions' => []]);
        $this->actingAs($reader->fresh())->getJson('/api/v1/notifications')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/notifications/'.$row['id'].'/read')->assertForbidden();
        $this->postJson('/api/v1/public/contact', $this->contact('大宗認購專案'))->assertOk();
        $this->assertDatabaseCount('contact_inquiries', 2);
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/public/contact', array_replace($v, ['submission_token' => (string) Str::uuid()]))->assertOk();
        $this->assertDatabaseCount('contact_inquiries', 3);
    }

    public function test_group_notifications_recheck_audience_and_ignore_untrusted_urls(): void
    {
        $member = $this->account([]);
        $group = Group::create(['name' => '合成小組']);
        $group->members()->attach($member);
        $article = Entity::create(['type' => 'contents', 'data' => ['kind' => 'news', 'title' => '私密標題', 'body' => '私密內文', 'status' => 'published', 'visibility' => 'groups', 'group_ids' => [$group->id]]]);
        $notification = Entity::create(['type' => 'notifications', 'owner_id' => $member->id, 'data' => ['content_id' => $article->id, 'url' => 'https://evil.test', 'title' => '私密標題', 'read' => false]]);
        $this->actingAs($member)->getJson('/api/v1/notifications')->assertJsonPath('data.0.url', '/app/group-news/'.$article->id)->assertJsonPath('unread_count', 1)->assertJsonMissing(['title' => '私密標題']);
        $group->members()->detach($member);
        $this->getJson('/api/v1/notifications')->assertJsonPath('unread_count', 0)->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/notifications/'.$notification->id.'/read')->assertForbidden();
    }

    public function test_hourly_ip_limit_and_same_token_replay_are_independent_of_storage_duplicates(): void
    {
        $v = $this->contact();
        for ($i = 0; $i < 100; $i++) {
            if ($i > 0 && $i % 19 === 0) {
                $this->travel(61)->seconds();
            }
            $this->postJson('/api/v1/public/contact', $v)->assertOk();
        }
        $this->postJson('/api/v1/public/contact', $v)->assertStatus(429);
        $this->assertDatabaseCount('contact_inquiries', 1);
    }

    public function test_schedule_notifications_have_safe_fallback_links_and_recheck_teacher_scope(): void
    {
        $teacher = $this->account(['schedule.read.own']);
        $session = ServiceSession::create(['data' => ['title' => '合成排課', 'service_date' => '2035-01-01', 'start_time' => '09:00', 'end_time' => '10:00', 'status' => 'scheduled']]);
        $session->assignments()->create(['teacher_id' => $teacher->id]);
        $notification = Entity::create(['type' => 'notifications', 'owner_id' => $teacher->id, 'data' => ['session_id' => $session->id, 'title' => '排課異動', 'url' => 'https://evil.test']]);
        $this->actingAs($teacher)->getJson('/api/v1/notifications')->assertJsonPath('data.0.url', '/app/calendar')->assertJsonPath('unread_count', 1);
        $teacher->roles()->first()->update(['permissions' => []]);
        $this->actingAs($teacher->fresh())->getJson('/api/v1/notifications')->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0);
        $this->postJson('/api/v1/notifications/'.$notification->id.'/read')->assertForbidden();
        $admin = $this->account(['schedule.read.all']);
        Entity::create(['type' => 'notifications', 'owner_id' => $admin->id, 'data' => ['title' => '舊排課通知', 'read' => false]]);
        $this->actingAs($admin)->getJson('/api/v1/notifications')->assertJsonPath('data.0.url', '/app/admin/schedule');
    }
}
