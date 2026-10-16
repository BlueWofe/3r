<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\Group;
use App\Models\User;
use App\Services\GroupAudience;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GroupController extends ApiController
{
    public function memberOptions(Request $r): array
    {
        $this->permit($r, 'groups.manage.all');

        return ['data' => User::orderBy('name')->get(['id', 'name', 'active'])];
    }

    public function options(Request $r): array
    {
        $all = collect(['groups.manage.all', 'content.create.all', 'content.update.all', 'meetings.create.all', 'meetings.update.all', 'resources.create.all', 'resources.update.all'])->contains(fn ($p) => $r->user()->canDo($p));

        return ['data' => Group::where('active', true)->when(! $all, fn ($q) => $q->whereIn('id', app(GroupAudience::class)->membershipIds($r->user())))->orderBy('name')->get(['id', 'name'])];
    }

    public function groups(Request $r, ?int $id = null)
    {
        $this->permit($r, 'groups.manage.all');
        if ($r->isMethod('get')) {
            return $id ? Group::findOrFail($id)->publicData() : ['data' => Group::orderBy('id')->get()->map->publicData()];
        }

        return DB::transaction(function () use ($r, $id) {
            User::orderBy('id')->lockForUpdate()->get();
            $r->user()->refresh()->unsetRelation('roles');
            $this->permit($r, 'groups.manage.all');
            $group = $id ? Group::lockForUpdate()->findOrFail($id) : new Group;
            $v = $r->validate(['name' => ['required', 'string', 'max:100', Rule::unique('groups')->ignore($id)], 'description' => 'nullable|string|max:5000', 'active' => 'required|boolean', 'member_ids' => 'present|array|max:500', 'member_ids.*' => 'integer|exists:users,id', 'version' => $id ? 'required|integer|min:1' : 'sometimes|integer|min:1']);
            if ($id) {
                abort_unless($group->version === (int) $v['version'], 409, '小組版本已更新。');
            }
            $before = $id ? $group->publicData() : [];
            $ids = array_values(array_unique(array_map('intval', $v['member_ids'])));
            $old = $id ? $group->members()->pluck('users.id')->all() : [];
            if (User::whereIn('id', array_diff($ids, $old))->where('active', false)->exists()) {
                throw ValidationException::withMessages(['member_ids' => '只能新增啟用的人員。']);
            }
            $group->fill(collect($v)->except(['version', 'member_ids'])->all());
            if ($id) {
                $group->version++;
            }$group->save();
            $group->members()->sync($ids);
            Entity::create(['type' => 'audit', 'owner_id' => $r->user()->id, 'data' => ['module' => 'groups', 'subject_id' => $group->id, 'action' => $id ? 'update' : 'create', 'before' => $before, 'after' => $group->publicData()]]);

            return $group->publicData();
        });
    }
}
