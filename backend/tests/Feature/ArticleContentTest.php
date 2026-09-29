<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArticleContentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::create(['phone' => '0912345678', 'name' => '文章編輯', 'email' => 'editor@example.test', 'password' => 'Test-only-password']);
        $u->roles()->attach(Role::create(['name' => '管理員', 'slug' => 'system-admin', 'active' => true, 'permissions' => []]));

        return $u;
    }

    private function article(array $data = []): array
    {
        return array_merge(['kind' => 'news', 'title' => '見證故事', 'slug' => 'story', 'body' => '故事內文', 'status' => 'published', 'category' => '見證分享'], $data);
    }

    private function entity(array $data = []): Entity
    {
        return Entity::create(['type' => 'contents', 'data' => $this->article($data)]);
    }

    public function test_html_sanitized_on_write_and_read_and_search_uses_visible_text(): void
    {
        $this->actingAs($this->admin());
        $html = '<h2 style="color:red" onclick="evil()">故事</h2><p>喜樂 &amp; 希望 <strong>新生</strong><a href="javascript:alert(1)">危險</a><a href="https://example.com" title="連結">安全</a></p><a href="java&#x73;cript:alert(1)">encoded</a><svg onload="evil()"><text>svgword</text></svg><style>styleword</style><script>secretword</script><iframe src="https://evil.test">iframeword</iframe><img src="https://evil.test/x.png" onerror="evil()">';
        $id = $this->postJson('/api/v1/contents', $this->article(['body_format' => 'html', 'body' => $html, 'body_html' => 'CLIENT OVERRIDE']))->assertOk()->json('id');
        $data = $this->getJson('/api/v1/public/news/'.$id)->assertOk()->json('data');
        $this->assertStringContainsString('<strong>新生</strong>', $data['body_html']);
        foreach (['script', 'iframe', 'style=', 'onclick', 'onerror', 'javascript', 'evil.test', 'secretword', 'CLIENT OVERRIDE'] as $bad) {
            $this->assertStringNotContainsString($bad, $data['body_html']);
        }
        $this->getJson('/api/v1/public/search?q='.urlencode('喜樂 & 希望'))->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/public/search?q=secretword')->assertJsonCount(0, 'data');
        Entity::find($id)->update(['data' => $this->article(['body_format' => 'html', 'body' => '<p onclick="evil()">legacy</p><script>bad</script>'])]);
        $this->getJson('/api/v1/public/news/'.$id)->assertJsonPath('data.body_html', '<p>legacy</p>');
    }

    public function test_only_existing_public_image_files_can_be_embedded_and_revocation_is_respected(): void
    {
        Storage::fake();
        $path = 'files/safe.png';
        Storage::put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jL1sAAAAASUVORK5CYII='));
        $file = Entity::create(['type' => 'files', 'data' => ['path' => $path, 'name' => 'safe.png', 'visibility' => 'public']]);
        $article = $this->entity(['body_format' => 'html', 'body' => '<p>照片</p><img src="/api/v1/files/'.$file->id.'/download" alt="照片"><img src="/api/v1/files/999/download">']);
        $this->getJson('/api/v1/public/news/'.$article->id)->assertOk()->assertJsonPath('data.body_html', '<p>照片</p><img src="/api/v1/files/'.$file->id.'/download" alt="照片" />');
        $file->update(['data' => array_merge($file->data, ['visibility' => 'private'])]);
        $this->getJson('/api/v1/public/news/'.$article->id)->assertJsonPath('data.body_html', '<p>照片</p>');
        $this->actingAs($this->admin());
        $this->postJson('/api/v1/contents', $this->article(['slug' => 'image-only', 'body_format' => 'html', 'body' => '<img src="/api/v1/files/'.$file->id.'/download">']))->assertUnprocessable();
        $file->update(['data' => array_merge($file->data, ['visibility' => 'public'])]);
        $this->postJson('/api/v1/contents', $this->article(['slug' => 'image-only', 'body_format' => 'html', 'body' => '<img src="/api/v1/files/'.$file->id.'/download">']))->assertOk();
    }

    public function test_legacy_text_is_escaped_and_has_author_and_created_date_fallback(): void
    {
        $e = $this->entity(['body' => "<script>alert('x')</script>\n第二段"]);
        $data = $this->getJson('/api/v1/public/news/'.$e->id)->assertOk()->json('data');
        $this->assertSame('text', $data['body_format']);
        $this->assertSame('協會編輯部', $data['author_name']);
        $this->assertStringContainsString('&lt;script&gt;', $data['body_html']);
        $this->assertStringContainsString('<br>第二段', $data['body_html']);
        $this->assertSame($e->created_at->setTimezone('Asia/Taipei')->toIso8601String(), $data['published_at']);
    }

    public function test_author_and_first_publication_default_and_edits_preserve_date(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 29)->setTime(12, 0));
        $this->actingAs($this->admin());
        $id = $this->postJson('/api/v1/contents', $this->article())->assertOk()->assertJsonPath('author_name', '文章編輯')->json('id');
        $initial = Entity::find($id)->data['published_at'];
        $this->travel(1)->days();
        $this->putJson('/api/v1/contents/'.$id, $this->article(['title' => '改標題']))->assertOk()->assertJsonPath('published_at', $initial);
        $this->putJson('/api/v1/contents/'.$id, $this->article(['published_at' => null, 'author_name' => '']))->assertOk()->assertJsonPath('published_at', $initial)->assertJsonPath('author_name', '文章編輯');
        $this->putJson('/api/v1/contents/'.$id, $this->article(['published_at' => '2026-09-20T00:00:00Z', 'author_name' => '指定作者']))->assertOk()->assertJsonPath('published_at', '2026-09-20T08:00:00+08:00')->assertJsonPath('author_name', '指定作者');
        $this->travelBack();
    }

    public function test_news_latest_three_order_and_future_draft_hidden_everywhere(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 29)->setTime(12, 0));
        $ids = [];
        foreach ([20, 21, 22, 22] as $i => $day) {
            $ids[] = $this->entity(['slug' => 'story'.$i, 'published_at' => '2026-09-'.$day.'T08:00:00+08:00'])->id;
        }
        $future = $this->entity(['slug' => 'future', 'published_at' => '2030-01-01T08:00:00+08:00']);
        $draft = $this->entity(['slug' => 'draft', 'status' => 'draft']);
        $this->getJson('/api/v1/public/news?limit=3')->assertJsonCount(3, 'data')->assertJsonPath('data.0.id', $ids[3])->assertJsonPath('data.1.id', $ids[2])->assertJsonPath('data.2.id', $ids[1]);
        foreach ([$future, $draft] as $hidden) {
            $this->getJson('/api/v1/public/news/'.$hidden->id)->assertNotFound();
        }
        $page = $this->entity(['kind' => 'page', 'slug' => 'future-page', 'published_at' => '2030-01-01T08:00:00+08:00']);
        $this->getJson('/api/v1/public/pages/future-page')->assertNotFound();
        $this->getJson('/api/v1/public/search?q='.urlencode('見證故事'))->assertJsonCount(4, 'data');
        foreach ([0, 101, 'abc'] as $limit) {
            $this->getJson('/api/v1/public/news?limit='.$limit)->assertUnprocessable();
        }
        $this->travelBack();
    }

    public function test_empty_html_and_invalid_article_fields_rejected(): void
    {
        $this->actingAs($this->admin());
        foreach (['<p></p>', '<p>&nbsp; </p>', '<script>alert(1)</script>', '<img alt="src=pretend">'] as $body) {
            $this->postJson('/api/v1/contents', $this->article(['body_format' => 'html', 'body' => $body]))->assertUnprocessable()->assertJsonValidationErrors('body');
        }
        $this->postJson('/api/v1/contents', $this->article(['author_name' => str_repeat('x', 101), 'published_at' => 'invalid', 'body_format' => 'xml']))->assertUnprocessable()->assertJsonValidationErrors(['author_name', 'published_at', 'body_format']);
        $this->postJson('/api/v1/contents', $this->article(['published_at' => '2026-09-29']))->assertUnprocessable()->assertJsonValidationErrors('published_at');
    }
}
