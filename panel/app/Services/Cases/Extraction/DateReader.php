<?php

namespace App\Services\Cases\Extraction;

use App\Support\PersianValue;

/**
 * پاک‌سازی و اعتبارسنجی یک تاریخ شمسیِ خوانده‌شده از OCR.
 *
 * الگوی خرابی روی دادهٔ واقعی روشن است: **سال تقریباً همیشه سالم است** و
 * خرابی در ماه یا روز می‌افتد، آن هم بیشتر به‌شکل یک رقم اضافه
 * («۱۴۱۰/۱۱/۲۱» → «۱۴۱۰/۱۱/۲۱۰»، «۱۳۸۳/۰۳/۰۴» → «۱۳۸۳/۰۳/۵۰۴»).
 *
 * پس اگر ماه یا روز سه‌رقمی درآمد، دو برشِ دورقمی‌اش آزمایش می‌شود و آن که
 * از نظر تقویمی معتبر است برداشته می‌شود. اگر هر دو معتبر بودند نتیجه
 * «مبهم» علامت می‌خورد و اطمینانش پایین می‌آید — نه این‌که دور ریخته شود،
 * چون کارشناس با دیدن مقدار سریع‌تر تصمیم می‌گیرد تا با دیدن خانهٔ خالی.
 *
 * اعتبارسنجی تقویمی از PersianValue می‌آید (طول ماه و سال کبیسه) تا پنل و
 * موتور یک تعریف از «تاریخ درست» داشته باشند.
 */
final class DateReader
{
    /**
     * @param  array{raw: string, year: int, month: string, day: string}  $match
     * @return array{value: string, valid: bool, repaired: bool, ambiguous: bool}
     */
    public static function read(array $match): array
    {
        $year = $match['year'];

        [$month, $monthRepaired, $monthAmbiguous] = self::part(
            $match['month'],
            static fn (int $value): bool => $value >= 1 && $value <= 12,
        );

        $monthNumber = (int) $month;
        $maxDay = $monthNumber >= 1 && $monthNumber <= 12
            ? PersianValue::jalaliMonthLength($year, $monthNumber)
            : 31;

        [$day, $dayRepaired, $dayAmbiguous] = self::part(
            $match['day'],
            static fn (int $value): bool => $value >= 1 && $value <= $maxDay,
        );

        $value = $year.'/'.str_pad($month, 2, '0', STR_PAD_LEFT).'/'.str_pad($day, 2, '0', STR_PAD_LEFT);

        $valid = PersianValue::validate('jalali_date', $value, 'تاریخ') === null;

        return [
            'value' => $value,
            'valid' => $valid,
            'repaired' => $monthRepaired || $dayRepaired,
            'ambiguous' => $monthAmbiguous || $dayAmbiguous,
        ];
    }

    /**
     * یک جزء (ماه یا روز) را در صورت سه‌رقمی‌بودن ترمیم می‌کند.
     *
     * @param  callable(int): bool  $isSane
     * @return array{0: string, 1: bool, 2: bool}
     */
    private static function part(string $raw, callable $isSane): array
    {
        if (strlen($raw) <= 2) {
            return [$raw, false, false];
        }

        if (strlen($raw) !== 3) {
            return [$raw, false, false];
        }

        $head = substr($raw, 0, 2);
        $tail = substr($raw, 1, 2);

        $headOk = $isSane((int) $head);
        $tailOk = $isSane((int) $tail);

        if ($headOk && $tailOk) {
            return [$head, true, true];
        }

        if ($headOk) {
            return [$head, true, false];
        }

        if ($tailOk) {
            return [$tail, true, false];
        }

        return [$raw, false, false];
    }
}
