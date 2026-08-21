<?php

namespace App\Http\Middleware;

use BadMethodCallException;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
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
            $this->forceLogout($request);

            return redirect()->route('login')->withErrors([
                'auth' => 'حساب کاربری شما غیرفعال شده است. برای پیگیری با مدیر سامانه تماس بگیرید.',
            ]);
        }

        // رمز عبور از جای دیگری عوض شده: این نشست با اعتبارنامهٔ قدیمی ساخته شده
        // و باید باطل شود (لاراول این کار را با AuthenticateSession می‌کند؛ اینجا
        // چون میان‌افزار سراسری خودمان است، همان بررسی را جا داده‌ایم).
        if ($this->passwordChangedElsewhere($request, $user)) {
            $this->forceLogout($request);

            return redirect()->route('login')->withErrors([
                'auth' => 'رمز عبور این حساب تغییر کرده است. لطفاً با رمز جدید دوباره وارد شوید.',
            ]);
        }

        if ($roles !== [] && ! in_array($user->role, $roles, true)) {
            abort(403, 'این بخش فقط برای نقش‌های مجاز باز است و نقش شما («'.$user->roleLabel().'») اجازهٔ ورود ندارد.');
        }

        return $next($request);
    }

    /**
     * آیا هش رمزِ ذخیره‌شده در نشست با هش فعلی کاربر فرق دارد؟
     *
     * بار اول (نشست‌های ساخته‌شده پیش از این بررسی) فقط مقدار را ثبت می‌کند
     * و کسی را بیرون نمی‌اندازد.
     */
    private function passwordChangedElsewhere(Request $request, Authenticatable $user): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $current = (string) $user->getAuthPassword();

        if ($current === '') {
            return false;
        }

        $key = 'password_hash_'.Auth::getDefaultDriver();
        $stored = $request->session()->get($key);

        if (! is_string($stored) || $stored === '') {
            $request->session()->put($key, $this->fingerprint($current));

            return false;
        }

        // هر دو قالب پذیرفته می‌شود: HMAC تازهٔ لاراول و هش خام (سازگاری با نشست‌های قدیمی).
        return ! hash_equals($this->fingerprint($current), $stored)
            && ! hash_equals($current, $stored);
    }

    /** اثر انگشت هش رمز، دقیقاً به همان روشی که خود لاراول می‌سازد. */
    private function fingerprint(string $passwordHash): string
    {
        try {
            return Auth::guard('web')->hashPasswordForCookie($passwordHash);
        } catch (BadMethodCallException) {
            return $passwordHash;
        }
    }

    /** خروج کامل: نشست، توکن CSRF و کوکی «مرا به خاطر بسپار». */
    private function forceLogout(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
