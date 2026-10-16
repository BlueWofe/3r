<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    protected $guarded = [];

    protected $attributes = ['active' => true, 'version' => 1, 'description' => null];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer'];
    }

    public function members()
    {
        return $this->belongsToMany(User::class);
    }

    public function publicData(): array
    {
        $members = $this->members()->orderBy('users.id')->get(['users.id', 'users.name', 'users.active'])->map(fn ($u) => $u->only(['id', 'name', 'active']));

        return $this->only(['id', 'name', 'description', 'active', 'version']) + ['member_ids' => $members->pluck('id')->all(), 'members' => $members, 'member_count' => $members->count()];
    }
}
