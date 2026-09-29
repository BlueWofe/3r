<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $attributes = ['active' => true];

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'active' => 'boolean'];
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    public function permissions(): array
    {
        return $this->roles->where('active', true)->pluck('permissions')->flatten()->unique()->values()->all();
    }

    public function canDo(string $permission): bool
    {
        return $this->active && ($this->roles->where('active', true)->contains('slug', 'system-admin') || in_array($permission, $this->permissions(), true));
    }
}
