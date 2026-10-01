<?php

use App\Http\Controllers\ApiController;
use App\Http\Controllers\ClassTemplateController;
use App\Http\Controllers\ContactInquiryController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\GroupNewsController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PrisonController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShippingController;
use App\Http\Middleware\ActiveUser;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::prefix('api/v1')->group(function () {
    Route::get('health', function () {
        try {
            DB::select('select 1');
            Redis::connection()->ping();

            return ['status' => 'ok', 'mode' => 'synthetic-uat'];
        } catch (Throwable) {
            return response()->json(['status' => 'unavailable'], 503);
        }
    })->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
        ActiveUser::class,
    ]);
    foreach (['csrf', 'me'] as $a) {
        Route::get('auth/'.$a, fn (Request $r) => (new ApiController)->auth($r, $a));
    }
    foreach (['login', 'logout', 'otp', 'register', 'reset-password', 'change-phone'] as $a) {
        $route = Route::post('auth/'.$a, fn (Request $r) => (new ApiController)->auth($r, $a));
        if ($a !== 'login') {
            $route->middleware('throttle:10,1,auth-'.$a);
        }
    }
    Route::put('auth/profile', fn (Request $r) => (new ApiController)->auth($r, 'profile'));
    Route::post('public/contact', [ContactInquiryController::class, 'submit'])->middleware(['throttle:20,1,contact-inquiry', 'throttle:100,60,contact-inquiry-hour']);
    Route::get('public/contact', [ApiController::class, 'publicContact']);
    Route::get('public/shipping-settings', [ShippingController::class, 'publicSettings']);
    Route::post('public/orders/quote', [OrderController::class, 'quote'])->middleware('throttle:60,1,order-quote');
    Route::post('public/orders', [OrderController::class, 'submit'])->middleware(['throttle:10,1,guest-order', 'throttle:50,60,guest-order-hour']);
    Route::get('public/products/{id}/quote', [ProductController::class, 'quote']);
    foreach (['pages', 'news', 'products', 'search'] as $kind) {
        Route::get('public/'.$kind, fn (Request $r) => (new ApiController)->publicContent($r, $kind));
        if ($kind !== 'search') {
            Route::get('public/'.$kind.'/{id}', fn (Request $r, string $id) => (new ApiController)->publicContent($r, $kind, $id));
        }
    }
    Route::get('files/{id}/download', [ModuleController::class, 'files']);
    Route::middleware('auth')->group(function () {
        Route::match(['get', 'put'], 'shipping-settings', [ShippingController::class, 'settings']);
        Route::get('orders', [OrderController::class, 'orders']);
        Route::match(['get', 'put'], 'orders/{id}', [OrderController::class, 'orders']);
        Route::get('contact-inquiries', [ContactInquiryController::class, 'inquiries']);
        Route::put('contact-inquiries/{id}', [ContactInquiryController::class, 'inquiries']);
        Route::get('groups/options', [GroupController::class, 'options']);
        Route::get('groups/member-options', [GroupController::class, 'memberOptions']);
        Route::match(['get', 'post'], 'groups', [GroupController::class, 'groups']);
        Route::match(['get', 'put'], 'groups/{id}', [GroupController::class, 'groups']);
        Route::get('group-news', [GroupNewsController::class, 'news']);
        Route::get('group-news/{id}', [GroupNewsController::class, 'news']);
        Route::get('contents/{id}/broadcasts', [GroupNewsController::class, 'broadcasts']);
        Route::post('contents/{id}/broadcast', [GroupNewsController::class, 'broadcasts']);
        Route::get('prisons/options', [PrisonController::class, 'options']);
        Route::match(['get', 'post'], 'prisons', [PrisonController::class, 'prisons']);
        Route::put('prisons/{id}', [PrisonController::class, 'prisons']);
        Route::get('permissions', fn () => ['data' => ApiController::permissionNames()]);
        Route::post('class-templates/preview', [ClassTemplateController::class, 'preview']);
        Route::match(['get', 'post'], 'class-templates', [ClassTemplateController::class, 'templates']);
        Route::match(['get', 'put'], 'class-templates/{id}', [ClassTemplateController::class, 'templates']);
        Route::post('class-templates/{id}/generate', [ClassTemplateController::class, 'generate']);
        Route::post('sessions/{id}/assign', [ScheduleController::class, 'assignVacancy']);
        Route::match(['get', 'post'], 'products', [ProductController::class, 'products']);
        Route::match(['get', 'put', 'delete'], 'products/{id}', [ProductController::class, 'products']);
        Route::get('role-options', [ApiController::class, 'roleOptions']);
        foreach (['roles', 'users'] as $m) {
            Route::match(['get', 'post'], $m, fn (Request $r) => (new ApiController)->administration($r, $m));
            Route::put($m.'/{id}', fn (Request $r, int $id) => (new ApiController)->administration($r, $m, $id));
        }
        Route::get('teachers', function (Request $r) {
            abort_unless($r->user()->canDo('schedule.read.all') || $r->user()->canDo('schedule.read.own') || $r->user()->canDo('schedule.create.all'), 403);

            return ['data' => User::where('active', true)->get()->filter(fn ($u) => $u->canDo('schedule.read.own'))->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()];
        });
        Route::match(['get', 'post'], 'sessions', [ScheduleController::class, 'sessions']);
        Route::match(['get', 'put'], 'sessions/{id}', [ScheduleController::class, 'sessions']);
        Route::post('assignments/{id}/{action}', [ScheduleController::class, 'assignment'])->where('action', 'leave|withdraw-leave|invite|replace|attendance');
        Route::get('invitations', [ScheduleController::class, 'invitations']);
        Route::post('invitations/{id}/respond', [ScheduleController::class, 'invitations']);
        Route::get('cases/export', [ApiController::class, 'exportCases']);
        foreach (['contents', 'cases', 'meetings', 'forms'] as $m) {
            Route::match(['get', 'post'], $m, fn (Request $r) => (new ApiController)->generic($r, $m));
            Route::match(['get', 'put', 'delete'], $m.'/{id}', fn (Request $r, int $id) => (new ApiController)->generic($r, $m, $id));
        }
        Route::post('cases/{id}/records', [ModuleController::class, 'records']);
        Route::match(['get', 'post'], 'forms/{id}/responses', [ModuleController::class, 'responses']);
        Route::get('forms/{id}/export', fn (Request $r, int $id) => (new ModuleController)->responses($r, $id, true));
        Route::post('files', [ModuleController::class, 'files']);
        Route::match(['get', 'post'], 'resources', [ModuleController::class, 'resources']);
        Route::delete('resources/{id}', [ModuleController::class, 'resources']);
        Route::put('resources/{id}', [ModuleController::class, 'resources']);
        Route::match(['get', 'post'], 'donations', [ModuleController::class, 'donations']);
        Route::post('donations/{id}/simulate', [ModuleController::class, 'donations']);
        foreach (['changes', 'notifications'] as $m) {
            Route::get($m, fn (Request $r) => (new ModuleController)->inbox($r, $m));
            Route::post($m.'/{id}/'.($m === 'changes' ? 'acknowledge' : 'read'), fn (Request $r, int $id) => (new ModuleController)->inbox($r, $m, $id));
        }
        Route::match(['get', 'put'], 'integrations/line', fn (Request $r) => (new ModuleController)->integrations($r, 'line'));
        Route::get('integrations/drive', fn (Request $r) => (new ModuleController)->integrations($r, 'drive'));
        Route::post('integrations/drive/simulate', fn (Request $r) => (new ModuleController)->integrations($r, 'drive'));
        Route::match(['get', 'put'], 'settings', [SettingsController::class, 'settings']);
        Route::post('settings/logo', [SettingsController::class, 'logo']);
        Route::get('reports', [ModuleController::class, 'reports']);
    });
});
