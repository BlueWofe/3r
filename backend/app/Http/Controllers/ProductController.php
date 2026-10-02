<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\Order;
use App\Models\Role;
use App\Services\ProductCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends ApiController
{
    public function products(Request $r, ?int $id = null)
    {
        $action = $r->isMethod('get') ? 'read' : ($r->isMethod('delete') ? 'delete' : ($id ? 'update' : 'create'));
        $this->permit($r, "content.$action.all");
        if ($r->isMethod('get')) {
            if ($id) {
                return $this->product($id)->publicData();
            }

            return ['data' => Entity::where('type', 'contents')->get()->filter(fn ($e) => ($e->data['kind'] ?? '') === 'product')->map->publicData()->values()];
        }

        return DB::transaction(function () use ($r, $id, $action) {
            // Serialize slug validation with generic CMS writes through the same role lock.
            Role::orderBy('id')->lockForUpdate()->get();
            $r->user()->refresh()->unsetRelation('roles');
            $this->permit($r, "content.$action.all");
            $e = $id ? $this->product($id, true) : new Entity(['type' => 'contents', 'owner_id' => $r->user()->id, 'data' => []]);
            $before = $e->data;
            if ($r->isMethod('delete')) {
                abort_if($this->reservedVariants($id) !== [], 409, '商品仍有待處理訂單，無法刪除。');
                $e->delete();
                Entity::create(['type' => 'audit', 'owner_id' => $r->user()->id, 'data' => ['module' => 'products', 'subject_id' => $id, 'action' => 'delete', 'before' => $before]]);

                return ['message' => '已刪除'];
            }
            $v = app(ProductCatalog::class)->validate($r->all(), $id);
            $version = $r->validate(['version' => ($id ? 'required' : 'sometimes').'|integer|min:1', 'visibility' => 'sometimes|in:public']);
            if ($id && isset($version['version'])) {
                abort_unless((int) $version['version'] === (int) ($before['version'] ?? 1), 409, '文章版本已更新。');
            }
            if ($id) {
                $retained = array_column($v['metadata']['variants'], 'id');
                abort_if(array_diff($this->reservedVariants($id), $retained) !== [], 409, '規格仍有待處理訂單，無法移除。');
            }
            $v['version'] = $id ? (int) ($before['version'] ?? 1) + 1 : 1;
            $v['visibility'] = 'public';
            if ($v['status'] === 'published') {
                $this->permit($r, 'content.publish.all');
            }
            if (isset($before['views'])) {
                $v['views'] = $before['views'];
            }$e->data = $v;
            $e->save();
            Entity::create(['type' => 'audit', 'owner_id' => $r->user()->id, 'data' => ['module' => 'products', 'subject_id' => $e->id, 'action' => $id ? 'update' : 'create', 'before' => $before, 'after' => $v]]);

            return $e->publicData();
        });
    }

    private function product(int $id, bool $lock = false): Entity
    {
        $q = Entity::where('type', 'contents');
        if ($lock) {
            $q->lockForUpdate();
        }$e = $q->findOrFail($id);
        abort_unless(($e->data['kind'] ?? '') === 'product', 404);

        return $e;
    }

    private function reservedVariants(int $id): array
    {
        return Order::whereIn('status', ['new', 'confirmed'])->get()->flatMap(fn ($order) => collect($order->items)->where('product_id', $id)->pluck('variant_id'))->unique()->values()->all();
    }

    public function quote(Request $r, int $id): array
    {
        $v = $r->validate(['variant_id' => 'required|string|max:100', 'quantity' => 'required|integer|min:1|max:1000000']);

        return app(ProductCatalog::class)->quote($this->product($id), $v['variant_id'], (int) $v['quantity']);
    }
}
