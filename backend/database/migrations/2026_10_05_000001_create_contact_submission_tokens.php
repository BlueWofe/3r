<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_submission_tokens', function (Blueprint $t) {
            $t->uuid('token')->primary();
            $t->foreignId('contact_inquiry_id')->constrained()->cascadeOnDelete();
            $t->timestamp('created_at');
        });
        Schema::table('contact_inquiries', function (Blueprint $t) {
            $t->index(['payload_hash', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_submission_tokens');
        Schema::table('contact_inquiries', function (Blueprint $t) {
            $t->dropIndex(['payload_hash', 'created_at']);
        });
    }
};
