<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_templates', function (Blueprint $t) {
            $t->id();
            $t->json('data');
            $t->boolean('active')->default(true)->index();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::table('service_sessions', function (Blueprint $t) {
            $t->foreignId('template_id')->nullable()->constrained('class_templates')->nullOnDelete();
            $t->string('template_rule_id', 100)->nullable();
            $t->date('occurrence_date')->nullable();
            $t->unique(['template_id', 'template_rule_id', 'occurrence_date'], 'sessions_template_occurrence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('service_sessions', function (Blueprint $t) {
            $t->dropUnique('sessions_template_occurrence_unique');
            $t->dropConstrainedForeignId('template_id');
            $t->dropColumn(['template_rule_id', 'occurrence_date']);
        });
        Schema::dropIfExists('class_templates');
    }
};
