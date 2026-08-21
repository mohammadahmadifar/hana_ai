<?php

namespace App\Services\Cases\Extraction;

/**
 * یک نامزد مقدار برای یک فیلد، به‌همراه اطمینانش.
 *
 * سه چیز جدا نگه داشته می‌شود:
 *   raw        همان چیزی که روی متن OCR دیده شد (برای نمایش به کارشناس)
 *   value      مقدار پاک‌شده که باید canonical شود (ورودی PersianValue::forEngine)
 *   confidence عدد ۰ تا ۱۰۰ — ورودی مستقیم تسک امتیازدهی
 *
 * strategy فقط برای اشکال‌زدایی و توضیح در گزارش است، ذخیره نمی‌شود.
 */
final class Candidate
{
    public function __construct(
        public readonly ?string $raw,
        public readonly ?string $value,
        public readonly float $confidence,
        public readonly string $strategy = 'none',
    ) {}

    public static function none(string $strategy = 'none'): self
    {
        return new self(null, null, 0.0, $strategy);
    }

    public function found(): bool
    {
        return $this->value !== null && trim($this->value) !== '';
    }

    /** همان نامزد با اطمینان محدودشده به بازهٔ ۰ تا ۱۰۰. */
    public function clamped(): self
    {
        $confidence = max(0.0, min(100.0, $this->confidence));

        return $confidence === $this->confidence
            ? $this
            : new self($this->raw, $this->value, $confidence, $this->strategy);
    }
}
