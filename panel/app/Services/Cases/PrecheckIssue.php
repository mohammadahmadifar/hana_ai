<?php

namespace App\Services\Cases;

/**
 * یک ایراد در «اعتبارسنجی اولیه فایل».
 *
 * چرا کلاس و نه آرایهٔ خام: همین ایراد در سه جا مصرف می‌شود —
 * ستون `case_documents.precheck_issues`، ردیف `validation_results` با
 * scope=file، و پیام روی صفحهٔ نتیجهٔ پرونده. اگر آرایهٔ دستی بسازیم،
 * دیر یا زود سه شکل ناهماهنگ می‌شود.
 *
 * قاعدهٔ پیام: `messageFa` می‌گوید «مشکل چیست» و `hintFa` می‌گوید
 * «کاربر چه کند». هیچ‌کدام نباید «خطا رخ داد» باشد.
 */
final class PrecheckIssue
{
    /** ایراد بازدارنده — مدرک رد می‌شود و به OCR نمی‌رود. */
    public const ERROR = 'error';

    /** هشدار — مدرک قبول است ولی چیزی را نتوانستیم بسنجیم. */
    public const WARNING = 'warning';

    /**
     * @param  string  $code  کلید قرارداد، همیشه با پیشوند `file.` (مثل file.blurry)
     * @param  array<string, mixed>  $details  عددهای سنجیده‌شده و حد مجاز، برای لاگ و صفحهٔ نتیجه
     * @param  bool  $scored  آیا این ایراد ردیف validation_results بگیرد و امتیاز کم کند
     */
    public function __construct(
        public readonly string $code,
        public readonly string $messageFa,
        public readonly string $hintFa = '',
        public readonly string $severity = self::ERROR,
        public readonly array $details = [],
        public readonly bool $scored = true,
    ) {}

    /** @param array<string, mixed> $details */
    public static function error(string $code, string $messageFa, string $hintFa = '', array $details = []): self
    {
        return new self($code, $messageFa, $hintFa, self::ERROR, $details);
    }

    /** @param array<string, mixed> $details */
    public static function warning(string $code, string $messageFa, string $hintFa = '', array $details = []): self
    {
        return new self($code, $messageFa, $hintFa, self::WARNING, $details);
    }

    /**
     * راهنمای کیفیت: به کاربر نشان داده می‌شود ولی امتیاز را کم نمی‌کند.
     *
     * چرا لازم شد (تسک ۶۶۴): «تصویر از رزولوشن مرجع کوچک‌تر است» حرف درستی
     * است و کاربر باید ببیندش، ولی از وقتی موتور مدرک را در چند بزرگ‌نمایی
     * می‌خواند (تسک ۶۶۲) همان تصویر کوچک ممکن است بی‌عیب خوانده شود. کم‌کردن
     * امتیاز از پرونده‌ای که همهٔ فیلدهایش درست درآمده، جریمهٔ بی‌دلیل است.
     *
     * شدتش عمداً `warning` می‌ماند تا ویو همان ⚠️ آشنا را نشان دهد؛ تفاوت
     * فقط در امتیازدهی است، نه در ظاهر.
     *
     * @param  array<string, mixed>  $details
     */
    public static function notice(string $code, string $messageFa, string $hintFa = '', array $details = []): self
    {
        return new self($code, $messageFa, $hintFa, self::WARNING, $details, scored: false);
    }

    /** آیا این ایراد جلوی رفتن مدرک به OCR را می‌گیرد. */
    public function isBlocking(): bool
    {
        return $this->severity === self::ERROR;
    }

    /** وضعیت متناظر در جدول validation_results. */
    public function validationStatus(): string
    {
        return $this->isBlocking() ? 'failed' : 'warning';
    }

    /**
     * شکل ذخیره‌شده در `case_documents.precheck_issues`.
     *
     * سه کلید نخست قرارداد سایر تسک‌هاست؛ `severity` افزودنی است تا ویو
     * بتواند هشدار را از رد تفکیک کند.
     *
     * @return array{code: string, message_fa: string, hint_fa: string, severity: string, scored: bool}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message_fa' => $this->messageFa,
            'hint_fa' => $this->hintFa,
            'severity' => $this->severity,
            'scored' => $this->scored,
        ];
    }
}
