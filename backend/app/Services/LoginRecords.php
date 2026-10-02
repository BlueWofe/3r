<?php

namespace App\Services;

use App\Models\Entity;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LoginRecords
{
    public function record(Request $request, ?User $user, string $result): void
    {
        $roles = $user?->roles()->where('roles.active', true)->get(['roles.id', 'roles.name', 'roles.slug', 'roles.permissions']) ?? collect();
        $snapshot = $roles->map(fn ($role) => $role->only(['id', 'name', 'slug']))->values()->all();
        $categories = [];
        if ($roles->contains('slug', 'teacher') || $roles->contains('slug', 'volunteer')) {
            $categories[] = 'volunteer';
        }
        if ($roles->contains('slug', 'system-admin') || $roles->contains(fn ($role) => collect($role->permissions ?? [])->contains(fn ($permission) => str_ends_with($permission, '.all')))) {
            $categories[] = 'admin';
        }
        if ($roles->contains('slug', 'member') || ! $categories) {
            $categories[] = 'member';
        }

        Entity::create(['type' => 'login-records', 'owner_id' => $user?->id, 'data' => [
            'name' => $user?->name ?? '未識別帳號',
            'result' => $result,
            'ip_address' => mb_substr((string) $request->ip(), 0, 45),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            'roles' => $snapshot,
            'role_ids' => $roles->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'categories' => array_values(array_unique($categories)),
        ]]);
        Cache::forget('login-record-role-snapshots');
    }

    public function list(Request $request): array
    {
        abort_unless($request->user()?->canDo('users.read.all') || $request->user()?->canDo('roles.manage.all'), 403);
        $filters = $request->validate([
            'category' => 'sometimes|in:member,volunteer,admin',
            'role_id' => 'sometimes|integer|min:1',
            'from' => 'sometimes|date_format:Y-m-d',
            'to' => isset($request->from) ? 'sometimes|date_format:Y-m-d|after_or_equal:from' : 'sometimes|date_format:Y-m-d',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        $query = Entity::where('type', 'login-records');
        if (isset($filters['category'])) {
            $query->whereJsonContains('data->categories', $filters['category']);
        }
        if (isset($filters['role_id'])) {
            $query->whereJsonContains('data->role_ids', (int) $filters['role_id']);
        }
        if (isset($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        $page = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 20));
        $options = Role::query()->orderBy('name')->get(['id', 'name'])->mapWithKeys(fn ($role) => [$role->id => ['id' => $role->id, 'name' => $role->name]]);
        $historical = Cache::remember('login-record-role-snapshots', now()->addMinutes(5), function () {
            $roles = [];
            foreach (Entity::where('type', 'login-records')->cursor() as $record) {
                foreach ($record->data['roles'] ?? [] as $role) {
                    if (isset($role['id'], $role['name'])) {
                        $roles[(int) $role['id']] = ['id' => (int) $role['id'], 'name' => $role['name']];
                    }
                }
            }

            return $roles;
        });
        foreach ($historical as $id => $role) {
            if (! $options->has($id)) {
                $options->put($id, $role);
            }
        }

        return [
            'data' => $page->getCollection()->map(fn ($record) => [
                'id' => $record->id,
                'user_id' => $record->owner_id,
                'name' => $record->data['name'] ?? '未識別帳號',
                'result' => $record->data['result'] ?? 'failure',
                'occurred_at' => $record->created_at?->toIso8601String(),
                'ip_address' => $record->data['ip_address'] ?? '',
                'user_agent' => $record->data['user_agent'] ?? '',
                'roles' => $record->data['roles'] ?? [],
                'categories' => $record->data['categories'] ?? [],
            ])->values(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()],
            'role_options' => $options->values()->sortBy('name')->values(),
        ];
    }
}
