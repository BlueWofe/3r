<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\Prison;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PrisonController extends ApiController
{
    public function options(Request $r): array
    {
        $permissions = $r->user()->permissions();
        abort_unless($r->user()->canDo('prisons.manage.all') || collect($permissions)->contains(fn ($p) => str_starts_with($p, 'schedule.') || str_starts_with($p, 'cases.')), 403);

        return ['data' => Prison::orderBy('name')->get(['id', 'name', 'active'])];
    }

    public function prisons(Request $r, ?int $id = null)
    {
        $this->permit($r, 'prisons.manage.all');
        if ($r->isMethod('get')) {
            return ['data' => Prison::orderBy('name')->get()->map->publicData()];
        }

        return DB::transaction(function () use ($r, $id) {
            User::orderBy('id')->lockForUpdate()->get();
            $r->user()->refresh()->unsetRelation('roles');
            $this->permit($r, 'prisons.manage.all');
            $prison = $id ? Prison::lockForUpdate()->findOrFail($id) : new Prison;
            $v = $r->validate(['name' => ['required', 'string', 'max:100', Rule::unique('prisons')->ignore($id)], 'address' => 'nullable|string|max:500', 'active' => $id ? 'required|boolean' : 'sometimes|boolean', 'version' => $id ? 'required|integer|min:1' : 'sometimes|integer|min:1']);
            if ($id) {
                abort_unless($prison->version === (int) $v['version'], 409, '監所資料版本已更新。');
            }
            $before = $prison->exists ? $prison->publicData() : [];
            $prison->fill(collect($v)->except('version')->all());
            if ($id) {
                $prison->version++;
            }
            $prison->save();
            Entity::create(['type' => 'audit', 'owner_id' => $r->user()->id, 'data' => ['module' => 'prisons', 'subject_id' => $prison->id, 'action' => $id ? 'update' : 'create', 'before' => $before, 'after' => $prison->publicData()]]);

            return $prison->publicData();
        });
    }
}
