<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 50)->unique();
            $table->uuid('idempotency_key')->unique();
            $table->string('payload_hash', 64);
            $table->string('customer_name', 100);
            $table->string('customer_phone', 50);
            $table->string('address', 500)->nullable();
            $table->string('delivery_method', 20);
            $table->json('items');
            $table->bigInteger('subtotal_cents');
            $table->bigInteger('shipping_fee_cents');
            $table->bigInteger('total_cents');
            $table->string('status', 20)->default('new')->index();
            $table->unsignedInteger('version')->default(1);
            $table->text('staff_note')->nullable();
            $table->timestamps();
        });
        Schema::create('shipping_settings', function (Blueprint $table) {
            $table->id();
            $table->json('data');
        });
        DB::table('shipping_settings')->insert(['id' => 1, 'data' => json_encode(['flat_fee' => 0, 'free_shipping_threshold' => null, 'shipping_enabled' => true, 'pickup_enabled' => true, 'pickup_instructions' => '請待工作人員聯繫確認取貨時間。'])]);
        foreach (DB::table('roles')->where('slug', 'admin')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?? [];
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique(array_merge($permissions, ['orders.read.all', 'orders.update.all']))))]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
        Schema::dropIfExists('shipping_settings');
    }
};
