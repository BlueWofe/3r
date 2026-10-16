<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'version' => 'integer', 'subtotal_cents' => 'integer', 'shipping_fee_cents' => 'integer', 'total_cents' => 'integer'];
    }

    public function confirmation(): array
    {
        return ['order_number' => $this->order_number, 'status' => $this->status, 'total' => $this->total_cents / 100, 'currency' => 'TWD'];
    }

    public function payload(): array
    {
        return $this->only(['id', 'order_number', 'customer_name', 'customer_phone', 'address', 'delivery_method', 'items', 'status', 'version', 'staff_note', 'total_cents', 'created_at', 'updated_at']) + ['subtotal' => $this->subtotal_cents / 100, 'shipping_fee' => $this->shipping_fee_cents / 100, 'total' => $this->total_cents / 100, 'currency' => 'TWD'];
    }
}
