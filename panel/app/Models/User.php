<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\PersianValue;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * نقش‌های سامانه — کلید نقش => برچسب فارسی.
     *
     * چهار نقش، دقیقاً به اندازهٔ چهار کارِ متفاوتِ فرایند مجوز:
     *   admin      همه‌چیز، به‌علاوهٔ کاربران و آستانه‌های امتیازدهی
     *   expert     بررسی انسانی پرونده و تصمیم نهایی
     *   data       دیتاست و تگ‌گذاری و خروجی آموزش
     *   applicant  متقاضی: فقط درخواست خودش را می‌دهد و نتیجه‌اش را می‌بیند
     *
     * چرا «متقاضی» نقش جداست و کار کارشناس نیست: بررسی انسانی یعنی کسی غیر از
     * ثبت‌کنندهٔ پرونده آن را بازبینی کند. تا وقتی کارشناس خودش پرونده را
     * می‌ساخت، می‌توانست ساختهٔ خودش را هم تایید کند و «بررسی» بی‌معنا می‌شد.
     */
    public const ROLES = [
        'admin' => 'مدیر سامانه',
        'expert' => 'کارشناس بررسی',
        'data' => 'کارشناس داده',
        'applicant' => 'متقاضی',
    ];

    /** نقش پیش‌فرض حسابِ تازه — همان چیزی که ستون role در دیتابیس دارد. */
    public const DEFAULT_ROLE = 'expert';

    /**
     * نام کاربری ورود، کد ملی است نه ایمیل (تسک ۷۴۰).
     *
     * ستون `national_id` همیشه با **ارقام لاتین** و بدون جداکننده ذخیره می‌شود.
     * هر جایی که با ورودی کاربر مقایسه‌اش می‌کند باید اول از همین متد رد شود،
     * وگرنه «۰۰۱۱۱۱۱۱۱۹» و «0011111119» دو کاربر متفاوت می‌شوند و ایندکس یکتا
     * هم جلویش را نمی‌گیرد — همان کاربر، بسته به صفحه‌کلیدش، گاهی وارد می‌شود و
     * گاهی نه.
     *
     * ورودی `mixed` است نه `?string`، چون مهاجم هر پارامتری را می‌تواند آرایه
     * بفرستد (`national_id[]=1`). با امضای رشته‌ای، همین‌جا — **پیش از
     * اعتبارسنجی** — TypeError می‌خورد و صفحهٔ ورودِ احرازنشده ۵۰۰ می‌دهد؛ با
     * APP_DEBUG روشن یعنی نمایش کد و مسیر فایل‌ها به یک ناشناس. مقدار
     * غیرمتنی رشتهٔ خالی می‌شود تا قاعدهٔ `required` پیام فارسی درست بدهد.
     * همان گاردی که `UserController::queryText()` برای پارامترهای کوئری دارد.
     */
    public static function normalizeNationalId(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        // جداکننده‌هایی که واقعاً از روی مدرک یا از اکسل کپی می‌شوند: فاصله،
        // خط تیرهٔ لاتین و کوتاه و بلند، نیم‌فاصله، نقطه، اسلش و زیرخط. عمداً
        // «هر چیزِ غیررقم» حذف نمی‌شود، وگرنه «abc0011111119xyz» هم یک نام
        // کاربری معتبر می‌شد.
        return str_replace(
            [' ', '-', '–', '—', '‌', '.', '/', '_'],
            '',
            PersianValue::toEnglishDigits(PersianValue::normalize((string) $value)),
        );
    }

    /** کد ملی به شکل خواندنی برای رابط فارسی. */
    public function nationalIdLabel(): string
    {
        return $this->national_id === null
            ? '—'
            : PersianValue::toPersianDigits((string) $this->national_id);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** متقاضی: بیرون‌ترین حلقه — نه دیتاست می‌بیند، نه پروندهٔ دیگران. */
    public function isApplicant(): bool
    {
        return $this->role === 'applicant';
    }

    /**
     * آیا این کاربر می‌تواند درخواست خدمت ثبت کند؟
     *
     * جدا از canReviewCases() است: متقاضی پرونده می‌سازد ولی بررسی نمی‌کند،
     * و کارشناس داده هیچ‌کدام را.
     */
    public function canSubmitCases(): bool
    {
        return in_array($this->role, ['admin', 'expert', 'applicant'], true);
    }

    /** آیا این کاربر به بخش داده و تگ‌گذاری دسترسی دارد؟ */
    public function canManageDataset(): bool
    {
        return in_array($this->role, ['admin', 'data'], true);
    }

    /** آیا این کاربر می‌تواند پرونده بررسی و تصمیم‌گیری کند؟ */
    public function canReviewCases(): bool
    {
        return in_array($this->role, ['admin', 'expert'], true);
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }
}
