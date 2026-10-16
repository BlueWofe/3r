<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\Role;
use App\Services\ProductCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShippingController extends ApiController
{
    public function publicSettings(): array
    {
        return json_decode(DB::table('shipping_settings')->where('id', 1)->firstOrFail()->data, true);
    }

    public function settings(Request $r): array
    {
        $this->authorizeSettings($r);
        if ($r->isMethod('get')) {
            return $this->publicSettings();
        }
        $v = $r->validate(['flat_fee' => 'required|numeric|min:0|max:1000000|decimal:0,2', 'free_shipping_threshold' => 'present|nullable|numeric|min:0|max:100000000|decimal:0,2', 'shipping_enabled' => 'required|boolean', 'pickup_enabled' => 'required|boolean', 'pickup_instructions' => 'nullable|string|max:2000']);
        $catalog = app(ProductCatalog::class);
        $v['flat_fee'] = $catalog->cents($v['flat_fee']) / 100;
        $v['free_shipping_threshold'] = isset($v['free_shipping_threshold']) ? $catalog->cents($v['free_shipping_threshold']) / 100 : null;
        $v['pickup_instructions'] = $v['pickup_instructions'] ?? '';

        return DB::transaction(function () use ($r, $v) {
            Role::orderBy('id')->lockForUpdate()->get();
            $r->user()->refresh()->unsetRelation('roles');
            $this->authorizeSettings($r);
            $e = DB::table('shipping_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            $before = json_decode($e->data, true);
            DB::table('shipping_settings')->where('id', 1)->update(['data' => json_encode($v)]);
            Entity::create(['type' => 'audit', 'owner_id' => $r->user()->id, 'data' => ['module' => 'shipping-settings', 'action' => 'update', 'before' => $before, 'after' => $v]]);

            return $v;
        });
    }

    private function authorizeSettings(Request $r): void
    {
        abort_unless($r->user()?->canDo('content.update.all') || $r->user()?->canDo('settings.manage.all'), 403);
    }
}
