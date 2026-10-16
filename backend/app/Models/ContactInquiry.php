<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactInquiry extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'new', 'version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function payload(): array
    {
        return $this->only(['id', 'name', 'phone', 'email', 'category', 'message', 'status', 'staff_note', 'version', 'created_at', 'updated_at']) + ['handled_by_name' => $this->handler?->name];
    }

    public function handler()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
