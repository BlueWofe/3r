<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $role = Role::firstOrCreate(['slug' => 'system-admin'], ['name' => '管理員', 'permissions' => []]);
        $u = User::create(['name' => '示範', 'email' => 'products@example.invalid', 'phone' => '0912345678', 'password' => 'Test-Only-Password']);
        $u->roles()->attach($role);

        return $u;
    }

    private function product(): array
    {
        return ['title' => '示範食品', 'slug' => 'demo-food', 'body' => '示範食品資訊', 'status' => 'published', 'metadata' => ['unit' => '盒', 'currency' => 'TWD', 'gallery_ids' => [], 'spec_axes' => [], 'variants' => [['id' => 'original', 'sku' => 'DEMO-01', 'options' => [], 'price' => 100.15, 'stock' => 100, 'active' => true, 'wholesale' => [['min_quantity' => 5, 'unit_price' => 90.05], ['min_quantity' => 10, 'unit_price' => 80.01]]]]]];
    }

    public function test_crud_preserves_content_storage_and_exact_tier_quote(): void
    {
        $this->actingAs($this->admin());
        $id = $this->postJson('/api/v1/products', $this->product())->assertOk()->json('id');
        $this->getJson('/api/v1/products')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/public/products/'.$id)->assertOk()->assertJsonPath('data.metadata.currency', 'TWD');
        foreach ([[1, 100.15, 100.15, null], [5, 90.05, 450.25, 5], [11, 80.01, 880.11, 10]] as [$quantity,$price,$total,$tier]) {
            $this->getJson("/api/v1/public/products/$id/quote?variant_id=original&quantity=$quantity")->assertOk()->assertJsonPath('unit_price', $price)->assertJsonPath('total', $total)->assertJsonPath('applied_min_quantity', $tier);
        }
        $this->getJson("/api/v1/public/products/$id/quote?variant_id=original&quantity=101")->assertUnprocessable();
        $this->getJson("/api/v1/public/products/$id/quote?variant_id=original&quantity=0")->assertUnprocessable();
        $body = $this->product();
        $body['status'] = 'draft';
        $body['version'] = 1;
        $this->putJson('/api/v1/products/'.$id, $body)->assertOk();
        $this->getJson("/api/v1/public/products/$id/quote?variant_id=original&quantity=1")->assertNotFound();
        $this->deleteJson('/api/v1/products/'.$id)->assertOk();
        $this->assertDatabaseMissing('entities', ['id' => $id]);
    }

    public function test_generic_content_product_cannot_bypass_validation_or_change_kind(): void
    {
        $this->actingAs($this->admin());
        $bad = $this->product();
        $bad['kind'] = 'product';
        $bad['metadata']['variants'][0]['price'] = 1.234;
        $this->postJson('/api/v1/contents', $bad)->assertUnprocessable();
        $id = $this->postJson('/api/v1/products', $this->product())->json('id');
        $this->putJson('/api/v1/contents/'.$id, ['kind' => 'page', 'title' => '繞過', 'slug' => 'bypass', 'body' => '示範', 'status' => 'published'])->assertUnprocessable();
        $this->assertEquals('product', Entity::find($id)->data['kind']);
    }

    public function test_duplicate_variants_invalid_axes_and_increasing_discount_rejected(): void
    {
        $this->actingAs($this->admin());
        $body = $this->product();
        $body['metadata']['variants'][] = $body['metadata']['variants'][0];
        $this->postJson('/api/v1/products', $body)->assertUnprocessable();
        $body = $this->product();
        $body['metadata']['spec_axes'] = [['name' => '口味', 'options' => ['原味']]];
        $this->postJson('/api/v1/products', $body)->assertUnprocessable();
        $body = $this->product();
        $body['metadata']['variants'][0]['wholesale'][1]['unit_price'] = 95;
        $this->postJson('/api/v1/products', $body)->assertUnprocessable();
        $body = $this->product();
        $body['metadata']['variants'][0]['wholesale'][1]['min_quantity'] = 5;
        $this->postJson('/api/v1/products', $body)->assertUnprocessable();
    }

    public function test_quotes_do_not_mix_variants_and_inactive_variant_is_unavailable(): void
    {
        $this->actingAs($this->admin());
        $body = $this->product();
        $body['metadata']['spec_axes'] = [['name' => '口味', 'options' => ['原味', '芝麻']]];
        $body['metadata']['variants'][0]['options'] = ['原味'];
        $body['metadata']['variants'][] = ['id' => 'sesame', 'sku' => 'DEMO-02', 'options' => ['芝麻'], 'price' => 200, 'stock' => 2, 'active' => false, 'wholesale' => []];
        $id = $this->postJson('/api/v1/products', $body)->assertOk()->json('id');
        $this->getJson("/api/v1/public/products/$id/quote?variant_id=sesame&quantity=1")->assertUnprocessable();
        $this->getJson("/api/v1/public/products/$id/quote?variant_id=original&quantity=4")->assertOk()->assertJsonPath('unit_price', 100.15);
    }

    public function test_legacy_product_remains_readable_and_private_images_rejected(): void
    {
        $this->actingAs($this->admin());
        $legacy = Entity::create(['type' => 'contents', 'data' => ['kind' => 'product', 'title' => '舊示範食品', 'status' => 'published', 'metadata' => ['demo' => true]]]);
        $this->getJson('/api/v1/products/'.$legacy->id)->assertOk()->assertJsonPath('metadata.demo', true);
        $this->getJson('/api/v1/public/products/'.$legacy->id)->assertOk();
        $file = Entity::create(['type' => 'files', 'data' => ['name' => 'private.png', 'visibility' => 'private', 'path' => 'private']]);
        $body = $this->product();
        $body['metadata']['gallery_ids'] = [$file->id];
        $this->postJson('/api/v1/products', $body)->assertUnprocessable();
    }

    public function test_read_only_user_cannot_write_and_public_quotes_need_no_login(): void
    {
        $this->getJson('/api/v1/products')->assertUnauthorized();
        $admin = $this->admin();
        $id = $this->actingAs($admin)->postJson('/api/v1/products', $this->product())->json('id');
        $role = Role::create(['name' => '閱讀', 'slug' => 'read-only', 'permissions' => ['content.read.all']]);
        $admin->roles()->sync([$role->id]);
        $this->getJson('/api/v1/products')->assertOk();
        $this->postJson('/api/v1/products', $this->product())->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/public/products/$id/quote?variant_id=original&quantity=1")->assertOk();
    }
}
