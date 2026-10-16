<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Entity
    {
        return Entity::create(['type' => 'contents', 'data' => ['kind' => 'product', 'title' => '合成商品', 'status' => 'published', 'visibility' => 'public', 'metadata' => ['variants' => [['id' => 'one', 'sku' => 'DEMO', 'options' => ['示範'], 'price' => 100.25, 'stock' => 10, 'active' => true, 'wholesale' => [['min_quantity' => 3, 'unit_price' => 90.15]]]]]]]);
    }

    private function payload(Entity $product): array
    {
        return ['delivery_method' => 'shipping', 'items' => [['product_id' => $product->id, 'variant_id' => 'one', 'quantity' => 3]], 'customer_name' => '合成訂購人', 'customer_phone' => '0900000000', 'address' => '示範地址', 'idempotency_key' => (string) Str::uuid(), 'expected_total_cents' => 27045];
    }

    private function actor(array $permissions): User
    {
        $user = User::create(['name' => '測試管理員', 'phone' => '09'.str_pad((string) User::count(), 8, '0', STR_PAD_LEFT), 'email' => uniqid().'@example.test', 'password' => 'test-password']);
        $user->roles()->attach(Role::create(['name' => '測試角色', 'slug' => uniqid(), 'permissions' => $permissions, 'active' => true]));

        return $user;
    }

    public function test_quote_merges_lines_and_computes_wholesale_shipping_threshold_and_pickup(): void
    {
        $product = $this->product();
        DB::table('shipping_settings')->where('id', 1)->update(['data' => json_encode(['flat_fee' => 60, 'free_shipping_threshold' => 300, 'shipping_enabled' => true, 'pickup_enabled' => true, 'pickup_instructions' => '示範'])]);
        $v = $this->payload($product);
        $v['items'] = [['product_id' => $product->id, 'variant_id' => 'one', 'quantity' => 1], ['product_id' => $product->id, 'variant_id' => 'one', 'quantity' => 2]];
        $this->postJson('/api/v1/public/orders/quote', $v)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.quantity', 3)->assertJsonPath('total_cents', 33045)->assertJsonPath('shipping_fee', 60);
        $v['items'][1]['quantity'] = 3;
        $this->postJson('/api/v1/public/orders/quote', $v)->assertOk()->assertJsonPath('total_cents', 36060)->assertJsonPath('shipping_fee', 0);
        $v['delivery_method'] = 'pickup';
        $this->postJson('/api/v1/public/orders/quote', $v)->assertOk()->assertJsonPath('shipping_fee', 0);
        $this->assertSame(10, $product->fresh()->data['metadata']['variants'][0]['stock']);
    }

    public function test_guest_order_retries_conflicts_inventory_and_private_confirmation(): void
    {
        $product = $this->product();
        $v = $this->payload($product);
        $this->postJson('/api/v1/public/orders', array_replace($v, ['expected_total_cents' => 1]))->assertConflict();
        $this->assertDatabaseCount('orders', 0);
        $confirmation = $this->postJson('/api/v1/public/orders', $v)->assertCreated()->json();
        $this->assertEqualsCanonicalizing(['order_number', 'status', 'total', 'currency'], array_keys($confirmation));
        $this->postJson('/api/v1/public/orders', $v)->assertOk()->assertExactJson($confirmation);
        $this->postJson('/api/v1/public/orders', array_replace($v, ['customer_name' => '其他人']))->assertConflict();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(7, $product->fresh()->data['metadata']['variants'][0]['stock']);
        $this->getJson('/api/v1/orders')->assertUnauthorized();
        $this->getJson('/api/v1/public/search?q=合成訂購人')->assertJsonCount(0, 'data');
        $v['idempotency_key'] = (string) Str::uuid();
        $v['items'][0]['quantity'] = 8;
        $this->postJson('/api/v1/public/orders', $v)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_permissions_versions_cancel_restores_once_and_notifications_recheck_access(): void
    {
        $manager = $this->actor(['orders.read.all', 'orders.update.all']);
        $outsider = $this->actor(['content.read.all']);
        $product = $this->product();
        $this->postJson('/api/v1/public/orders', $this->payload($product))->assertCreated();
        $order = Order::first();
        $this->assertSame(1, Entity::where('type', 'notifications')->count());
        $this->actingAs($outsider)->getJson('/api/v1/orders')->assertForbidden();
        $this->putJson('/api/v1/orders/'.$order->id, ['version' => 1, 'status' => 'cancelled'])->assertForbidden();
        $this->actingAs($manager)->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.url', '/app/admin/orders?id='.$order->id);
        $this->getJson('/api/v1/orders/'.$order->id)->assertOk()->assertJsonPath('customer_name', '合成訂購人');
        $this->putJson('/api/v1/orders/'.$order->id, ['version' => 1, 'status' => 'cancelled', 'staff_note' => '合成取消'])->assertOk()->assertJsonPath('version', 2);
        $this->assertSame(10, $product->fresh()->data['metadata']['variants'][0]['stock']);
        $this->putJson('/api/v1/orders/'.$order->id, ['version' => 1, 'status' => 'cancelled'])->assertConflict();
        $this->putJson('/api/v1/orders/'.$order->id, ['version' => 2, 'status' => 'new'])->assertConflict();
        $this->putJson('/api/v1/orders/'.$order->id, ['version' => 2, 'status' => 'cancelled'])->assertOk();
        $this->assertSame(10, $product->fresh()->data['metadata']['variants'][0]['stock']);
        $manager->roles()->first()->update(['permissions' => []]);
        $manager->unsetRelation('roles');
        $this->getJson('/api/v1/notifications')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/orders/'.$order->id)->assertForbidden();
    }

    public function test_shipping_settings_authorization_validation_disabled_delivery_and_spam_limit(): void
    {
        $this->getJson('/api/v1/public/shipping-settings')->assertOk()->assertJsonPath('flat_fee', 0);
        $this->actingAs($this->actor(['content.read.all']))->getJson('/api/v1/shipping-settings')->assertForbidden();
        $this->actingAs($this->actor(['content.update.all']));
        $v = ['flat_fee' => 60, 'free_shipping_threshold' => null, 'shipping_enabled' => false, 'pickup_enabled' => true, 'pickup_instructions' => '示範取貨'];
        $this->putJson('/api/v1/shipping-settings', array_replace($v, ['flat_fee' => -1]))->assertUnprocessable();
        $this->putJson('/api/v1/shipping-settings', $v)->assertOk()->assertJsonPath('pickup_instructions', '示範取貨');
        $product = $this->product();
        $cart = $this->payload($product);
        $this->postJson('/api/v1/public/orders/quote', $cart)->assertUnprocessable();
        $cart['delivery_method'] = 'pickup';
        unset($cart['address']);
        $this->postJson('/api/v1/public/orders', $cart)->assertCreated();
        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/v1/public/orders', $cart)->assertOk();
        }
        $this->postJson('/api/v1/public/orders', $cart)->assertOk();
        $this->postJson('/api/v1/public/orders', $cart)->assertStatus(429);
    }

    public function test_catalog_cannot_remove_reserved_stock_or_overwrite_a_stale_inventory_version(): void
    {
        $product = $this->product();
        $this->postJson('/api/v1/public/orders', $this->payload($product))->assertCreated();
        $this->actingAs($this->actor(['content.delete.all', 'content.update.all', 'content.publish.all']));
        $this->deleteJson('/api/v1/products/'.$product->id)->assertConflict();
        $data = $product->fresh()->data;
        $data += ['slug' => 'demo-order-product', 'body' => '合成商品', 'version' => 2];
        $data['metadata'] += ['unit' => '盒', 'currency' => 'TWD', 'gallery_ids' => [], 'spec_axes' => [['name' => '示範規格', 'options' => ['示範']]]];
        $this->putJson('/api/v1/products/'.$product->id, array_replace($data, ['version' => 1]))->assertConflict();
        $data['metadata']['variants'][0]['id'] = 'replacement';
        $this->putJson('/api/v1/products/'.$product->id, $data)->assertConflict();
        $this->assertSame(7, $product->fresh()->data['metadata']['variants'][0]['stock']);
    }
}
