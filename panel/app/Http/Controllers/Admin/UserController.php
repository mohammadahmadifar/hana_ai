<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PersianValue;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * مدیریت کاربران — فقط برای نقش «مدیر سامانه».
 *
 * ثبت‌نام عمومی وجود ندارد؛ هر حساب کاربری از همین‌جا ساخته می‌شود.
 * دو محافظت سمت سرور (نه فقط در رابط کاربری):
 *   ۱) مدیر نمی‌تواند نقش خودش را عوض کند.
 *   ۲) مدیر نمی‌تواند حساب خودش را غیرفعال کند.
 */
class UserController extends Controller
{
    /** فهرست کاربران، با جست‌وجوی ساده و پالایش بر اساس نقش. */
    public function index(Request $request): View
    {
        // ورودی‌های کوئری ممکن است آرایه باشند (مثل ?q[]=a)؛ در آن حالت نادیده گرفته می‌شوند
        // تا به جای صفحهٔ خطای ۵۰۰، فهرست بدون پالایش نمایش داده شود.
        $q = trim(self::queryText($request, 'q'));
        $role = self::queryText($request, 'role');

        $users = User::query()
            ->when($q !== '', function ($builder) use ($q) {
                // جست‌وجو با کد ملی هم کار می‌کند و همان‌جا به لاتین تبدیل
                // می‌شود: از تسک ۷۴۰ نام کاربری همین است، پس مدیر معمولاً همین
                // را در دست دارد — و اگر از روی مدرک با ارقام فارسی رونویسی کند
                // نباید دست خالی برگردد.
                $digits = User::normalizeNationalId($q);

                $builder->where(function ($inner) use ($q, $digits) {
                    $inner->where('name', 'like', '%'.$q.'%')
                        ->orWhere('email', 'like', '%'.$q.'%');

                    if ($digits !== '') {
                        $inner->orWhere('national_id', 'like', '%'.$digits.'%');
                    }
                });
            })
            ->when(array_key_exists($role, User::ROLES), fn ($builder) => $builder->where('role', $role))
            ->orderBy('id')
            ->get();

        $users->each(function (User $user) {
            $user->setAttribute('created_fa', self::jalali($user->created_at));
        });

        return view('admin.users.index', [
            'users' => $users,
            'q' => $q,
            'role' => $role,
            'roles' => User::ROLES,
            'activeCount' => $users->where('is_active', true)->count(),
            // شمار مدیران فعال یک واقعیت سراسری است، نه نتیجهٔ پالایش جاری؛
            // برای همین با کوئری جدا روی کل جدول شمرده می‌شود.
            'adminCount' => User::query()->where('role', 'admin')->where('is_active', true)->count(),
        ]);
    }

    /** فرم ساخت کاربر تازه. */
    public function create(): View
    {
        return view('admin.users.create', [
            'roles' => User::ROLES,
        ]);
    }

    /** ذخیرهٔ کاربر تازه. */
    public function store(Request $request): RedirectResponse
    {
        $request->merge(['national_id' => User::normalizeNationalId($request->input('national_id'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', Rule::unique('users', 'email')],
            'national_id' => self::nationalIdRules(),
            'role' => ['required', 'string', Rule::in(array_keys(User::ROLES))],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ], self::messages(), self::attributes());

        // ستون‌های role و is_active در مدل fillable نیستند؛ عمداً مستقیم مقدار می‌گیرند.
        $user = new User();
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->national_id = $data['national_id'];
        $user->role = $data['role'];
        $user->is_active = $request->boolean('is_active');
        $user->password = Hash::make($data['password']);
        $user->save();

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'کاربر «'.$user->name.'» ساخته شد.');
    }

    /** فرم ویرایش کاربر. */
    public function edit(Request $request, User $user): View
    {
        return view('admin.users.edit', [
            'user' => $user,
            'roles' => User::ROLES,
            'isSelf' => $request->user()->is($user),
        ]);
    }

    /** به‌روزرسانی کاربر. تغییر رمز اختیاری است. */
    public function update(Request $request, User $user): RedirectResponse
    {
        $isSelf = $request->user()->is($user);

        $request->merge(['national_id' => User::normalizeNationalId($request->input('national_id'))]);

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:72', 'confirmed'],
        ];

        // مدیر نمی‌تواند نقش خودش را عوض کند، خودش را غیرفعال کند، یا کد ملی
        // خودش را دست بزند؛ برای همین این سه ورودی برای حساب خودش اصلاً
        // اعتبارسنجی و اعمال نمی‌شوند.
        //
        // کد ملی از تسک ۷۴۰ **نام کاربری ورود** است، پس تنها فیلدی است که
        // اشتباه نوشتنش مدیر را از سامانه بیرون می‌گذارد — و سامانه نه ثبت‌نام
        // دارد نه بازیابی رمز، یعنی راه برگشتی از رابط کاربری نیست. دقیقاً همان
        // منطقِ «نقش خودت را عوض نکن»: از مدیر دیگری بخواهید.
        if (! $isSelf) {
            $rules['role'] = ['required', 'string', Rule::in(array_keys(User::ROLES))];
            $rules['national_id'] = self::nationalIdRules($user->id);
        }

        $data = $request->validate($rules, self::messages(), self::attributes());

        $user->name = $data['name'];
        $user->email = $data['email'];

        if (! $isSelf) {
            $user->national_id = $data['national_id'];
            $user->role = $data['role'];
            $user->is_active = $request->boolean('is_active');

            // غیرفعال‌سازی باید فوری باشد: تا وقتی remember_token سر جایش بماند،
            // کوکی «مرا به خاطر بسپار» همچنان یک اعتبارنامهٔ معتبر است و اگر بعداً
            // حساب دوباره فعال شود همان کوکی قدیمی زنده می‌شود.
            if (! $user->is_active) {
                $user->setRememberToken(null);
            }
        }

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);

            // تغییر رمز همهٔ اعتبارنامه‌های قبلی را باطل می‌کند:
            // هم کوکی «مرا به خاطر بسپار» و هم نشست‌های باز روی دستگاه‌های دیگر
            // (اثر انگشت رمز در نشست را EnsureRole بررسی می‌کند).
            $user->setRememberToken(null);
        }

        $user->save();

        // نشست خود مدیری که همین الان رمزش را عوض کرد نباید قربانی همان بررسی شود؛
        // اثر انگشت را پاک می‌کنیم تا در درخواست بعدی با رمز تازه ساخته شود.
        if ($isSelf && ! empty($data['password'])) {
            $request->session()->forget(self::passwordFingerprintKey());
        }

        $note = $isSelf
            ? 'حساب خودتان به‌روزرسانی شد. (کد ملی، نقش و وضعیت حساب خودتان قابل تغییر نیست.)'
            : 'کاربر «'.$user->name.'» به‌روزرسانی شد.';

        return redirect()->route('admin.users.index')->with('success', $note);
    }

    /** غیرفعال‌سازی کاربر (حذف نمی‌کنیم تا سابقهٔ پرونده‌ها نشکند). */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()->is($user)) {
            return redirect()
                ->route('admin.users.index')
                ->with('error', 'نمی‌توانید حساب خودتان را غیرفعال کنید.');
        }

        if (! $user->is_active) {
            return redirect()
                ->route('admin.users.index')
                ->with('error', 'کاربر «'.$user->name.'» از قبل غیرفعال بود.');
        }

        $user->is_active = false;
        $user->setRememberToken(null);
        $user->save();

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'کاربر «'.$user->name.'» غیرفعال شد و دیگر نمی‌تواند وارد شود.');
    }

    /**
     * قاعدهٔ کد ملی — یک جا تعریف می‌شود و دو جا (ساخت و ویرایش) استفاده.
     *
     * `digits:10` شکل را می‌گیرد و قاعدهٔ بسته رقم کنترل را؛ همان الگوریتمی که
     * اعتبارسنجی مدارک روی کد ملیِ خوانده‌شده اجرا می‌کند. دو تعریف موازی از
     * «کد ملی درست» یعنی حسابی ساخته می‌شود که مدارک خودش را رد می‌کند.
     *
     * @return list<mixed>
     */
    private static function nationalIdRules(?int $ignoreId = null): array
    {
        return [
            // bail لازم است: بدون آن، ورودی پنج‌رقمی هم قاعدهٔ رقم کنترل را
            // اجرا می‌کند و هم یک کوئری unique می‌زند، و کنار پیام «۱۰ رقم»
            // پیام گمراه‌کنندهٔ «وجود خارجی ندارد» می‌نشیند.
            'bail',
            'required',
            'string',
            'digits:10',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! PersianValue::isValidNationalId((string) $value)) {
                    $fail('رقم کنترل کد ملی درست نیست؛ این کد ملی وجود خارجی ندارد.');
                }
            },
            $ignoreId === null
                ? Rule::unique('users', 'national_id')
                : Rule::unique('users', 'national_id')->ignore($ignoreId),
        ];
    }

    /**
     * خواندن یک ورودی کوئری به‌صورت متن.
     *
     * مهاجم می‌تواند هر پارامتری را آرایه بفرستد (?q[]=a). بدون این گارد،
     * تبدیل آرایه به رشته خطای ۵۰۰ می‌دهد؛ اینجا مقدار غیرمتنی نادیده گرفته می‌شود.
     */
    private static function queryText(Request $request, string $key): string
    {
        $value = $request->query($key, '');

        return is_scalar($value) ? (string) $value : '';
    }

    /** کلید نشستِ «اثر انگشت رمز» — همان کلیدی که میان‌افزار EnsureRole می‌خواند. */
    private static function passwordFingerprintKey(): string
    {
        return 'password_hash_'.Auth::getDefaultDriver();
    }

    /** پیام‌های فارسی اعتبارسنجی. */
    private static function messages(): array
    {
        return [
            'required' => 'پر کردن :attribute الزامی است.',
            'string' => 'مقدار :attribute باید متن باشد.',
            'email' => 'قالب :attribute درست نیست.',
            'max' => ':attribute طولانی‌تر از حد مجاز است.',
            'min' => ':attribute کوتاه‌تر از حد مجاز است.',
            'name.max' => 'نام و نام خانوادگی نباید بیش از ۱۲۰ نویسه باشد.',
            'email.max' => 'ایمیل نباید بیش از ۱۹۰ نویسه باشد.',
            'national_id.required' => 'کد ملی الزامی است؛ کاربر با همین کد وارد سامانه می‌شود.',
            'national_id.digits' => 'کد ملی باید دقیقاً ۱۰ رقم باشد (ارقام فارسی یا لاتین). '
                .'اگر صفرهای ابتدایی افتاده‌اند، آن‌ها را هم بنویسید.',
            'password.min' => 'رمز عبور باید دست‌کم ۸ نویسه باشد.',
            'password.max' => 'رمز عبور نباید بیش از ۷۲ نویسه باشد.',
            'unique' => 'این :attribute قبلاً برای کاربر دیگری ثبت شده است.',
            'in' => 'مقدار انتخاب‌شده برای :attribute معتبر نیست.',
            'confirmed' => 'تکرار رمز عبور با خود رمز یکی نیست.',
        ];
    }

    /** نام فارسی فیلدها. */
    private static function attributes(): array
    {
        return [
            'name' => 'نام و نام خانوادگی',
            'email' => 'ایمیل',
            'national_id' => 'کد ملی',
            'role' => 'نقش',
            'password' => 'رمز عبور',
            'is_active' => 'وضعیت حساب',
        ];
    }

    /** تبدیل تاریخ میلادی به شمسی با رقم‌های فارسی (بدون کتابخانهٔ بیرونی). */
    private static function jalali(mixed $date): string
    {
        if ($date === null) {
            return '—';
        }

        $date = $date->copy()->timezone('Asia/Tehran');
        [$jy, $jm, $jd] = self::toJalali((int) $date->year, (int) $date->month, (int) $date->day);

        $text = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);

        return strtr($text, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    /** الگوریتم استاندارد تبدیل گاه‌شماری میلادی به هجری شمسی. */
    private static function toJalali(int $gy, int $gm, int $gd): array
    {
        $monthDays = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400) + $gd + $monthDays[$gm - 1];

        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;

        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }
}
