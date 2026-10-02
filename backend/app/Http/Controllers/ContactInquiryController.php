<?php

namespace App\Http\Controllers;

use App\Models\ContactInquiry;
use App\Models\Entity;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ContactInquiryController extends ApiController
{
    public const CATEGORIES = ['監所探訪與代禱', '更生安置與職訓', '食品採購與禮盒', '志工加入', '奉獻與收據諮詢', '其他諮詢', '大宗認購專案', '試吃'];

    public function submit(Request $r)
    {
        $v = $r->validate(['name' => 'required|string|max:100', 'phone' => 'required|string|max:50', 'email' => 'nullable|email|max:254', 'category' => ['required', Rule::in(self::CATEGORIES)], 'message' => 'required|string|max:10000', 'submission_token' => 'required|uuid', 'website' => 'nullable|string|max:500']);
        abort_if($r->filled('website'), 422, '無法受理此訊息。');
        $v['submission_token'] = strtolower($v['submission_token']);
        $data = [];
        foreach (['name', 'phone', 'email', 'category', 'message'] as $key) {
            $data[$key] = $v[$key] ?? null;
        }
        $hash = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        try {
            $item = Cache::lock('contact-payload:'.$hash, 15)->block(3, fn () => DB::transaction(function () use ($v, $data, $hash) {
                $old = $this->forToken($v['submission_token']);
                if ($old) {
                    return $old;
                }
                $item = ContactInquiry::where('payload_hash', $hash)->where('created_at', '>=', now()->subMinutes(10))->orderByDesc('id')->first();
                if (! $item) {
                    $item = ContactInquiry::create($data + ['submission_token' => $v['submission_token'], 'reference' => (string) Str::uuid(), 'payload_hash' => $hash]);
                    foreach (User::where('active', true)->with('roles')->get() as $user) {
                        if ($user->canDo('contacts.read.all')) {
                            Entity::create(['type' => 'notifications', 'owner_id' => $user->id, 'data' => ['title' => '新的聯絡訊息', 'message' => '收到新的聯絡表單，請查看並處理。', 'contact_inquiry_id' => $item->id, 'url' => '/app/admin/contact-inquiries?id='.$item->id, 'read' => false]]);
                        }
                    }
                }
                DB::table('contact_submission_tokens')->insert(['token' => $v['submission_token'], 'contact_inquiry_id' => $item->id, 'created_at' => now()]);

                return $item;
            }));
        } catch (UniqueConstraintViolationException) {
            $item = $this->forToken($v['submission_token']);
            abort_unless($item, 409, '此送出代碼已使用。');
        } catch (LockTimeoutException) {
            abort(429, '請稍候再試。');
        }
        abort_unless(hash_equals($item->payload_hash, $hash), 409, '此送出代碼已用於其他訊息。');

        return ['message' => '已收到您的訊息', 'reference' => $item->reference];
    }

    private function forToken(string $token): ?ContactInquiry
    {
        $id = DB::table('contact_submission_tokens')->where('token', $token)->value('contact_inquiry_id');

        return $id ? ContactInquiry::find($id) : ContactInquiry::where('submission_token', $token)->first();
    }

    public function inquiries(Request $r, ?int $id = null)
    {
        $this->permit($r, $r->isMethod('get') ? 'contacts.read.all' : 'contacts.update.all');
        if ($r->isMethod('get')) {
            $v = $r->validate(['category' => ['nullable', Rule::in(self::CATEGORIES)], 'status' => 'nullable|in:new,processing,closed', 'q' => 'nullable|string|max:200']);
            $query = ContactInquiry::with('handler');
            foreach (['category', 'status'] as $key) {
                if (! empty($v[$key])) {
                    $query->where($key, $v[$key]);
                }
            }
            if (! empty($v['q'])) {
                $q = $v['q'];
                $query->where(function ($b) use ($q) {
                    foreach (['name', 'phone', 'email', 'message'] as $key) {
                        $b->orWhere($key, 'like', '%'.$q.'%');
                    }
                });
            }

            return ['data' => $query->orderByDesc('id')->get()->map->payload()];
        }
        $v = $r->validate(['version' => 'required|integer|min:1', 'status' => 'required|in:new,processing,closed', 'staff_note' => 'nullable|string|max:10000']);

        return DB::transaction(function () use ($r, $id, $v) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $r->user()->refresh()->unsetRelation('roles');
            $this->permit($r, 'contacts.update.all');
            $item = ContactInquiry::lockForUpdate()->findOrFail($id);
            abort_unless($item->version === (int) $v['version'], 409, '訊息版本已更新。');
            $before = $item->only(['status', 'staff_note', 'version', 'handled_by']);
            $item->fill(collect($v)->except('version')->all());
            $item->handled_by = $r->user()->id;
            $item->version++;
            $item->save();
            Entity::create(['type' => 'audit', 'owner_id' => $r->user()->id, 'data' => ['module' => 'contacts', 'subject_id' => $id, 'action' => 'update', 'before' => $before, 'after' => $item->only(['status', 'staff_note', 'version', 'handled_by'])]]);

            return $item->payload();
        });
    }
}
