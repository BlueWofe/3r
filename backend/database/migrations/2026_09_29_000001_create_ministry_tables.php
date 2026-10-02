<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('phone')->nullable()->unique();
            $t->boolean('active')->default(true);
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->boolean('active')->default(true);
            $t->json('permissions');
            $t->timestamps();
        });
        Schema::create('role_user', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->primary(['user_id', 'role_id']);
        });
        Schema::create('entities', function (Blueprint $t) {
            $t->id();
            $t->string('type')->index();
            $t->foreignId('owner_id')->nullable()->constrained('users');
            $t->json('data');
            $t->timestamps();
        });
        Schema::create('service_sessions', function (Blueprint $t) {
            $t->id();
            $t->json('data');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('session_id')->constrained('service_sessions')->cascadeOnDelete();
            $t->foreignId('teacher_id')->constrained('users');
            $t->string('status')->default('assigned');
            $t->json('attendance')->nullable();
            $t->unique(['session_id', 'teacher_id']);
        });
        Schema::create('invitations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assignment_id')->constrained();
            $t->foreignId('teacher_id')->constrained('users');
            $t->string('status')->default('pending');
            $t->timestamps();
        });
        Schema::create('otps', function (Blueprint $t) {
            $t->id();
            $t->string('phone');
            $t->string('purpose');
            $t->string('hash');
            $t->timestamp('expires_at');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('consumed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['otps', 'invitations', 'assignments', 'service_sessions', 'entities', 'role_user', 'roles'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
