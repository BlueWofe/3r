<?php

namespace App\Services;

use App\Models\ContactInquiry;
use App\Models\Entity;
use App\Models\Order;
use App\Models\ServiceSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class NotificationAccess
{
    public function payload(Entity $notification, User $user): ?array
    {
        if (! $user->active || $notification->owner_id !== $user->id) {
            return null;
        }
        $d = $notification->data;
        if (isset($d['order_id'])) {
            if (! $user->canDo('orders.read.all') || ! Order::whereKey($d['order_id'])->exists()) {
                return null;
            }
            $d['title'] = '新的商品訂單';
            $d['message'] = '收到新的商品訂單，請查看並處理。';
            $d['url'] = '/app/admin/orders?id='.(int) $d['order_id'];
            $d['category'] = 'product';
        } elseif (isset($d['contact_inquiry_id'])) {
            $inquiry = ContactInquiry::find($d['contact_inquiry_id']);
            if (! $user->canDo('contacts.read.all') || ! $inquiry) {
                return null;
            }
            $d['title'] = '新的聯絡訊息';
            $d['message'] = '收到新的聯絡表單，請查看並處理。';
            $d['url'] = '/app/admin/contact-inquiries?id='.(int) $d['contact_inquiry_id'];
            $d['category'] = in_array($inquiry->category, ['大宗認購專案', '試吃', '食品採購與禮盒'], true) ? 'product' : 'contact';
        } elseif (isset($d['content_id'])) {
            $article = Entity::where('type', 'contents')->find($d['content_id']);
            if (! $article || ($article->data['kind'] ?? '') !== 'news' || ($article->data['visibility'] ?? 'public') !== 'groups' || ! app(ArticleContent::class)->visible($article) || (! $user->canDo('content.read.all') && ! app(GroupAudience::class)->matches($user, $article->data['group_ids'] ?? []))) {
                return null;
            }
            $d['title'] = '新的小組消息';
            $d['message'] = '您有新的小組消息。';
            $d['url'] = '/app/group-news/'.$article->id;
            $d['category'] = 'message';
        } elseif (isset($d['session_id'])) {
            $session = ServiceSession::find($d['session_id']);
            if (! $session) {
                return null;
            }
            $systemAdmin = $user->roles->where('active', true)->contains('slug', 'system-admin');
            $all = $user->canDo('schedule.read.all');
            $own = $user->canDo('schedule.read.own');
            $invitation = ! empty($d['invitation']);
            if ($invitation && (! $user->canDo('schedule.update.own') || ($session->data['status'] ?? '') !== 'scheduled' || now('Asia/Taipei')->gte(Carbon::parse($session->data['service_date'].' '.$session->data['end_time'], 'Asia/Taipei')) || $session->assignments()->whereNotNull('attendance')->exists())) {
                return null;
            }
            $invitationQuery = DB::table('invitations')->join('assignments', 'assignments.id', '=', 'invitations.assignment_id')->where('assignments.session_id', $session->id)->where('invitations.teacher_id', $user->id)->where('invitations.status', 'pending');
            if ($invitation && isset($d['invitation_id'])) {
                $invitationQuery->where('invitations.id', $d['invitation_id']);
            }
            $pending = $invitation && $invitationQuery->exists();
            $related = $session->assignments()->where('teacher_id', $user->id)->exists();
            if ($invitation ? ! ($own && $pending) : ! ($systemAdmin || ($related && ($own || $all)))) {
                return null;
            }
            $d['url'] = $invitation ? '/app/invitations'.(isset($d['invitation_id']) ? '?invitation_id='.(int) $d['invitation_id'] : '') : ($all ? '/app/admin/schedule?session_id='.$session->id : '/app/calendar?session_id='.$session->id);
            $d['category'] = 'course';
        } else {
            return null;
        }
        $d['read'] = (bool) ($d['read'] ?? false);

        return array_merge($d, ['id' => $notification->id, 'owner_id' => $notification->owner_id, 'created_at' => $notification->created_at]);
    }
}
