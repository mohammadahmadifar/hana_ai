<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * ورود و خروج کاربران سامانه.
 *
 * ثبت‌نام عمومی وجود ندارد؛ حساب کاربری را فقط مدیر سامانه می‌سازد
 * (بخش «کاربران» در پنل مدیریت).
 */
class LoginController extends Controller
{
    /** بیشترین تلاش ناموفق مجاز در هر بازه. */
    private const MAX_ATTEMPTS = 5;

    /** طول بازهٔ شمارش تلاش‌ها، بر حسب ثانیه. */
    private const DECAY_SECONDS = 60;

    /** نمایش فرم ورود. */
    public function show(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->to($this->homeUrl());
        }

        return view('auth.login');
    }

    /** بررسی اطلاعات ورود و ساخت نشست. */
    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'password' => ['required', 'string', 'max:72'],
        ], [
            'email.required' => 'ایمیل را وارد کنید.',
            'email.email' => 'قالب ایمیل درست نیست.',
            'email.max' => 'ایمیل نباید بیش از ۱۹۰ نویسه باشد.',
            'password.required' => 'رمز عبور را وارد کنید.',
            'password.max' => 'رمز عبور نباید بیش از ۷۲ نویسه باشد.',
        ]);

        $key = $this->throttleKey($request, $data['email']);

        // پنجرهٔ محدودیت: حداکثر ۵ تلاش ناموفق در دقیقه برای هر «ایمیل + آی‌پی».
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return $this->failed(
                $request,
                'تلاش ناموفق زیاد بوده است. چند دقیقه صبر کن و دوباره امتحان کن ('
                .$this->waitLabel(RateLimiter::availableIn($key)).' دیگر).'
            );
        }

        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], (string) $user->password)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return $this->failed($request, 'ایمیل یا رمز عبور درست نیست.');
        }

        if (! $user->is_active) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return $this->failed(
                $request,
                'حساب کاربری شما غیرفعال است و امکان ورود ندارد. برای فعال‌سازی با مدیر سامانه تماس بگیرید.'
            );
        }

        RateLimiter::clear($key);

        Auth::login($user, $request->boolean('remember'));

        // جلوگیری از تثبیت نشست (session fixation).
        $request->session()->regenerate();

        return redirect()->intended($this->homeUrl());
    }

    /** خروج از سامانه — فقط با POST. */
    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'از سامانه خارج شدید.');
    }

    /** بازگشت به فرم ورود همراه پیام خطای فارسی. */
    private function failed(Request $request, string $message): RedirectResponse
    {
        return back()
            ->withInput($request->only('email', 'remember'))
            ->withErrors(['auth' => $message]);
    }

    /** کلید شمارش تلاش‌ها: ترکیب ایمیل و آی‌پی درخواست. */
    private function throttleKey(Request $request, string $email): string
    {
        return 'hana-login|'.Str::lower($email).'|'.$request->ip();
    }

    /** برچسب فارسی زمان باقی‌مانده. */
    private function waitLabel(int $seconds): string
    {
        if ($seconds >= 60) {
            return $this->fa((string) (int) ceil($seconds / 60)).' دقیقه';
        }

        return $this->fa((string) max($seconds, 1)).' ثانیه';
    }

    /** تبدیل رقم‌های لاتین به فارسی. */
    private function fa(string $value): string
    {
        return strtr($value, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    /**
     * مقصد بعد از ورود موفق.
     * اگر روت داشبورد هنوز ثبت نشده باشد به مسیر پیش‌فرض آن می‌رویم.
     */
    private function homeUrl(): string
    {
        return Route::has('dashboard') ? route('dashboard') : '/dashboard';
    }
}
