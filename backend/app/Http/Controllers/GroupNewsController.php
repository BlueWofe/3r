<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\Group;
use App\Models\User;
use App\Services\ArticleContent;
use App\Services\GroupAudience;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupNewsController extends ApiController
{
    public function news(Request $r, ?int $id = null): array
    {
        $articles = Entity::where('type', 'contents')->get()->filter(fn ($e) => ($e->data['kind'] ?? '') === 'news' && ($e->data['visibility'] ?? 'public') === 'groups' && app(ArticleContent::class)->visible($e) && ($r->user()->canDo('content.read.all') || app(GroupAudience::class)->matches($r->user(), $e->data['group_ids'] ?? [])));
        if ($id) {
            $e = $articles->firstWhere('id', $id);
            abort_unless($e, 404);

            return ['data' => app(ArticleContent::class)->payload($e)];
        }

        return ['data' => $articles->sort(fn ($a, $b) => [app(ArticleContent::class)->date($b)->timestamp, $b->id] <=> [app(ArticleContent::class)->date($a)->timestamp, $a->id])->map(fn ($e) => app(ArticleContent::class)->payload($e))->values()];
    }

    private function payload($record, bool $duplicate = false): array
    {
        return ['id' => $record->id, 'content_id' => $record->content_id, 'version' => $record->version, 'recipient_count' => $record->recipient_count, 'sent_at' => Carbon::parse($record->sent_at)->toIso8601String(), 'duplicate' => $duplicate, 'line_mode' => 'mock'];
    }

    public function broadcasts(Request $r, int $id)
    {
        $this->permit($r, 'content.publish.all');
        $this->permit($r, 'groups.broadcast.all');
        Entity::where('type', 'contents')->findOrFail($id);
        if ($r->isMethod('get')) {
            return ['data' => DB::table('content_broadcasts')->where('content_id', $id)->orderByDesc('id')->get()->map(fn ($b) => $this->payload($b))];
        }
        $v = $r->validate(['version' => 'required|integer|min:1']);

        return DB::transaction(function () use ($r, $id, $v) {
            User::orderBy('id')->lockForUpdate()->get();
            $selection = Entity::where('type', 'contents')->findOrFail($id);
            Group::whereIn('id', $selection->data['group_ids'] ?? [])->orderBy('id')->lockForUpdate()->get();
            $r->user()->refresh()->unsetRelation('roles');
            $this->permit($r, 'content.publish.all');
            $this->permit($r, 'groups.broadcast.all');
            $e = Entity::where('type', 'contents')->lockForUpdate()->findOrFail($id);
            abort_unless(($e->data['version'] ?? 1) === (int) $v['version'], 409, '文章版本已更新。');
            $existing = DB::table('content_broadcasts')->where('content_id', $id)->where('version', $v['version'])->first();
            if ($existing) {
                return $this->payload($existing, true);
            }
            abort_unless(($e->data['kind'] ?? '') === 'news' && ($e->data['visibility'] ?? 'public') === 'groups' && app(ArticleContent::class)->visible($e), 422, '只能群發已到發布時間的小組消息。');
            $recipients = DB::table('group_user')->join('groups', 'groups.id', '=', 'group_user.group_id')->join('users', 'users.id', '=', 'group_user.user_id')->whereIn('groups.id', $e->data['group_ids'] ?? [])->where('groups.active', true)->where('users.active', true)->distinct()->pluck('users.id');
            abort_if($recipients->isEmpty(), 422, '沒有有效的小組收件人。');
            $recordId = DB::table('content_broadcasts')->insertGetId(['content_id' => $id, 'version' => $v['version'], 'recipient_count' => $recipients->count(), 'sent_at' => now(), 'actor_id' => $r->user()->id]);
            foreach ($recipients as $recipient) {
                $message = ['title' => '新的小組消息', 'message' => '新的小組消息', 'content_id' => $id, 'url' => '/app/group-news/'.$id, 'read' => false, 'broadcast_id' => $recordId];
                Entity::create(['type' => 'notifications', 'owner_id' => $recipient, 'data' => $message]);
                Entity::create(['type' => 'line-outbox', 'owner_id' => $recipient, 'data' => ['mode' => 'mock', 'message' => '新的小組消息', 'content_id' => $id, 'url' => '/app/group-news/'.$id, 'broadcast_id' => $recordId, 'delivered' => false]]);
            }
            Entity::create(['type' => 'audit', 'owner_id' => $r->user()->id, 'data' => ['module' => 'contents', 'subject_id' => $id, 'action' => 'broadcast', 'version' => $v['version'], 'broadcast_id' => $recordId, 'recipient_count' => $recipients->count()]]);

            return $this->payload(DB::table('content_broadcasts')->find($recordId));
        });
    }
}
