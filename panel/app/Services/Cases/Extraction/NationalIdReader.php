<?php

namespace App\Services\Cases\Extraction;

use App\Support\PersianValue;

/**
 * خواندن کد ملی از رشته‌های رقمِ متن OCR.
 *
 * چرا «۱۰ رقم پشت سر هم» کافی نیست: OCR روی کارت ملی رقم اضافه می‌سازد.
 * یک کد ملی ده‌رقمی واقعی به‌شکل ۱۱ تا ۱۶ رقم خوانده می‌شود
 * (مثلاً ۸۱۴۳۰۳۷۳۸۱ → «۸۰۱۴۲۳۰۲۳۳۷۳۲۸۱۰»). ارقام درست به‌ترتیب داخل رشته
 * هستند، فقط ارقام جعلی لای آن‌ها نشسته‌اند.
 *
 * پس از هر رشتهٔ رقم، همهٔ **زیردنباله‌های ده‌رقمی** ساخته می‌شود و
 * `PersianValue::isValidNationalId()` (رقم کنترل) داور می‌شود. رقم کنترل
 * یازده‌یکِ حالت‌ها را رد می‌کند، پس بهترین سیگنالی است که در دست داریم و
 * مستقیم به confidence وصل می‌شود.
 *
 * برای مهار انفجار ترکیبیاتی دو قاعده:
 *   - رشتهٔ بلندتر از MAX_RUN فقط با پنجرهٔ پیوسته بررسی می‌شود
 *     (C(16,6) = ۸۰۰۸ نامزد، که آن‌قدر زیاد است که رقم کنترل هم دیگر
 *      جدا‌کننده نیست و جواب اشتباهِ بااطمینان می‌دهد).
 *   - میان نامزدهای معتبر، آن که حذف‌هایش از **دو سرِ** رشته است بر آن که
 *     از وسط حذف کرده ترجیح دارد؛ حذف از سر و ته (آشغال چسبیده به عدد)
 *     بسیار رایج‌تر از رقم جعلیِ وسط است.
 */
final class NationalIdReader
{
    private const LENGTH = 10;

    /** بلندتر از این فقط پنجرهٔ پیوسته آزمایش می‌شود. */
    private const MAX_RUN = 14;

    /**
     * @param  list<string>  $digitRuns  رشته‌های رقم با ارقام لاتین
     *                                   `alternatives` می‌گوید چند مقدارِ **متفاوتِ دیگر** هم رقم کنترل را رد
     *                                   می‌کنند. هرچه بیشتر، انتخاب ما بیشتر شبیه قرعه‌کشی است و اطمینان باید
     *                                   پایین‌تر بیاید — همین عدد در FieldExtractor از امتیاز کم می‌کند.
     * @return array{value: string, valid: bool, interior: int, trimmed: int, first: int, alternatives: int}|null
     */
    public static function best(array $digitRuns): ?array
    {
        $best = null;
        $valid = [];

        foreach ($digitRuns as $run) {
            $length = strlen($run);

            if ($length < self::LENGTH) {
                continue;
            }

            foreach (self::candidates($run) as $candidate) {
                if ($candidate['valid']) {
                    $valid[$candidate['value']] = true;
                }

                if ($best === null || self::better($candidate, $best)) {
                    $best = $candidate;
                }
            }
        }

        if ($best === null) {
            return null;
        }

        $best['alternatives'] = max(0, count($valid) - 1);

        return $best;
    }

    /**
     * @return list<array{value: string, valid: bool, interior: int, trimmed: int, first: int}>
     */
    private static function candidates(string $run): array
    {
        $length = strlen($run);
        $extra = $length - self::LENGTH;

        $keepSets = $extra === 0
            ? [range(0, self::LENGTH - 1)]
            : ($length > self::MAX_RUN
                ? self::windows($length)
                : self::subsequences($length, $extra));

        $out = [];

        foreach ($keepSets as $keep) {
            $value = '';

            foreach ($keep as $index) {
                $value .= $run[$index];
            }

            $leading = $keep[0];
            $trailing = $length - 1 - $keep[self::LENGTH - 1];

            $out[] = [
                'value' => $value,
                'valid' => PersianValue::isValidNationalId($value),
                'interior' => $extra - $leading - $trailing,
                'trimmed' => $leading + $trailing,
                'first' => $leading > 0 ? 0 : self::firstGap($keep),
            ];
        }

        return $out;
    }

    /** @return list<list<int>> پنجره‌های پیوستهٔ ده‌رقمی */
    private static function windows(int $length): array
    {
        $sets = [];

        for ($start = 0; $start + self::LENGTH <= $length; $start++) {
            $sets[] = range($start, $start + self::LENGTH - 1);
        }

        return $sets;
    }

    /**
     * همهٔ راه‌های حذف $extra رقم از رشته‌ای به طول $length.
     *
     * @return list<list<int>>
     */
    private static function subsequences(int $length, int $extra): array
    {
        $sets = [];

        $walk = function (int $start, array $dropped) use (&$walk, $length, $extra, &$sets): void {
            if (count($dropped) === $extra) {
                $sets[] = array_values(array_diff(range(0, $length - 1), $dropped));

                return;
            }

            for ($i = $start; $i < $length; $i++) {
                $walk($i + 1, [...$dropped, $i]);
            }
        };

        $walk(0, []);

        return $sets;
    }

    /** جای اولین رقمِ حذف‌شده (برای شکستن تساوی). */
    private static function firstGap(array $keep): int
    {
        foreach ($keep as $position => $index) {
            if ($index !== $position) {
                return $index;
            }
        }

        return $keep[self::LENGTH - 1] + 1;
    }

    /**
     * @param  array{value: string, valid: bool, interior: int, trimmed: int, first: int}  $a
     * @param  array{value: string, valid: bool, interior: int, trimmed: int, first: int}  $b
     */
    private static function better(array $a, array $b): bool
    {
        if ($a['valid'] !== $b['valid']) {
            return $a['valid'];
        }

        if ($a['interior'] !== $b['interior']) {
            return $a['interior'] < $b['interior'];
        }

        if ($a['trimmed'] !== $b['trimmed']) {
            return $a['trimmed'] < $b['trimmed'];
        }

        // در تساوی کامل، آن که دیرتر رقم انداخته برنده است: ارقام اولِ عدد
        // معمولاً سالم خوانده می‌شوند و آشغال به دُم عدد می‌چسبد.
        return $a['first'] > $b['first'];
    }
}
