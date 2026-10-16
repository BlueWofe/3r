<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prisons', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->string('address', 500)->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        foreach (['entities', 'service_sessions', 'class_templates'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('prison_id')->nullable()->constrained('prisons')->restrictOnDelete();
            });
            $query = DB::table($table);
            if ($table === 'entities') {
                $query->where('type', 'cases');
            }
            $query->orderBy('id')->chunkById(200, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    $data = json_decode($row->data, true);
                    $name = trim((string) ($data['prison'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    DB::table('prisons')->insertOrIgnore(['name' => $name, 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                    $id = DB::table('prisons')->where('name', $name)->value('id');
                    $data['prison_id'] = $id;
                    $data['prison'] = $name;
                    DB::table($table)->where('id', $row->id)->update(['prison_id' => $id, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['entities', 'service_sessions', 'class_templates'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('prison_id'));
        }
        Schema::dropIfExists('prisons');
    }
};
