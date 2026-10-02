<?php

use App\Models\ClassTemplate;
use App\Services\ClassGeneration;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('classes:generate {--template= : 僅產生指定班別}', function () {
    $query = ClassTemplate::where('active', true);
    if ($this->option('template')) {
        $query->where('id', (int) $this->option('template'));
    }
    $failed = false;
    foreach ($query->orderBy('id')->pluck('id') as $id) {
        try {
            $result = app(ClassGeneration::class)->generate($id);
            $this->line("班別 {$id}：新增 {$result['created']}，既有 {$result['existing']}，略過 ".count($result['skipped']).'。');
        } catch (Throwable) {
            $failed = true;
            $this->error("班別 {$id} 生成失敗，請檢查資料與系統狀態。");
        }
    }

    return $failed ? 1 : 0;
})->purpose('維持未來 90 天班別排課，重跑不覆寫既有場次');

Schedule::command('classes:generate')->dailyAt('00:10')->timezone('Asia/Taipei')->withoutOverlapping()->onOneServer();
