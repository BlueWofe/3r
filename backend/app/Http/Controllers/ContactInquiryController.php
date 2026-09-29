<?php

namespace App\Http\Controllers;

use App\Models\ContactInquiry;
use App\Models\Entity;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ContactInquiryController extends ApiController
{
    public const CATEGORIES = ['監所探訪與代禱', '更生安置與職訓', '食品採購與禮盒', '志工加入', '奉獻與收據諮詢', '其他諮詢'];

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
            $item = DB::transaction(function () use ($v, $data, $hash) {
                $old = ContactInquiry::where('submission_token', $v['submission_token'])->first();
                if ($old) {
                    return $old;
                }

                return ContactInquiry::create($data + ['submission_token' => $v['submission_token'], 'reference' => (string) Str::uuid(), 'payload_hash' => $hash]);
            });
        } catch (UniqueConstraintViolationException) {
            $item = ContactInquiry::where('submission_token', $v['submission_token'])->firstOrFail();
        }
        abort_unless(hash_equals($item->payload_hash, $hash), 409, '此送出代碼已用於其他訊息。');

        return ['message' => '已收到您的訊息', 'reference' => $item->reference];
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
