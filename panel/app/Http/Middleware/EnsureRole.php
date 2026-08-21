<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * کنترل دسترسی بر پایه نقش کاربر.
 *
 * روش استفاده در روت‌ها:
 *   ->middleware('role:admin')          فقط مدیر سامانه
 *   ->middleware('role:admin,data')     مدیر سامانه یا کارشناس داده
 *   ->middleware('role')                بدون آرگومان: فقط بررسی «حساب فعال»
 *                                       (امن برای افزودن به گروه سراسری web)
 *
 * نام مستعار «role» از قبل در bootstrap/app.php ثبت شده است.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // مهمان: اگر نقشی خواسته نشده (استفاده به‌عنوان نگهبان سراسری «حساب فعال»)
        // بگذار رد شود؛ وگرنه به صفحهٔ ورود برگردد.
        if ($user === null) {
            return $roles === [] ? $next($request) : redirect()->guest(route('login'));
        }

        // حساب غیرفعال‌شده وسط نشست: بلافاصله خارج شود.
        if (! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'auth' => 'حساب کاربری شما غیرفعال شده است. برای پیگیری با مدیر سامانه تماس بگیرید.',
            ]);
        }

        if ($roles !== [] && ! in_array($user->role, $roles, true)) {
            abort(403, 'این بخش فقط برای نقش‌های مجاز باز است و نقش شما («'.$user->roleLabel().'») اجازهٔ ورود ندارد.');
        }

        return $next($request);
    }
}
