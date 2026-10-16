<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->text('description')->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('group_user', function (Blueprint $t) {
            $t->foreignId('group_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['group_id', 'user_id']);
        });
        Schema::create('content_broadcasts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('content_id')->index();
            $t->unsignedInteger('version');
            $t->unsignedInteger('recipient_count');
            $t->timestamp('sent_at');
            $t->foreignId('actor_id')->constrained('users');
            $t->unique(['content_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_broadcasts');
        Schema::dropIfExists('group_user');
        Schema::dropIfExists('groups');
    }
};
