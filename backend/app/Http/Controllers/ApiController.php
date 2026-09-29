<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ApiController extends Controller
{
    public static function permissionNames(): array
    {
        $modules = ['schedule' => ['read.own', 'read.all', 'update.own', 'update.all', 'create.all'], 'attendance' => ['create.own', 'update.all'], 'content' => ['read.all', 'create.all', 'update.all', 'delete.all', 'publish.all'], 'users' => ['read.all', 'update.all', 'create.all'], 'roles' => ['manage.all'], 'cases' => ['read.assigned', 'read.all', 'create.all', 'update.assigned', 'update.all', 'export.all'], 'resources' => ['read.own', 'read.all', 'create.own', 'create.all', 'update.all', 'delete.all'], 'meetings' => ['read.own', 'read.all', 'create.all', 'update.all'], 'forms' => ['read.own', 'read.all', 'create.all', 'update.all', 'export.all'], 'donations' => ['read.own', 'read.all'], 'reports' => ['read.own', 'read.all'], 'settings' => ['manage.all']];
        $out = [];
        foreach ($modules as $m => $actions) {
            foreach ($actions as $a) {
                $out[] = "$m.$a";
            }
        }

        return $out;
    }

    protected function permit(Request $r, string $p): void
    {
        abort_unless($r->user()?->canDo($p), 403);
    }

    protected function me(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'phone' => $u->phone, 'roles' => $u->roles->where('active', true)->values(), 'permissions' => $u->roles->where('active', true)->contains('slug', 'system-admin') ? self::permissionNames() : $u->permissions()];
    }

    public function auth(Request $r, string $action)
    {
        if ($action === 'csrf') {
            return ['csrf_token' => csrf_token()];
        }
        if ($action === 'login') {
            $v = $r->validate(['phone' => 'required|regex:/^09[0-9]{8}$/', 'password' => 'required|string']);
            $key = 'login:'.hash('sha256', $v['phone'].'|'.$r->ip());
            abort_if(RateLimiter::tooManyAttempts($key, 5), 429, '登入失敗次數過多，請稍後重試');
            if (! Auth::attempt($v + ['active' => true])) {
                RateLimiter::hit($key, 60);
                abort(422, '手機或密碼錯誤');
            }
            RateLimiter::clear($key);
            $r->session()->regenerate();

            return ['user' => $this->me($r->user())];
        }
        if ($action === 'otp') {
            return $this->otp($r);
        }
        if (in_array($action, ['register', 'reset-password'])) {
            $v = $r->validate(['phone' => 'required|regex:/^09[0-9]{8}$/', 'code' => 'required|string', 'password' => 'required|confirmed|min:10', 'name' => ($action === 'register' ? 'required' : 'nullable').'|string|max:100']);
            $this->consumeOtp($v['phone'], $action === 'register' ? 'register' : 'reset', $v['code']);
            if ($action === 'register') {
                abort_if(User::where('phone', $v['phone'])->exists(), 422, '手機已註冊');
                $u = User::create(['phone' => $v['phone'], 'email' => $v['phone'].'@demo.invalid', 'name' => $v['name'], 'password' => $v['password']]);
                $role = Role::where('slug', 'member')->first();
                if ($role) {
                    $u->roles()->attach($role);
                }
            } else {
                $u = User::where('phone', $v['phone'])->firstOrFail();
                $u->update(['password' => $v['password']]);
                DB::table('sessions')->where('user_id', $u->id)->delete();
            }

            return ['message' => '完成'];
        }
        abort_unless($r->user()?->active, 401);
        if ($action === 'logout') {
            Auth::logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();

            return ['message' => '已登出'];
        }
        if ($action === 'profile') {
            $r->user()->update($r->validate(['name' => 'required|string|max:100']));
        }
        if ($action === 'change-phone') {
            $v = $r->validate(['phone' => 'required|regex:/^09[0-9]{8}$/|unique:users,phone', 'code' => 'required|string']);
            $this->consumeOtp($v['phone'], 'change_phone', $v['code']);
            $r->user()->update(['phone' => $v['phone']]);
        }

        return ['user' => $this->me($r->user()->fresh())];
    }

    private function otp(Request $r): array
    {
        $v = $r->validate(['phone' => 'required|regex:/^09[0-9]{8}$/', 'purpose' => 'required|in:register,reset,change_phone']);
        $lock = Cache::lock('otp:'.hash('sha256', $v['phone']), 15);
        abort_unless($lock->get(), 429, '請稍後重試');
        try {
            return $this->sendOtp($r, $v);
        } finally {
            $lock->release();
        }
    }

    private function sendOtp(Request $r, array $v): array
    {
        if ($v['purpose'] === 'change_phone') {
            abort_unless($r->user(), 401);
        }
        abort_if(DB::table('otps')->where('phone', $v['phone'])->where('created_at', '>', now()->subMinute())->exists(), 429, '請稍後重試');
        $code = (string) random_int(100000, 999999);
        $allow = explode(',', config('ministry.otp_test_phones', ''));
        if (config('ministry.sms_driver') === 'mock') {
            abort_unless(in_array($v['phone'], $allow, true), 422, '測試手機未列入允許清單');
            Storage::disk('local')->put('otp-mailbox/'.$v['phone'].'.json', json_encode(['code' => $code, 'purpose' => $v['purpose']]));
        } else {
            abort_unless(config('ministry.sms_driver') === 'mitake', 503);
            abort_unless(config('ministry.mitake_username') && config('ministry.mitake_password'), 503, '簡訊服務尚未設定');
            try {
                $response = Http::asForm()->timeout(10)->post('https://smsapi.mitake.com.tw/api/mtk/SmSend?CharsetURL=UTF8', ['username' => config('ministry.mitake_username'), 'password' => config('ministry.mitake_password'), 'dstaddr' => $v['phone'], 'smbody' => '驗證碼 '.$code]);
            } catch (\Throwable) {
                abort(503, '簡訊服務暫時無法使用');
            }
            preg_match('/(?:^|\r?\n)statuscode=([^\r\n]+)/', $response->body(), $matches);
            abort_unless($response->successful() && in_array(trim($matches[1] ?? ''), ['0', '1', '2', '4'], true), 503, '簡訊服務未接受請求');
        }
        DB::table('otps')->insert($v + ['hash' => Hash::make($code), 'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now()]);

        return ['message' => '驗證碼已發送'];
    }

    private function consumeOtp(string $phone, string $purpose, string $code): void
    {
        $valid = DB::transaction(function () use ($phone, $purpose, $code) {
            $o = DB::table('otps')->where(compact('phone', 'purpose'))->whereNull('consumed_at')->latest('id')->lockForUpdate()->first();
            if (! $o || $o->attempts >= 5 || now()->gt($o->expires_at)) {
                return false;
            }DB::table('otps')->where('id', $o->id)->increment('attempts');
            if (! Hash::check($code, $o->hash)) {
                return false;
            }DB::table('otps')->where('id', $o->id)->update(['consumed_at' => now()]);

            return true;
        });
        abort_unless($valid, 422, '驗證碼無效或過期');
    }

    public function administration(Request $r, string $module, ?int $id = null)
    {
        $this->permit($r, $module === 'roles' ? 'roles.manage.all' : 'users.'.($r->isMethod('get') ? 'read' : ($id ? 'update' : 'create')).'.all');
        if ($r->isMethod('get')) {
            return ['data' => ($module === 'roles' ? Role::all() : User::with('roles')->get())];
        }

        return DB::transaction(function () use ($r, $module, $id) {
            // Serialize all administrator membership changes to protect the final active admin.
            $roles = Role::orderBy('id')->lockForUpdate()->get();
            User::orderBy('id')->lockForUpdate()->get();
            $r->user()->refresh()->unsetRelation('roles');
            $this->permit($r, $module === 'roles' ? 'roles.manage.all' : 'users.'.($id ? 'update' : 'create').'.all');
            if ($module === 'roles') {
                $v = $r->validate(['name' => 'required|string|max:100', 'slug' => ['required', 'string', Rule::unique('roles')->ignore($id)], 'active' => 'required|boolean', 'permissions' => 'present|array', 'permissions.*' => Rule::in(self::permissionNames())]);
                $x = $id ? Role::findOrFail($id) : new Role;
                $before = $x->toArray();
                $x->fill($v)->save();
            } else {
                $v = $r->validate(['name' => 'required|string|max:100', 'active' => 'required|boolean', 'role_ids' => 'present|array', 'role_ids.*' => 'exists:roles,id', 'phone' => ($id ? 'sometimes' : 'required').'|regex:/^09[0-9]{8}$/', 'password' => ($id ? 'sometimes' : 'required').'|min:10']);
                $x = $id ? User::findOrFail($id) : new User;
                if ($id && ($r->has('phone') || $r->has('password'))) {
                    $this->permit($r, 'roles.manage.all');
                }
                $before = $id ? $x->load('roles')->toArray() : [];
                $currentRoles = $id ? $x->roles->pluck('id')->map(fn ($n) => (int) $n)->sort()->values()->all() : [];
                $requestedRoles = collect($v['role_ids'])->map(fn ($n) => (int) $n)->unique()->sort()->values()->all();
                if ($currentRoles !== $requestedRoles) {
                    $this->permit($r, 'roles.manage.all');
                }
                $x->fill(collect($v)->except('role_ids')->all());
                if (! $id) {
                    $x->email = $v['phone'].'@demo.invalid';
                }$x->save();
                $x->roles()->sync($v['role_ids']);
            }
            abort_unless(User::where('active', true)->whereHas('roles', fn ($q) => $q->where('slug', 'system-admin')->where('active', true))->exists(), 409, '至少保留一位啟用的系統管理員');
            Entity::create(['type' => 'audit', 'owner_id' => $r->user()->id, 'data' => ['module' => $module, 'subject_id' => $x->id, 'before' => $before, 'after' => ($module === 'users' ? $x->fresh()->load('roles') : $x->fresh())->toArray()]]);
            if ($module === 'users') {
                DB::table('sessions')->where('user_id', $x->id)->delete();
            } else {
                DB::table('sessions')->whereIn('user_id', DB::table('role_user')->where('role_id', $x->id)->pluck('user_id'))->delete();
            }

            return $x->fresh();
        });
    }

    public function roleOptions(Request $r): array
    {
        $allowed = collect(['roles.manage.all', 'forms.create.all', 'forms.update.all', 'meetings.create.all', 'meetings.update.all', 'resources.create.all'])
            ->contains(fn ($permission) => $r->user()->canDo($permission));
        abort_unless($allowed, 403);

        return ['data' => Role::where('active', true)->orderBy('name')->get(['id', 'name'])];
    }

    public function publicContact(): array
    {
        $settings = Entity::where('type', 'settings')->first()?->data ?? [];
        $defaults = ['association_name' => '中華復甦更新發展協會', 'contact_phone' => '', 'contact_email' => '', 'address' => ''];

        foreach ($defaults as $key => $default) {
            $defaults[$key] = is_string($settings[$key] ?? null) ? $settings[$key] : $default;
        }

        return ['data' => $defaults];
    }

    protected function entity(Request $r, string $type, int $id, string $action = 'read'): Entity
    {
        $e = Entity::where('type', $type)->findOrFail($id);
        $module = $type === 'contents' ? 'content' : $type;
        $scope = $type === 'cases' ? 'assigned' : 'own';
        $own = $type === 'cases' ? ($e->data['assigned_user_id'] ?? null) == $r->user()->id : $e->owner_id === $r->user()->id;
        $roles = $e->data['role_ids'] ?? [];
        if (in_array($type, ['resources', 'meetings', 'forms'])) {
            $own = $own || (! $roles && $action === 'read') || $r->user()->roles->where('active', true)->pluck('id')->intersect($roles)->isNotEmpty();
        }
        if ($type === 'forms' && $action === 'read' && ($e->data['status'] ?? 'draft') !== 'published') {
            $own = false;
        }
        abort_unless($r->user()->canDo("$module.$action.all") || ($own && $r->user()->canDo("$module.$action.$scope")), 403);

        return $e;
    }

    public function generic(Request $r, string $type, ?int $id = null)
    {
        if ($r->isMethod('get')) {
            return $this->genericOperation($r, $type, $id);
        }

        return DB::transaction(fn () => $this->genericOperation($r, $type, $id));
    }

    public function exportCases(Request $r)
    {
        $this->permit($r, 'cases.export.all');
        $this->permit($r, 'cases.read.all');
        Entity::create(['type' => 'audit', 'owner_id' => $r->user()->id, 'data' => ['module' => 'cases', 'action' => 'export']]);
        $cases = Entity::where('type', 'cases')->get();

        return response()->streamDownload(function () use ($cases) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['代碼', '姓名', '狀態', '監所', '聯絡', '服務紀錄']);
            foreach ($cases as $e) {
                $d = $e->data;
                $row = [$d['code'] ?? '', $d['name'] ?? '', $d['status'] ?? '', $d['prison'] ?? '', $d['contact'] ?? '', json_encode($d['records'] ?? [], JSON_UNESCAPED_UNICODE)];
                $row = array_map(fn ($value) => preg_match('/^[=+@\-\t\r]/', (string) $value) ? "'".$value : $value, $row);
                fputcsv($out, $row);
            }fclose($out);
        }, 'cases.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function genericOperation(Request $r, string $type, ?int $id)
    {
        $permission = $type === 'contents' ? 'content' : $type;
        if ($r->isMethod('get')) {
            if ($id) {
                $e = $this->entity($r, $type, $id);
                if ($type === 'cases') {
                    Entity::create(['type' => 'audit', 'owner_id' => $r->user()->id, 'data' => ['module' => 'cases', 'subject_id' => $id, 'action' => 'read']]);
                }

                return $e->publicData();
            }
            abort_unless($r->user()->canDo("$permission.read.all") || $r->user()->canDo("$permission.read.own") || $r->user()->canDo("$permission.read.assigned"), 403);
            if ($type === 'cases') {
                Entity::create(['type' => 'audit', 'owner_id' => $r->user()->id, 'data' => ['module' => 'cases', 'action' => 'list']]);
            }

            return ['data' => Entity::where('type', $type)->get()->filter(function ($e) use ($r, $type) {
                try {
                    $this->entity($r, $type, $e->id);

                    return true;
                } catch (\Throwable) {
                    return false;
                }
            })->map->publicData()->values()];
        }
        if ($id) {
            $e = $this->entity($r, $type, $id, $r->isMethod('delete') ? 'delete' : 'update');
        } else {
            $this->permit($r, "$permission.create.all");
            $e = new Entity(['type' => $type, 'owner_id' => $r->user()->id, 'data' => []]);
        }
        if ($r->isMethod('delete')) {
            $e->delete();

            return response()->json(['message' => '已刪除']);
        }
        $rules = match ($type) {
            'contents' => ['kind' => 'required|in:page,news,product', 'title' => 'required|string|max:200', 'slug' => 'required|string|max:200', 'body' => 'required|string|max:100000', 'summary' => 'nullable|string|max:2000', 'category' => 'nullable|string|max:100', 'status' => 'required|in:draft,published', 'sort_order' => 'nullable|integer', 'metadata' => 'nullable|array', 'image_id' => 'nullable|integer'],
            'cases' => ['code' => 'required|string|max:100', 'name' => 'required|string|max:100', 'status' => 'required|string|max:100', 'prison' => 'nullable|string|max:100', 'contact' => 'nullable|string|max:500', 'assigned_user_id' => 'nullable|exists:users,id'],
            'meetings' => ['title' => 'required|string|max:200', 'meeting_date' => 'required|date', 'agenda' => 'nullable|string', 'minutes' => 'nullable|string', 'decisions' => 'nullable|string', 'role_ids' => 'array', 'role_ids.*' => 'exists:roles,id', 'file_ids' => 'array', 'file_ids.*' => 'integer'],
            'forms' => ['title' => 'required|string|max:200', 'description' => 'nullable|string', 'status' => 'required|in:draft,published', 'deadline' => 'nullable|date', 'role_ids' => 'array', 'role_ids.*' => 'exists:roles,id', 'fields' => 'required|array|max:100', 'fields.*.key' => 'required|alpha_dash|distinct', 'fields.*.label' => 'required|string', 'fields.*.type' => 'required|in:text,textarea,number,date,select,multiselect,file', 'fields.*.required' => 'required|boolean', 'fields.*.options' => 'nullable|array'],
            default => []
        };
        if ($id) {
            $e = Entity::where('type', $type)->lockForUpdate()->findOrFail($id);
        }
        $before = $e->data;
        $v = $r->validate($rules);
        if ($type === 'cases') {
            if ($id && ($v['assigned_user_id'] ?? null) !== ($before['assigned_user_id'] ?? null)) {
                $this->permit($r, 'cases.update.all');
            }
            $v['records'] = $before['records'] ?? [];
            Entity::create(['type' => 'audit', 'owner_id' => $r->user()->id, 'data' => ['module' => 'cases', 'subject_id' => $id, 'action' => $id ? 'update' : 'create', 'before' => $before, 'after' => $v]]);
        }
        if ($type === 'meetings') {
            foreach ($v['file_ids'] ?? [] as $fileId) {
                $file = Entity::where('type', 'files')->findOrFail($fileId);
                abort_unless($file->owner_id === $r->user()->id || ($file->data['visibility'] ?? 'private') === 'public', 403, '不可分享他人的私人檔案');
            }
        }
        if ($type === 'contents') {
            if ($v['status'] === 'published') {
                $this->permit($r, 'content.publish.all');
            }$v['body'] = strip_tags($v['body']);
            abort_if(Entity::where('type', 'contents')->where('id', '!=', $id ?? 0)->get()->contains(fn ($x) => ($x->data['slug'] ?? '') === $v['slug']), 422, '網址代稱重複');
        }
        if ($type === 'forms') {
            $old = $e->data;
            $v['version'] = ($old['version'] ?? 0) + 1;
            $v['snapshots'] = $old['snapshots'] ?? [];
            if ($v['status'] === 'published') {
                $v['snapshots'][] = ['version' => $v['version'], 'fields' => $v['fields'], 'published_at' => now()->toIso8601String()];
            }
        }
        $e->data = $v;
        $e->save();

        return $e->publicData();
    }

    public function publicContent(Request $r, string $kind, ?string $id = null)
    {
        $items = Entity::where('type', 'contents')->get()->filter(fn ($e) => ($e->data['status'] ?? '') === 'published' && ($kind === 'search' || ($e->data['kind'] ?? '') === ['pages' => 'page', 'news' => 'news', 'products' => 'product'][$kind]));
        if ($kind === 'search') {
            $items = $items->filter(fn ($e) => str_contains(mb_strtolower(implode(' ', [$e->data['title'], $e->data['body']])), mb_strtolower((string) $r->query('q'))));
        }
        if ($id) {
            $e = $items->first(fn ($e) => $kind === 'pages' ? ($e->data['slug'] ?? '') === $id : $e->id == (int) $id);
            abort_unless($e, 404);
            if ($kind === 'products' && (int) $r->session()->get('view.'.$id, 0) <= now()->subMinutes(30)->timestamp) {
                $e = DB::transaction(function () use ($e) {
                    $locked = Entity::lockForUpdate()->findOrFail($e->id);
                    $locked->update(['data' => array_merge($locked->data, ['views' => ($locked->data['views'] ?? 0) + 1])]);

                    return $locked;
                });
                $r->session()->put('view.'.$id, now()->timestamp);
            }

            return ['data' => $e->publicData()];
        }

        return ['data' => $items->sortBy(fn ($e) => $e->data['sort_order'] ?? 0)->map->publicData()->values()];
    }
}
