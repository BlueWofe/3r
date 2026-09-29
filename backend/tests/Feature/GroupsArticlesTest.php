<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Group;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GroupsArticlesTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $permissions = [], bool $admin = false): User
    {
        $u = User::create(['name' => '示範'.User::count(), 'email' => uniqid().'@example.test', 'phone' => '09'.str_pad((string) (User::count() + 1), 8, '0', STR_PAD_LEFT), 'password' => 'Test-only-password']);
        $u->roles()->attach(Role::create(['name' => '測試', 'slug' => $admin ? 'system-admin' : uniqid('role'), 'permissions' => $permissions, 'active' => true]));

        return $u;
    }

    private function group(array $members = [], bool $active = true): Group
    {
        $g = Group::create(['name' => uniqid('示範小組'), 'active' => $active]);
        $g->members()->sync(array_map(fn ($u) => $u->id, $members));

        return $g;
    }

    private function article(array $extra = []): array
    {
        return array_merge(['kind' => 'news', 'title' => '私人標題測試', 'slug' => uniqid('story'), 'body' => '私人內文測試', 'status' => 'published', 'category' => '代禱消息'], $extra);
    }

    private function publish(Group $group, array $extra = []): int
    {
        return $this->postJson('/api/v1/contents', $this->article(array_merge(['visibility' => 'groups', 'group_ids' => [$group->id]], $extra)))->assertOk()->json('id');
    }

    public function test_groups_manage_members_versions_and_options_never_grant_roles_or_expose_phones(): void
    {
        $member = $this->account();
        $disabled = $this->account();
        $disabled->update(['active' => false]);
        $manager = $this->account(['groups.manage.all']);
        $this->actingAs($member)->getJson('/api/v1/groups')->assertForbidden();
        $this->getJson('/api/v1/groups/member-options')->assertForbidden();
        $this->actingAs($manager);
        $body = ['name' => '示範探訪組', 'description' => '合成描述', 'active' => true, 'member_ids' => [$member->id, $member->id]];
        $id = $this->postJson('/api/v1/groups', $body)->assertOk()->assertJsonPath('version', 1)->assertJsonPath('member_count', 1)->assertJsonPath('members.0.name', $member->name)->assertJsonMissing(['phone' => $member->phone])->json('id');
        $this->putJson('/api/v1/groups/'.$id, $body + ['version' => 1])->assertOk()->assertJsonPath('version', 2);
        $this->putJson('/api/v1/groups/'.$id, $body + ['version' => 1])->assertConflict();
        $bad = $body;
        $bad['member_ids'] = [$disabled->id];
        $this->putJson('/api/v1/groups/'.$id, $bad + ['version' => 2])->assertUnprocessable();
        $this->getJson('/api/v1/groups/member-options')->assertOk()->assertJsonMissing(['phone' => $member->phone]);
        $this->actingAs($member->fresh())->getJson('/api/v1/groups/options')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', '示範探訪組');
        $this->getJson('/api/v1/groups')->assertForbidden();
        $this->assertFalse($member->fresh()->canDo('groups.manage.all'));
        $this->assertSame(2, Entity::where('type', 'audit')->where('data->module', 'groups')->count());
    }

    public function test_article_categories_legacy_preservation_versions_and_filters_before_limit(): void
    {
        $admin = $this->account([], true);
        $this->actingAs($admin);
        $old = Entity::create(['type' => 'contents', 'data' => $this->article(['category' => '見證分享'])]);
        $this->getJson('/api/v1/public/news?article_type=testimony&category='.urlencode('見證分享').'&limit=1')->assertJsonPath('data.0.id', $old->id)->assertJsonPath('data.0.article_type', 'testimony');
        $body = $this->article(['article_type' => 'sharing', 'category' => '志工招募']);
        $id = $this->postJson('/api/v1/contents', $body)->assertOk()->assertJsonPath('version', 1)->json('id');
        unset($body['article_type']);
        $this->putJson('/api/v1/contents/'.$id, $body + ['version' => 1])->assertOk()->assertJsonPath('article_type', 'sharing')->assertJsonPath('version', 2);
        $this->putJson('/api/v1/contents/'.$id, $body + ['version' => 1])->assertConflict();
        $this->putJson('/api/v1/contents/'.$id, $body)->assertOk()->assertJsonPath('version', 3);
        $this->getJson('/api/v1/public/news?article_type=sharing&category='.urlencode('志工招募').'&limit=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/public/news?article_type=&category=')->assertOk();
        $this->getJson('/api/v1/public/news?article_type=bad')->assertUnprocessable();
    }

    public function test_group_news_membership_is_immediate_public_never_leaks_and_editors_can_read(): void
    {
        $member = $this->account(['forms.read.own', 'donations.read.own']);
        $outsider = $this->account();
        $admin = $this->account([], true);
        $group = $this->group([$member]);
        $this->actingAs($admin);
        $id = $this->publish($group, ['article_type' => 'sharing']);
        $this->getJson('/api/v1/public/news')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/news/'.$id)->assertNotFound();
        $this->getJson('/api/v1/public/search?q='.urlencode('私人'))->assertJsonCount(0, 'data');
        $this->actingAs($member)->getJson('/api/v1/group-news/'.$id)->assertOk()->assertJsonPath('data.group_names.0', $group->name)->assertJsonPath('data.version', 1);
        $this->getJson('/api/v1/group-news')->assertJsonCount(1, 'data');
        $this->actingAs($outsider)->getJson('/api/v1/group-news/'.$id)->assertNotFound();
        $group->members()->detach($member);
        $this->actingAs($member)->getJson('/api/v1/group-news/'.$id)->assertNotFound();
        $group->members()->attach($member);
        $group->update(['active' => false]);
        $this->getJson('/api/v1/group-news')->assertJsonCount(0, 'data');
        $this->actingAs($admin)->getJson('/api/v1/group-news/'.$id)->assertOk();
    }

    public function test_private_articles_reject_images_inactive_groups_pages_and_future_broadcasts(): void
    {
        $this->actingAs($this->account([], true));
        $group = $this->group();
        $inactive = $this->group([], false);
        foreach ([['image_id' => 123], ['body_format' => 'html', 'body' => '<p>文字</p><IMG src="/api/v1/files/123/download">'], ['kind' => 'page'], ['group_ids' => []], ['group_ids' => [$inactive->id]]] as $extra) {
            $this->postJson('/api/v1/contents', $this->article(array_merge(['visibility' => 'groups', 'group_ids' => [$group->id]], $extra)))->assertUnprocessable();
        }
        $id = $this->publish($group, ['published_at' => '2099-01-01T00:00:00+08:00']);
        $this->getJson('/api/v1/group-news/'.$id)->assertNotFound();
        $this->postJson('/api/v1/contents/'.$id.'/broadcast', ['version' => 1])->assertUnprocessable();
    }

    public function test_broadcast_deduplicates_group_union_and_versions_and_keeps_private_text_out_of_notifications(): void
    {
        $member = $this->account();
        $second = $this->account();
        $disabled = $this->account();
        $disabled->update(['active' => false]);
        $admin = $this->account([], true);
        $one = $this->group([$member, $second, $disabled]);
        $two = $this->group([$member]);
        $this->actingAs($admin);
        $id = $this->publish($one, ['group_ids' => [$one->id, $two->id]]);
        $response = $this->postJson('/api/v1/contents/'.$id.'/broadcast', ['version' => 1])->assertOk()->assertJsonPath('recipient_count', 2)->assertJsonPath('duplicate', false)->assertJsonPath('line_mode', 'mock');
        $this->postJson('/api/v1/contents/'.$id.'/broadcast', ['version' => 1])->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('id', $response->json('id'));
        $this->assertSame(2, Entity::where('type', 'notifications')->count());
        $this->assertSame(2, Entity::where('type', 'line-outbox')->count());
        foreach (Entity::whereIn('type', ['notifications', 'line-outbox'])->get() as $e) {
            $raw = json_encode($e->data, JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('私人標題測試', $raw);
            $this->assertStringNotContainsString('私人內文測試', $raw);
            $this->assertSame('/app/group-news/'.$id, $e->data['url']);
        }
        $this->getJson('/api/v1/contents/'.$id.'/broadcasts')->assertJsonCount(1, 'data')->assertJsonPath('data.0.version', 1);
        $this->putJson('/api/v1/contents/'.$id, $this->article(['visibility' => 'groups', 'group_ids' => [$one->id], 'version' => 1]))->assertOk()->assertJsonPath('version', 2);
        $this->postJson('/api/v1/contents/'.$id.'/broadcast', ['version' => 1])->assertConflict();
        $this->postJson('/api/v1/contents/'.$id.'/broadcast', ['version' => 2])->assertOk()->assertJsonPath('duplicate', false);
        $this->assertSame(4, Entity::where('type', 'notifications')->count());
        $this->actingAs($member)->postJson('/api/v1/contents/'.$id.'/broadcast', ['version' => 2])->assertForbidden();
        $this->actingAs($admin)->deleteJson('/api/v1/contents/'.$id)->assertOk();
        $this->assertSame(2, DB::table('content_broadcasts')->where('content_id', $id)->count());
        $this->actingAs($member)->getJson('/api/v1/group-news/'.$id)->assertNotFound();
    }

    public function test_broadcast_requires_both_permissions_and_active_recipients(): void
    {
        $publisher = $this->account(['content.create.all', 'content.publish.all']);
        $group = $this->group();
        $this->actingAs($publisher);
        $id = $this->publish($group);
        $this->postJson('/api/v1/contents/'.$id.'/broadcast', ['version' => 1])->assertForbidden();
        $this->actingAs($this->account(['content.publish.all', 'groups.broadcast.all']))->postJson('/api/v1/contents/'.$id.'/broadcast', ['version' => 1])->assertUnprocessable();
        $this->assertSame(0, DB::table('content_broadcasts')->count());
        $this->assertSame(0, Entity::where('type', 'notifications')->count());
    }

    public function test_resources_and_meetings_need_function_and_audience_and_resource_files_cannot_use_broader_meeting(): void
    {
        Storage::fake();
        $member = $this->account(['resources.read.own', 'meetings.read.own']);
        $noFunction = $this->account();
        $admin = $this->account([], true);
        $group = $this->group([$member, $noFunction]);
        $this->actingAs($admin);
        $resource = $this->post('/api/v1/resources', ['file' => UploadedFile::fake()->createWithContent('demo.txt', '合成附件'), 'title' => '小組資源', 'category' => '講義', 'group_ids' => [$group->id]], ['Accept' => 'application/json'])->assertOk()->json();
        $meetingBody = ['title' => '廣泛會議', 'meeting_date' => '2030-01-01', 'agenda' => '合成', 'file_ids' => [$resource['file_id']], 'role_ids' => [], 'group_ids' => []];
        $meetingId = $this->postJson('/api/v1/meetings', $meetingBody)->assertOk()->json('id');
        $this->actingAs($member)->getJson('/api/v1/resources?group_id='.$group->id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.group_names.0', $group->name);
        $this->get('/api/v1/files/'.$resource['file_id'].'/download')->assertOk();
        $this->actingAs($noFunction)->getJson('/api/v1/resources')->assertForbidden();
        $this->get('/api/v1/files/'.$resource['file_id'].'/download')->assertForbidden();
        $group->members()->detach($member);
        $this->actingAs($member)->getJson('/api/v1/resources')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/meetings/'.$meetingId)->assertOk();
        $this->get('/api/v1/files/'.$resource['file_id'].'/download')->assertForbidden();
        $this->actingAs($admin)->putJson('/api/v1/resources/'.$resource['id'], ['group_ids' => [], 'title' => '公開給功能成員的資源'])->assertOk()->assertJsonPath('file_id', $resource['file_id']);
        $this->actingAs($member)->get('/api/v1/files/'.$resource['file_id'].'/download')->assertOk();
    }

    public function test_resource_self_upload_only_selects_own_groups_and_cannot_assign_roles(): void
    {
        Storage::fake();
        $u = $this->account(['resources.create.own', 'resources.read.own']);
        $own = $this->group([$u]);
        $other = $this->group();
        $this->actingAs($u);
        $body = ['title' => '我的資源', 'category' => '合成', 'group_ids' => [$own->id], 'file' => UploadedFile::fake()->createWithContent('demo.txt', '合成資料')];
        $this->post('/api/v1/resources', $body, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('group_names.0', $own->name);
        $body['file'] = UploadedFile::fake()->createWithContent('demo.txt', '合成資料');
        $body['group_ids'] = [$other->id];
        $this->post('/api/v1/resources', $body, ['Accept' => 'application/json'])->assertForbidden();
        $body['group_ids'] = [$own->id];
        $body['role_ids'] = [$u->roles()->first()->id];
        $this->post('/api/v1/resources', $body, ['Accept' => 'application/json'])->assertForbidden();
    }
}
