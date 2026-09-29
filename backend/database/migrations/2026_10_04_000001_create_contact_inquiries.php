<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_inquiries', function (Blueprint $t) {
            $t->id();
            $t->uuid('submission_token')->unique();
            $t->uuid('reference')->unique();
            $t->string('payload_hash', 64);
            $t->string('name', 100);
            $t->string('phone', 50);
            $t->string('email', 254)->nullable();
            $t->string('category', 100);
            $t->text('message');
            $t->string('status', 20)->default('new')->index();
            $t->text('staff_note')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_inquiries');
    }
};
