<?php

namespace App\Http\Middleware;

use App\Models\Entity;
use Closure;
use Illuminate\Http\Request;

class RequirePasswordChange
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->must_change_password || ! $request->is('api/v1/*')) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ($request->is('api/v1/public/*') || $request->is('api/v1/health'))) {
            return $next($request);
        }

        if ($request->isMethod('GET')
            && preg_match('#^api/v1/files/([1-9][0-9]*)/download$#', $request->path(), $matches)
            && Entity::where('type', 'files')->whereKey((int) $matches[1])->where('data->visibility', 'public')->exists()) {
            return $next($request);
        }

        if ($request->is('api/v1/auth/*') && in_array($request->segment(4), ['me', 'csrf', 'login', 'logout', 'otp', 'register', 'reset-password', 'password'], true)) {
            return $next($request);
        }

        return response()->json(['code' => 'PASSWORD_CHANGE_REQUIRED', 'message' => '首次登入請先更改密碼'], 403);
    }
}
