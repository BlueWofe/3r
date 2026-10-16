<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\ProductCatalog;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends ApiController
{
    private function cart(Request $r): array
    {
        $v = $r->validate(['delivery_method' => 'required|in:shipping,pickup', 'items' => 'required|array|min:1|max:50', 'items.*.product_id' => 'required|integer|min:1', 'items.*.variant_id' => 'required|string|max:100', 'items.*.quantity' => 'required|integer|min:1|max:1000000']);
        // Merge duplicate lines before determining wholesale prices and checking stock.
        $items = [];
        foreach ($v['items'] as $item) {
            $key = $item['product_id'].':'.$item['variant_id'];
            $items[$key] ??= ['product_id' => (int) $item['product_id'], 'variant_id' => $item['variant_id'], 'quantity' => 0];
            $items[$key]['quantity'] += (int) $item['quantity'];
            if ($items[$key]['quantity'] > 1000000) {
                throw ValidationException::withMessages(['items' => '單一規格數量超出上限。']);
            }
        }
        ksort($items);

        return ['delivery_method' => $v['delivery_method'], 'items' => array_values($items)];
    }

    private function calculate(array $cart, bool $lock = false): array
    {
        $settingsQuery = DB::table('shipping_settings')->where('id', 1);
        if ($lock) {
            $settingsQuery->lockForUpdate();
        }
        $settings = json_decode($settingsQuery->firstOrFail()->data, true);
        if (! ($settings[$cart['delivery_method'].'_enabled'] ?? false)) {
            throw ValidationException::withMessages(['delivery_method' => '此取貨方式目前未開放。']);
        }
        $ids = array_unique(array_column($cart['items'], 'product_id'));
        $query = Entity::where('type', 'contents')->whereIn('id', $ids)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $products = $query->get()->keyBy('id');
        $catalog = app(ProductCatalog::class);
        $items = [];
        $subtotal = 0;
        foreach ($cart['items'] as $line) {
            $product = $products->get($line['product_id']);
            abort_unless($product && ($product->data['kind'] ?? '') === 'product' && ($product->data['visibility'] ?? 'public') === 'public', 422, '商品已下架。');
            $quote = $catalog->quote($product, $line['variant_id'], $line['quantity']);
            $variant = collect($product->data['metadata']['variants'])->firstWhere('id', $line['variant_id']);
            $unit = $catalog->cents($quote['unit_price']);
            $subtotal += $unit * $line['quantity'];
            $items[] = $line + ['title' => $product->data['title'], 'sku' => $variant['sku'], 'options' => $variant['options'], 'unit_price' => $unit / 100, 'total' => $unit * $line['quantity'] / 100, 'applied_min_quantity' => $quote['applied_min_quantity']];
        }
        $fee = 0;
        if ($cart['delivery_method'] === 'shipping' && (! isset($settings['free_shipping_threshold']) || $subtotal < $catalog->cents($settings['free_shipping_threshold']))) {
            $fee = $catalog->cents($settings['flat_fee']);
        }

        return ['items' => $items, 'subtotal' => $subtotal / 100, 'shipping_fee' => $fee / 100, 'total' => ($subtotal + $fee) / 100, 'total_cents' => $subtotal + $fee, 'currency' => 'TWD'];
    }

    public function quote(Request $r): array
    {
        return $this->calculate($this->cart($r));
    }

    public function submit(Request $r)
    {
        $cart = $this->cart($r);
        $v = $r->validate(['customer_name' => 'required|string|max:100', 'customer_phone' => 'required|string|max:50|regex:/^[0-9+()\s-]{6,50}$/', 'address' => 'required_if:delivery_method,shipping|nullable|string|max:500', 'idempotency_key' => 'required|uuid', 'expected_total_cents' => 'required|integer|min:0', 'website' => 'nullable|string|max:500']);
        abort_if($r->filled('website'), 422, '無法受理此訂單。');
        $key = strtolower($v['idempotency_key']);
        $data = $cart + ['customer_name' => trim($v['customer_name']), 'customer_phone' => trim($v['customer_phone']), 'address' => $cart['delivery_method'] === 'shipping' ? trim($v['address']) : null, 'expected_total_cents' => (int) $v['expected_total_cents']];
        $hash = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $created = false;
        try {
            $order = Cache::lock('guest-order:'.$key, 15)->block(3, function () use ($key, $hash, $data, $cart, &$created) {
                return DB::transaction(function () use ($key, $hash, $data, $cart, &$created) {
                    if ($old = Order::where('idempotency_key', $key)->first()) {
                        return $old;
                    }
                    $quote = $this->calculate($cart, true);
                    abort_unless($quote['total_cents'] === $data['expected_total_cents'], 409, '價格或運費已變更，請重新確認訂單。');
                    $this->inventory($quote['items'], -1);
                    $order = Order::create(['order_number' => 'R3-'.now('Asia/Taipei')->format('Ymd').'-'.strtoupper((string) Str::ulid()), 'idempotency_key' => $key, 'payload_hash' => $hash, 'customer_name' => $data['customer_name'], 'customer_phone' => $data['customer_phone'], 'address' => $data['address'], 'delivery_method' => $data['delivery_method'], 'items' => $quote['items'], 'subtotal_cents' => $quote['total_cents'] - app(ProductCatalog::class)->cents($quote['shipping_fee']), 'shipping_fee_cents' => app(ProductCatalog::class)->cents($quote['shipping_fee']), 'total_cents' => $quote['total_cents'], 'status' => 'new', 'version' => 1]);
                    foreach (User::where('active', true)->with('roles')->get() as $user) {
                        if ($user->canDo('orders.read.all')) {
                            Entity::create(['type' => 'notifications', 'owner_id' => $user->id, 'data' => ['order_id' => $order->id, 'title' => '新的商品訂單', 'message' => '收到新的商品訂單，請查看並處理。', 'read' => false]]);
                        }
                    }
                    $created = true;

                    return $order;
                }, 3);
            });
        } catch (UniqueConstraintViolationException) {
            $order = Order::where('idempotency_key', $key)->first();
            abort_unless($order, 409, '訂單送出發生衝突，請重試。');
        } catch (LockTimeoutException) {
            abort(429, '請稍候再試。');
        }
        abort_unless(hash_equals($order->payload_hash, $hash), 409, '此送出代碼已用於其他訂單。');

        return response()->json($order->confirmation(), $created ? 201 : 200);
    }

    private function inventory(array $items, int $direction): void
    {
        $products = Entity::where('type', 'contents')->whereIn('id', array_column($items, 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($items as $line) {
            $product = $products->get($line['product_id']);
            abort_unless($product, 409, '商品已移除，無法調整庫存。');
            $data = $product->data;
            $found = false;
            foreach ($data['metadata']['variants'] as &$variant) {
                if ($variant['id'] === $line['variant_id']) {
                    $stock = (int) $variant['stock'] + $direction * $line['quantity'];
                    abort_if($stock < 0, 409, '庫存不足。');
                    $variant['stock'] = $stock;
                    $found = true;
                    break;
                }
            }
            unset($variant);
            abort_unless($found, 409, '商品規格已移除，無法調整庫存。');
            $data['version'] = (int) ($data['version'] ?? 1) + 1;
            $product->update(['data' => $data]);
        }
    }

    public function orders(Request $r, ?int $id = null)
    {
        $this->permit($r, $r->isMethod('get') ? 'orders.read.all' : 'orders.update.all');
        if ($r->isMethod('get')) {
            if ($id) {
                return Order::findOrFail($id)->payload();
            }
            $v = $r->validate(['status' => 'nullable|in:new,confirmed,shipped,completed,cancelled', 'q' => 'nullable|string|max:200']);
            $query = Order::query();
            if (! empty($v['status'])) {
                $query->where('status', $v['status']);
            }
            if (! empty($v['q'])) {
                $query->where(fn ($q) => $q->where('order_number', 'like', '%'.$v['q'].'%')->orWhere('customer_name', 'like', '%'.$v['q'].'%')->orWhere('customer_phone', 'like', '%'.$v['q'].'%'));
            }

            return ['data' => $query->orderByDesc('id')->limit(500)->get()->map->payload()];
        }
        $v = $r->validate(['version' => 'required|integer|min:1', 'status' => 'required|in:new,confirmed,shipped,completed,cancelled', 'staff_note' => 'nullable|string|max:10000']);

        return DB::transaction(function () use ($r, $id, $v) {
            Role::orderBy('id')->lockForUpdate()->get();
            $r->user()->refresh()->unsetRelation('roles');
            $this->permit($r, 'orders.update.all');
            $order = Order::lockForUpdate()->findOrFail($id);
            abort_unless($order->version === (int) $v['version'], 409, '訂單版本已更新。');
            $allowed = ['new' => ['new', 'confirmed', 'cancelled'], 'confirmed' => ['confirmed', 'shipped', 'completed', 'cancelled'], 'shipped' => ['shipped', 'completed'], 'completed' => ['completed'], 'cancelled' => ['cancelled']];
            abort_unless(in_array($v['status'], $allowed[$order->status], true), 409, '無法進行此訂單狀態變更。');
            $before = $order->only(['status', 'staff_note', 'version']);
            if ($v['status'] === 'cancelled' && $order->status !== 'cancelled') {
                $this->inventory($order->items, 1);
            }
            $order->fill(collect($v)->except('version')->all());
            $order->version++;
            $order->save();
            Entity::create(['type' => 'audit', 'owner_id' => $r->user()->id, 'data' => ['module' => 'orders', 'subject_id' => $order->id, 'action' => 'update', 'before' => $before, 'after' => $order->only(['status', 'staff_note', 'version'])]]);

            return $order->payload();
        }, 3);
    }
}
