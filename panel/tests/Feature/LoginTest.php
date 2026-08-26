<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * تسک ۷۴۰ — نام کاربری ورود، کد ملی است نه ایمیل.
 *
 * دو چیزی که این فایل مواظبشان است و شکستنشان ساکت است:
 *   ۱) ارقام فارسی و لاتین باید به یک ردیف برسند — هم در جست‌وجوی کاربر، هم در
 *      کلید شمارش تلاش‌ها. اگر دومی جا بیفتد، سقف پنج تلاش با عوض‌کردن شکل
 *      ارقام دور زده می‌شود و کسی هم متوجه نمی‌شود.
 *   ۲) پیام خطا نباید بگوید کدام کد ملی در سامانه هست — «کاربر نیست» و «رمز
 *      غلط است» یک پیام می‌گیرند.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const NID = '0011111119';

    private const PASSWORD = 'hana-test-1405';

    public function test_login_form_asks_for_national_id_not_email(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('کد ملی');
        $response->assertSee('name="national_id"', false);
        $response->assertDontSee('name="email"', false);
    }

    public function test_user_logs_in_with_national_id(): void
    {
        $user = $this->activeUser();

        $this->post('/login', ['national_id' => self::NID, 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
    }

    /** کاربری که با صفحه‌کلید فارسی تایپ می‌کند باید همان‌جا برسد. */
    public function test_persian_digits_are_accepted(): void
    {
        $user = $this->activeUser();

        $this->post('/login', ['national_id' => '۰۰۱۱۱۱۱۱۱۹', 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));

        $this->assertSame($user->id, Auth::id());
    }

    /** جداکنندهٔ کپی‌شده از روی مدرک نباید ورود را بشکند. */
    public function test_separators_are_ignored(): void
    {
        $this->activeUser();

        $this->post('/login', ['national_id' => '001-111 1119', 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(Auth::check());
    }

    /** ایمیل دیگر نام کاربری نیست — حتی با رمز درست. */
    public function test_email_is_no_longer_a_username(): void
    {
        $this->activeUser();

        $this->from(route('login'))
            ->post('/login', ['national_id' => 'admin@hana.local', 'password' => self::PASSWORD])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('national_id');

        $this->assertFalse(Auth::check());
    }

    public function test_wrong_password_is_refused_with_a_persian_message(): void
    {
        $this->activeUser();

        $this->from(route('login'))
            ->post('/login', ['national_id' => self::NID, 'password' => 'wrong-password'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['auth' => 'کد ملی یا رمز عبور درست نیست.']);

        $this->assertFalse(Auth::check());
    }

    /**
     * کد ملیِ ناموجود دقیقاً همان پیام رمزِ غلط را می‌گیرد.
     *
     * وگرنه صفحهٔ ورود به یک ابزار «این کد ملی در سامانه هست یا نه» تبدیل می‌شود.
     */
    public function test_unknown_national_id_gives_the_same_message_as_a_wrong_password(): void
    {
        $this->activeUser();

        $this->from(route('login'))
            ->post('/login', ['national_id' => '0022222227', 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['auth' => 'کد ملی یا رمز عبور درست نیست.']);

        $this->assertFalse(Auth::check());
    }

    public function test_inactive_account_cannot_log_in(): void
    {
        $user = $this->activeUser();
        $user->forceFill(['is_active' => false])->save();

        $this->from(route('login'))
            ->post('/login', ['national_id' => self::NID, 'password' => self::PASSWORD])
            ->assertSessionHasErrors('auth');

        $this->assertFalse(Auth::check());
        $this->assertStringContainsString(
            'غیرفعال',
            (string) session('errors')->first('auth'),
        );
    }

    /** کد ملیِ بدشکل پیش از هر جست‌وجویی رد می‌شود. */
    public function test_malformed_national_id_is_rejected_before_any_lookup(): void
    {
        $this->activeUser();

        foreach (['', '12345', 'abcdefghij', '00111111190'] as $bad) {
            $this->from(route('login'))
                ->post('/login', ['national_id' => $bad, 'password' => self::PASSWORD])
                ->assertSessionHasErrors('national_id');
        }

        $this->assertFalse(Auth::check());
    }

    /**
     * سقف تلاش با عوض‌کردن شکل ارقام دور زده نمی‌شود.
     *
     * پنج تلاش با ارقام لاتین و ششمی با ارقام فارسی: اگر کلید شمارش روی مقدار
     * خامِ ورودی بسته شده باشد، تلاش ششم شمارندهٔ تازه‌ای باز می‌کند و قفل هرگز
     * نمی‌افتد — همان حالتی که با چشم دیده نمی‌شود.
     */
    public function test_throttle_counts_persian_and_latin_digits_together(): void
    {
        $this->activeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->from(route('login'))
                ->post('/login', ['national_id' => self::NID, 'password' => 'wrong-password'])
                ->assertSessionHasErrors('auth');
        }

        // ششمی با ارقام فارسی و **رمز درست** — باید به قفل بخورد، نه به ورود.
        $this->from(route('login'))
            ->followingRedirects()
            ->post('/login', ['national_id' => '۰۰۱۱۱۱۱۱۱۹', 'password' => self::PASSWORD])
            ->assertSee('ورود موقتاً بسته شده است');

        $this->assertFalse(Auth::check());
    }

    /**
     * ورودی آرایه‌ای صفحهٔ ورود را نمی‌ترکاند.
     *
     * مهاجم هر پارامتری را می‌تواند آرایه بفرستد (`national_id[]=1`). با امضای
     * رشته‌ایِ نرمال‌سازی، TypeError **پیش از** اعتبارسنجی می‌خورد و یک ناشناس
     * صفحهٔ ۵۰۰ می‌گیرد — با APP_DEBUG روشن یعنی دیدن کد و مسیر فایل‌ها.
     */
    public function test_array_input_is_a_validation_error_not_a_500(): void
    {
        $this->activeUser();

        $this->from(route('login'))
            ->post('/login', ['national_id' => ['1'], 'password' => self::PASSWORD])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('national_id');

        $this->assertFalse(Auth::check());
    }

    /**
     * ورود موفق، شمارندهٔ آی‌پی را پاک نمی‌کند.
     *
     * سقف آی‌پی برای پویشِ کد ملی‌هاست — حمله‌ای که ذاتاً از حساب‌های مختلف رد
     * می‌شود. اگر ورود موفق پاکش کند، هر کسی با یک حساب معتبر می‌تواند نوزده
     * حدس بزند، یک بار وارد شود، و شمارنده را برای همیشه صفر نگه دارد.
     */
    public function test_a_successful_login_does_not_reset_the_ip_scan_counter(): void
    {
        $this->activeUser();

        // نوزده حدس روی کد ملی‌های ناموجود (زیر سقف بیستِ آی‌پی).
        for ($i = 0; $i < 19; $i++) {
            $this->from(route('login'))
                ->post('/login', ['national_id' => '00'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'password' => 'x'])
                ->assertSessionHasErrors();
        }

        // ورود موفقِ خودِ مهاجم با حساب معتبرش (شمارنده هنوز زیر سقف است).
        $this->post('/login', ['national_id' => self::NID, 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));

        $this->post(route('logout'));

        // حدس بیستم شمارنده را به سقف می‌رساند …
        $this->from(route('login'))
            ->post('/login', ['national_id' => '0099999998', 'password' => 'x'])
            ->assertSessionHasErrors();

        // … و حدس بعدی باید قفل بخورد. اگر ورود موفق شمارنده را پاک کرده بود،
        // این‌جا دوباره پیام «کد ملی یا رمز عبور درست نیست» می‌آمد و پویش
        // بی‌پایان ادامه داشت.
        $this->from(route('login'))
            ->followingRedirects()
            ->post('/login', ['national_id' => '0099999999', 'password' => 'x'])
            ->assertSee('ورود موقتاً بسته شده است');
    }

    /** جداکننده‌های دیگری که از اکسل و پیام‌رسان کپی می‌شوند هم پاک می‌شوند. */
    public function test_dots_and_slashes_are_ignored_too(): void
    {
        $this->activeUser();

        $this->post('/login', ['national_id' => '001.111/1119', 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(Auth::check());
    }

    /** پیام «۱۰ رقم» باید بگوید صفرهای ابتدایی را هم بنویس. */
    public function test_short_code_message_mentions_leading_zeros(): void
    {
        $this->activeUser();

        $this->from(route('login'))
            ->followingRedirects()
            ->post('/login', ['national_id' => '11111119', 'password' => self::PASSWORD])
            ->assertSee('صفرهای ابتدایی');

        $this->assertFalse(Auth::check());
    }

    /** بعد از خروج، نشست باطل می‌شود. */
    public function test_logout_ends_the_session(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertFalse(Auth::check());
    }

    private function activeUser(): User
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $user->forceFill([
            'national_id' => self::NID,
            'role' => 'admin',
            'is_active' => true,
        ])->save();

        return $user->refresh();
    }
}
