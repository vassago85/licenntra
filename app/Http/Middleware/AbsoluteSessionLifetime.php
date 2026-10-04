<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class AbsoluteSessionLifetime
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null || ! $request->hasSession()) {
            return $next($request);
        }

        $started = $request->session()->get('absolute_session_started_at');

        if ($started === null) {
            $request->session()->put('absolute_session_started_at', now()->timestamp);

            return $next($request);
        }

        $minutes = 480;

        if (Schema::hasTable('system_settings')) {
            $minutes = SystemSetting::current()->absolute_timeout_minutes ?: 480;
        }

        if (now()->timestamp - (int) $started >= ($minutes * 60)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Your session ended after the maximum sign-in time. Sign in again.');
        }

        return $next($request);
    }
}
