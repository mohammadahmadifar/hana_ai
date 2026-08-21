<?php

namespace App\Services\Cases\Validation;

use App\Support\PersianValue;

/**
 * یک مقدار خواندهٔ‌شده از روی یک مدرک، آمادهٔ مقایسه.
 *
 * چرا لازم است: مقایسهٔ ضدجعل باید بداند «چه مقداری»، «از روی کدام مدرک» و
 * «با چه اطمینانی» خوانده شده است. بدون اطمینان، یک اشتباهِ OCR به‌سادگی
 * «جعل قطعی» گزارش می‌شود. پس هر مقدار سه‌گانهٔ (مدرک، مقدار، اطمینان) را
 * با خودش حمل می‌کند و دو شکل دارد:
 *   canonical  — شکل قانونی برای نمایش (PersianValue::forEngine)
 *   compareKey — شکل ساده‌شده برای مقایسه (بدون فاصله و علائم، ارقام لاتین)
 */
final class FieldValue
{
    /**
     * @param  list<string>  $partKeys  کلیدهای فیلدی که این مقدار از آن‌ها ساخته شده
     */
    private function __construct(
        public readonly string $documentKey,
        public readonly string $documentLabel,
        public readonly ?int $documentId,
        public readonly string $fieldKey,
        public readonly string $fieldLabel,
        public readonly string $valueType,
        public readonly string $canonical,
        public readonly float $confidence,
        public readonly string $source,
        public readonly array $partKeys,
    ) {}

    public static function make(
        string $documentKey,
        string $documentLabel,
        ?int $documentId,
        string $fieldKey,
        string $fieldLabel,
        string $valueType,
        ?string $rawValue,
        float $confidence,
        string $source,
    ): self {
        return new self(
            $documentKey,
            $documentLabel,
            $documentId,
            $fieldKey,
            $fieldLabel,
            $valueType,
            PersianValue::forEngine($valueType, $rawValue),
            max(0.0, min(100.0, $confidence)),
            $source === 'manual' ? 'manual' : 'ocr',
            [$fieldKey],
        );
    }

    /**
     * چند فیلد یک مدرک را به یک مقدار منطقی می‌چسباند.
     *
     * این همان پلی است که «نام + نام خانوادگی» کارت ملی را به «نام و نام
     * خانوادگی» گواهینامه می‌رساند. اطمینانِ حاصل، کمترینِ اطمینان اجزاست:
     * اگر یکی از دو تکه بد خوانده شده باشد، کل نام قابل اتکا نیست.
     *
     * @param  non-empty-list<self>  $parts
     */
    public static function composite(array $parts, string $logicalKey): self
    {
        $first = $parts[0];

        return new self(
            $first->documentKey,
            $first->documentLabel,
            $first->documentId,
            $logicalKey,
            implode(' و ', array_map(static fn (self $p): string => $p->fieldLabel, $parts)),
            $first->valueType,
            PersianValue::normalize(implode(' ', array_map(
                static fn (self $p): string => $p->canonical,
                $parts,
            ))),
            min(array_map(static fn (self $p): float => $p->confidence, $parts)),
            in_array('ocr', array_map(static fn (self $p): string => $p->source, $parts), true) ? 'ocr' : 'manual',
            array_merge(...array_map(static fn (self $p): array => $p->partKeys, $parts)),
        );
    }

    public function isEmpty(): bool
    {
        return $this->canonical === '';
    }

    /**
     * آیا این خوانده‌شده آن‌قدر مطمئن است که بشود روی آن «رد قطعی» صادر کرد؟
     * مقدار دست‌نویسِ کارشناس همیشه مطمئن است.
     */
    public function isTrusted(float $minConfidence): bool
    {
        return $this->source === 'manual' || $this->confidence >= $minConfidence;
    }

    /** شکل نمایشی برای پیام فارسی — ارقام فارسی. */
    public function display(): string
    {
        return PersianValue::toPersianDigits($this->canonical);
    }

    /** شکل مقایسه — OCR فاصله و ی/ک را جابه‌جا می‌کند، پس مقایسهٔ خام ممنوع. */
    public function compareKey(): string
    {
        return PersianValue::compareKey($this->canonical);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'document' => $this->documentKey,
            'document_label' => $this->documentLabel,
            'field' => $this->fieldKey,
            'field_label' => $this->fieldLabel,
            'value' => $this->display(),
            'confidence' => round($this->confidence, 2),
            'source' => $this->source,
        ];
    }
}
