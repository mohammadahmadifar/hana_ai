<?php

namespace App\Services\Cases\Extraction;

/**
 * پیدا کردن برچسب یک فیلد روی سطرهای متن OCR.
 *
 * برچسب می‌تواند چندکلمه‌ای باشد («نام خانوادگی»)، پس روی توکن‌های سطر پنجرهٔ
 * لغزان می‌اندازیم و پنجره را با برچسب مقایسه می‌کنیم. عرض پنجره یکی کمتر و
 * یکی بیشتر از تعداد کلمه‌های برچسب هم آزمایش می‌شود، چون OCR گاهی دو کلمه را
 * به‌هم می‌چسباند («شماره‌ملی») و گاهی یک کلمه را دو تکه می‌کند («نما بنده»).
 *
 * خروجی می‌گوید برچسب **دقیق** بود یا **فازی** — همین تفاوت مستقیم به
 * confidence فیلد می‌رود.
 */
final class LabelLocator
{
    /** بیشترین نسبت خطای پذیرفته‌شده برای تطبیق فازی برچسب. */
    private const FUZZY_LIMIT = 0.34;

    /**
     * @param  list<string>  $labels
     * @return array{line: int, tokenEnd: int, exact: bool, ratio: float}|null
     */
    public static function find(OcrText $text, array $labels, int $fromLine = 0, int $toLine = PHP_INT_MAX): ?array
    {
        $best = null;

        for ($i = max(0, $fromLine); $i < min($text->count(), $toLine); $i++) {
            $hit = self::findOnLine($text->tokens($i), $labels);

            if ($hit === null) {
                continue;
            }

            $hit['line'] = $i;

            // برچسب دقیق همیشه بر فازی می‌چربد، وگرنه کم‌خطاترین برنده است
            if ($best === null
                || ($hit['exact'] && ! $best['exact'])
                || ($hit['exact'] === $best['exact'] && $hit['ratio'] < $best['ratio'])) {
                $best = $hit;
            }

            if ($best['exact'] && $best['ratio'] === 0.0) {
                break;
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $labels
     * @return array{tokenEnd: int, exact: bool, ratio: float}|null
     */
    public static function findOnLine(array $tokens, array $labels): ?array
    {
        $count = count($tokens);
        $best = null;

        foreach ($labels as $label) {
            $words = max(1, count(OcrText::tokenize($label)));
            $needle = self::comparable($label);

            if ($needle === '') {
                continue;
            }

            foreach ([$words, $words + 1, $words - 1] as $width) {
                if ($width < 1) {
                    continue;
                }

                for ($start = 0; $start + $width <= $count; $start++) {
                    $window = self::comparable(implode(' ', array_slice($tokens, $start, $width)));

                    if ($window === '') {
                        continue;
                    }

                    $distance = OcrText::distance($window, $needle);
                    $ratio = $distance / max(mb_strlen($window), mb_strlen($needle));

                    if ($ratio > self::FUZZY_LIMIT) {
                        continue;
                    }

                    if ($best === null || $ratio < $best['ratio']) {
                        $best = [
                            'tokenEnd' => $start + $width,
                            'exact' => $distance === 0,
                            'ratio' => $ratio,
                        ];
                    }
                }
            }
        }

        return $best;
    }

    /** شکل قابل‌مقایسه: بدون نیم‌فاصله، بدون نقطه‌گذاری، حروف لاتین کوچک. */
    private static function comparable(string $value): string
    {
        $value = str_replace("\u{200C}", '', $value);
        $value = preg_replace('/[^\p{Arabic}a-zA-Z0-9 ]+/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim(mb_strtolower($value));
    }
}
