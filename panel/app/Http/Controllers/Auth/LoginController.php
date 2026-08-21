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

    /**
     * سقف تلاش ناموفق برای یک آی‌پی، مستقل از ایمیل.
     *
     * بدون این سقف، مهاجم می‌تواند با عوض کردن ایمیل در هر تلاش
     * بی‌نهایت آدرس را از یک آی‌پی امتحان کند و فهرست کاربران را بسازد.
     */
    private const MAX_IP_ATTEMPTS = 20;

    /**
     * هش ساختگی برای هم‌زمان‌سازی پاسخ.
     *
     * وقتی ایمیل در پایگاه داده نیست، به‌جای بازگشت فوری، یک بررسی هش روی همین
     * مقدار انجام می‌شود تا زمان پاسخ «کاربر هست» و «کاربر نیست» یکی بماند.
     * با هزینهٔ (cost) پیش‌فرض پروژه ساخته شده؛ اگر BCRYPT_ROUNDS عوض شد، این را هم بازتولید کنید.
     */
    private const TIMING_DUMMY_HASH = '$2y$12$tE9d3PIjhWkhm2mF2SzP.e6InT4MXvUKqHGKP/DMMf/5GwQlGhQnO';

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
        $ipKey = $this->ipThrottleKey($request);

        // دو پنجرهٔ محدودیت:
        //   ۱) هر «ایمیل + آی‌پی»: ۵ تلاش ناموفق در دقیقه (حمله به یک حساب مشخص).
        //   ۲) خود آی‌پی: ۲۰ تلاش ناموفق در دقیقه (پویش فهرست ایمیل‌ها با عوض کردن آدرس).
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return $this->failed($request, $this->lockedMessage(RateLimiter::availableIn($key)));
        }

        if (RateLimiter::tooManyAttempts($ipKey, self::MAX_IP_ATTEMPTS)) {
            return $this->failed($request, $this->lockedMessage(RateLimiter::availableIn($ipKey)));
        }

        $user = User::query()->where('email', $data['email'])->first();

        // بررسی هش همیشه انجام می‌شود — چه کاربر وجود داشته باشد چه نه — تا زمان پاسخ
        // نگوید کدام ایمیل‌ها در سامانه ثبت‌شده‌اند.
        $passwordOk = Hash::check(
            $data['password'],
            $user !== null ? (string) $user->password : self::TIMING_DUMMY_HASH
        );

        if ($user === null || ! $passwordOk) {
            $this->recordFailure($key, $ipKey);

            return $this->failed($request, 'ایمیل یا رمز عبور درست نیست.');
        }

        if (! $user->is_active) {
            $this->recordFailure($key, $ipKey);

            return $this->failed(
                $request,
                'حساب کاربری شما غیرفعال است و امکان ورود ندارد. برای فعال‌سازی با مدیر سامانه تماس بگیرید.'
            );
        }

        RateLimiter::clear($key);
        RateLimiter::clear($ipKey);

        Auth::login($user, $request->boolean('remember'));

        // جلوگیری از تثبیت نشست (session fixation).
        $request->session()->regenerate();

        // regenerate فقط شناسهٔ نشست را عوض می‌کند و محتوایش را نگه می‌دارد؛ اگر روی
        // همین مرورگر قبلاً کاربر دیگری وارد بوده، اثر انگشت رمزِ او باقی می‌ماند و
        // EnsureRole کاربر تازه را بی‌دلیل بیرون می‌اندازد. پس پاکش می‌کنیم تا در
        // درخواست بعدی برای همین کاربر ساخته شود.
        $request->session()->forget('password_hash_'.Auth::getDefaultDriver());

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

    /** ثبت یک تلاش ناموفق روی هر دو پنجرهٔ محدودیت. */
    private function recordFailure(string $key, string $ipKey): void
    {
        RateLimiter::hit($key, self::DECAY_SECONDS);
        RateLimiter::hit($ipKey, self::DECAY_SECONDS);
    }

    /** کلید شمارش تلاش‌ها: ترکیب ایمیل و آی‌پی درخواست. */
    private function throttleKey(Request $request, string $email): string
    {
        return 'hana-login|'.Str::lower($email).'|'.$request->ip();
    }

    /** کلید شمارش تلاش‌ها فقط بر پایهٔ آی‌پی، مستقل از ایمیل واردشده. */
    private function ipThrottleKey(Request $request): string
    {
        return 'hana-login-ip|'.$request->ip();
    }

    /** پیام قفل موقت ورود — زمان دقیق از خود RateLimiter خوانده می‌شود. */
    private function lockedMessage(int $seconds): string
    {
        return 'به دلیل تلاش‌های ناموفق پیاپی، ورود موقتاً بسته شده است. '
            .'لطفاً پس از '.$this->waitLabel($seconds).' دوباره تلاش کنید.';
    }

    /** برچسب فارسی زمان باقی‌مانده — دقیقاً همان واحدی که RateLimiter گزارش می‌دهد. */
    private function waitLabel(int $seconds): string
    {
        $seconds = max($seconds, 1);

        if ($seconds >= 60) {
            return $this->fa((string) (int) ceil($seconds / 60)).' دقیقه';
        }

        return $this->fa((string) $seconds).' ثانیه';
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
