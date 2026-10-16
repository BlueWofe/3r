<?php

namespace App\Services;

use App\Models\Entity;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductCatalog
{
    public function validate(array $input, ?int $id = null): array
    {
        $v = Validator::make($input, [
            'kind' => 'sometimes|in:product', 'title' => 'required|string|max:200', 'slug' => 'required|string|max:200', 'body' => 'required|string|max:100000', 'summary' => 'nullable|string|max:2000', 'category' => 'nullable|string|max:100', 'status' => 'required|in:draft,published', 'sort_order' => 'nullable|integer', 'image_id' => 'nullable|integer',
            'metadata' => 'required|array', 'metadata.unit' => 'required|string|max:50', 'metadata.currency' => 'required|in:TWD', 'metadata.gallery_ids' => 'present|array|max:10', 'metadata.gallery_ids.*' => 'integer|distinct',
            'metadata.spec_axes' => 'present|array|max:2', 'metadata.spec_axes.*.name' => 'required|string|max:100|distinct', 'metadata.spec_axes.*.options' => 'required|array|min:1|max:100', 'metadata.spec_axes.*.options.*' => 'required|string|max:100',
            'metadata.variants' => 'required|array|min:1|max:100', 'metadata.variants.*.id' => 'required|string|max:100|distinct', 'metadata.variants.*.sku' => 'required|string|max:100|distinct', 'metadata.variants.*.options' => 'present|array|max:2', 'metadata.variants.*.options.*' => 'required|string|max:100', 'metadata.variants.*.price' => 'required|numeric|min:0|max:1000000|decimal:0,2', 'metadata.variants.*.stock' => 'required|integer|min:0|max:1000000', 'metadata.variants.*.active' => 'required|boolean', 'metadata.variants.*.wholesale' => 'present|array|max:10',
            'metadata.variants.*.wholesale.*.min_quantity' => 'required|integer|min:2|max:1000000', 'metadata.variants.*.wholesale.*.unit_price' => 'required|numeric|min:0|max:1000000|decimal:0,2',
            'metadata.ingredients' => 'nullable|string|max:5000', 'metadata.allergens' => 'nullable|string|max:5000', 'metadata.net_weight' => 'nullable|string|max:200', 'metadata.shelf_life' => 'nullable|string|max:200', 'metadata.storage' => 'nullable|string|max:1000', 'metadata.origin' => 'nullable|string|max:200',
        ])->validate();
        $v['kind'] = 'product';
        $v['body'] = strip_tags($v['body']);
        if (Entity::where('type', 'contents')->where('id', '!=', $id ?? 0)->get()->contains(fn ($e) => ($e->data['slug'] ?? '') === $v['slug'])) {
            $this->invalid('slug', '網址代稱已被使用。');
        }
        $axes = $v['metadata']['spec_axes'];
        $combinations = [];
        foreach ($axes as $axisIndex => $axis) {
            if (count($axis['options']) !== count(array_unique($axis['options']))) {
                $this->invalid("metadata.spec_axes.$axisIndex.options", '同一規格軸的選項不可重複。');
            }
        }
        foreach ($v['metadata']['variants'] as $index => &$variant) {
            $key = "metadata.variants.$index";
            if (count($variant['options']) !== count($axes)) {
                $this->invalid("$key.options", '規格選項數量必須符合規格軸。');
            }
            foreach ($axes as $axisIndex => $axis) {
                if (! in_array($variant['options'][$axisIndex], $axis['options'], true)) {
                    $this->invalid("$key.options", '規格選項不在允許清單中。');
                }
            }
            $combo = json_encode($variant['options'], JSON_UNESCAPED_UNICODE);
            if (isset($combinations[$combo])) {
                $this->invalid("$key.options", '同商品規格組合不可重複。');
            }$combinations[$combo] = true;
            $price = $this->cents($variant['price']);
            $quantity = 1;
            $lastPrice = $price;
            foreach ($variant['wholesale'] as $tierIndex => &$tier) {
                $tierPrice = $this->cents($tier['unit_price']);
                if ($tier['min_quantity'] <= $quantity || $tierPrice > $lastPrice) {
                    $this->invalid("$key.wholesale.$tierIndex", '大量優惠門檻必須遞增，單價不得超過基本價或前一階梯。');
                }
                $quantity = (int) $tier['min_quantity'];
                $lastPrice = $tierPrice;
                $tier['min_quantity'] = $quantity;
                $tier['unit_price'] = $tierPrice / 100;
            }unset($tier);
            $variant['price'] = $price / 100;
            $variant['stock'] = (int) $variant['stock'];
            $variant['active'] = (bool) $variant['active'];
        }unset($variant);
        $images = array_filter(array_merge($v['metadata']['gallery_ids'], isset($v['image_id']) ? [$v['image_id']] : []), fn ($value) => $value !== null);
        foreach ($images as $image) {
            $file = Entity::where('type', 'files')->find($image);
            if (! $file || ($file->data['visibility'] ?? 'private') !== 'public' || ! preg_match('/\.(?:jpe?g|png|webp)$/i', $file->data['name'] ?? '')) {
                $this->invalid('metadata.gallery_ids', '商品圖片必須是已公開的圖片檔案。');
            }
        }
        // Only the agreed metadata fields are retained; client-supplied calculated prices are ignored.
        $allowed = ['unit', 'currency', 'gallery_ids', 'spec_axes', 'variants', 'ingredients', 'allergens', 'net_weight', 'shelf_life', 'storage', 'origin'];
        $v['metadata'] = array_intersect_key($v['metadata'], array_flip($allowed));

        return $v;
    }

    public function cents(int|float|string $amount): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', (string) $amount, $m)) {
            $this->invalid('price', '價格最多只能有兩位小數。');
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }

    public function quote(Entity $product, string $variantId, int $quantity): array
    {
        abort_unless(($product->data['status'] ?? '') === 'published', 404);
        $variant = collect($product->data['metadata']['variants'] ?? [])->first(fn ($v) => $v['id'] === $variantId);
        if (! $variant || ! ($variant['active'] ?? false)) {
            $this->invalid('variant_id', '規格不存在或已停用。');
        }
        if ($quantity < 1 || $quantity > ($variant['stock'] ?? 0)) {
            $this->invalid('quantity', '數量無效或超過庫存。');
        }
        $unitPrice = $this->cents($variant['price']);
        $applied = null;
        foreach ($variant['wholesale'] ?? [] as $tier) {
            if ($quantity >= $tier['min_quantity']) {
                $unitPrice = $this->cents($tier['unit_price']);
                $applied = (int) $tier['min_quantity'];
            }
        }

        return ['variant_id' => $variant['id'], 'quantity' => $quantity, 'unit_price' => $unitPrice / 100, 'total' => ($unitPrice * $quantity) / 100, 'applied_min_quantity' => $applied, 'currency' => 'TWD', 'stock' => (int) $variant['stock']];
    }
}
