<?php

namespace App\Services;

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GroupAudience
{
    public function membershipIds(User $user): array
    {
        return $user->active ? DB::table('group_user')->join('groups', 'groups.id', '=', 'group_user.group_id')->where('user_id', $user->id)->where('groups.active', true)->pluck('group_id')->map(fn ($id) => (int) $id)->all() : [];
    }

    public function matches(User $user, array $ids): bool
    {
        return count(array_intersect($ids, $this->membershipIds($user))) > 0;
    }

    public function validate(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $count = Group::whereIn('id', $ids)->where('active', true)->count();
        if ($count !== count($ids)) {
            throw ValidationException::withMessages(['group_ids' => '請選擇有效的小組。']);
        }

        return $ids;
    }

    public function names(array $ids): array
    {
        return Group::whereIn('id', $ids)->orderBy('id')->pluck('name')->all();
    }
}
