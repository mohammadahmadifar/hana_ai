<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * خطای موتور پایتون.
 *
 * پیام این استثنا همیشه فارسی و قابل نمایش مستقیم به کاربر است؛
 * جزئیات فنی در $detail نگه داشته می‌شود و فقط در لاگ می‌رود.
 */
class EngineException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $detail = '',
        public readonly string $command = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * پیام قابل نمایش به کاربر.
     */
    public function userMessage(): string
    {
        return $this->getMessage();
    }

    /**
     * زمینهٔ خطا برای لاگ.
     *
     * @return array<string, string>
     */
    public function context(): array
    {
        return [
            'command' => $this->command,
            'detail' => $this->detail,
        ];
    }

    // ------------------------------------------------------------------
    // سازنده‌های کمکی
    // ------------------------------------------------------------------

    public static function notConfigured(string $detail): self
    {
        return new self(
            'پیکربندی موتور پردازش تصویر ناقص است؛ با مدیر سامانه تماس بگیرید.',
            $detail,
        );
    }

    public static function timedOut(string $command, int $timeout, ?Throwable $previous = null): self
    {
        return new self(
            'پردازش بیش از '.self::faDigits($timeout).' ثانیه طول کشید و متوقف شد؛ دوباره تلاش کنید.',
            "command={$command} timeout={$timeout}",
            $command,
            $previous,
        );
    }

    public static function unreadableOutput(string $command, string $detail): self
    {
        return new self(
            'پاسخ موتور پردازش تصویر قابل خواندن نبود.',
            $detail,
            $command,
        );
    }

    public static function fromEngine(string $command, string $message, string $detail = ''): self
    {
        return new self($message, $detail, $command);
    }

    /**
     * تبدیل رقم‌های لاتین به فارسی برای پیام‌های قابل نمایش.
     */
    private static function faDigits(int|string $value): string
    {
        return strtr((string) $value, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }

    public static function crashed(string $command, string $detail, ?Throwable $previous = null): self
    {
        return new self(
            'اجرای موتور پردازش تصویر با خطا متوقف شد.',
            $detail,
            $command,
            $previous,
        );
    }
}
