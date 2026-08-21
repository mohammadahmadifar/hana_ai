<?php

namespace App\Services\Cases\Extraction;

use App\Support\PersianValue;

/**
 * متن خام OCR، آمادهٔ استخراج فیلد.
 *
 * چرا یک کلاس جدا: متن OCR فارسی سه ویژگی دارد که هر بار باید یکسان با آن‌ها
 * رفتار شود، وگرنه هر فیلد قاعدهٔ خودش را می‌سازد و نتیجه غیرقابل‌توضیح می‌شود:
 *
 *   ۱) **ساختار سطری معنا دارد.** روی کارت ملی ترتیب سطرها همان ترتیب چاپ
 *      فیلدهاست، پس نباید مثل PersianValue::normalize() همهٔ سطرها را در یک
 *      رشته آب کرد. این‌جا اول سطر می‌شکنیم و بعد هر سطر را نرمال می‌کنیم.
 *   ۲) **ارقام سه شکل دارند** (فارسی، عربی، لاتین) و گاهی در یک سطر قاطی‌اند.
 *      برای هر سطر یک نسخهٔ «لاتین‌شده» هم نگه می‌داریم تا regex ساده بماند.
 *   ۳) **نیم‌فاصله (ZWNJ) گاهی جای فاصله می‌نشیند** — «شماره‌ملی» با «شماره ملی»
 *      یکی است. پس توکن‌بندی روی فاصله و نیم‌فاصله با هم انجام می‌شود.
 *
 * فاصلهٔ ویرایشی (Levenshtein) این‌جا mb-امن پیاده شده چون levenshtein()
 * داخلی PHP بایت‌محور است و برای فارسی (هر حرف ۲ بایت) عدد بی‌معنا می‌دهد.
 */
final class OcrText
{
    /** @var list<string> سطرهای نرمال‌شده و ناتهی */
    private array $lines = [];

    /** @var list<string> همان سطرها با ارقام لاتین */
    private array $latin = [];

    /** @var list<list<string>> توکن‌های هر سطر (شکسته روی فاصله و نیم‌فاصله) */
    private array $tokens = [];

    public function __construct(string $raw)
    {
        $pieces = preg_split("/\r\n|\r|\n/u", $raw) ?: [];

        foreach ($pieces as $piece) {
            $line = PersianValue::normalize($piece);

            if ($line === '') {
                continue;
            }

            $this->lines[] = $line;
            $this->latin[] = PersianValue::toEnglishDigits($line);
            $this->tokens[] = self::tokenize($line);
        }
    }

    /** @return list<string> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function count(): int
    {
        return count($this->lines);
    }

    public function line(int $index): ?string
    {
        return $this->lines[$index] ?? null;
    }

    /** همان سطر با ارقام لاتین — برای regex. */
    public function latin(int $index): ?string
    {
        return $this->latin[$index] ?? null;
    }

    /** @return list<string> */
    public function tokens(int $index): array
    {
        return $this->tokens[$index] ?? [];
    }

    /** @return list<string> */
    public static function tokenize(string $line): array
    {
        $parts = preg_split('/[\s\x{200C}]+/u', $line) ?: [];

        return array_values(array_filter($parts, static fn (string $t): bool => $t !== ''));
    }

    // ------------------------------------------------------------------
    // شکل مقدارها روی یک سطر
    // ------------------------------------------------------------------

    /**
     * رشته‌های پیوستهٔ رقم روی یک سطر (با ارقام لاتین).
     *
     * @return list<string>
     */
    public static function digitRuns(string $latinLine, int $minLength = 1): array
    {
        preg_match_all('/[0-9]+/', $latinLine, $matches);

        $runs = [];

        foreach ($matches[0] as $run) {
            if (strlen($run) >= $minLength) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    /**
     * تاریخ‌های شمسیِ محتمل روی یک سطر.
     *
     * سال باید چهار رقم و در بازهٔ منطقی باشد و نباید رقمی چسبیده به دو طرفش
     * باشد؛ وگرنه «۱۴۱۵۹/۵۲۳/۲۱» (که خرابیِ OCR است) به‌عنوان تاریخ سال ۴۱۵۹
     * پذیرفته می‌شد. ماه و روز تا سه رقم پذیرفته می‌شوند چون OCR رقم اضافه
     * می‌گذارد؛ ترمیمشان کار DateReader است نه این‌جا.
     *
     * @return list<array{raw: string, year: int, month: string, day: string}>
     */
    public static function dateMatches(string $latinLine): array
    {
        $pattern = '/(?<![0-9])([0-9]{4})\s*\/\s*([0-9]{1,3})\s*\/\s*([0-9]{1,3})(?![0-9])/';

        preg_match_all($pattern, $latinLine, $matches, PREG_SET_ORDER);

        $dates = [];

        foreach ($matches as $m) {
            $year = (int) $m[1];

            if ($year < 1200 || $year > 1500) {
                continue;
            }

            $dates[] = [
                'raw' => $m[0],
                'year' => $year,
                'month' => $m[2],
                'day' => $m[3],
            ];
        }

        return $dates;
    }

    /** آیا این توکن اصلاً می‌تواند بخشی از یک نام فارسی باشد؟ */
    public static function isPersianWord(string $token, int $minLength = 2): bool
    {
        if (! preg_match('/^[\p{Arabic}\x{200C}]+$/u', $token)) {
            return false;
        }

        return mb_strlen(str_replace("\u{200C}", '', $token)) >= $minLength;
    }

    // ------------------------------------------------------------------
    // شباهت رشته‌ها (برای برچسب‌های خراب‌شدهٔ OCR)
    // ------------------------------------------------------------------

    /** فاصلهٔ ویرایشی mb-امن. */
    public static function distance(string $a, string $b): int
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $n = count($x);
        $m = count($y);

        if ($n === 0) {
            return $m;
        }

        if ($m === 0) {
            return $n;
        }

        $previous = range(0, $m);

        for ($i = 1; $i <= $n; $i++) {
            $current = [$i];

            for ($j = 1; $j <= $m; $j++) {
                $cost = $x[$i - 1] === $y[$j - 1] ? 0 : 1;

                $current[$j] = min(
                    $previous[$j] + 1,
                    $current[$j - 1] + 1,
                    $previous[$j - 1] + $cost,
                );
            }

            $previous = $current;
        }

        return $previous[$m];
    }

    /** شباهت ۰ تا ۱ (۱ یعنی یکسان). */
    public static function similarity(string $a, string $b): float
    {
        $longest = max(mb_strlen($a), mb_strlen($b));

        if ($longest === 0) {
            return 1.0;
        }

        return 1.0 - (self::distance($a, $b) / $longest);
    }
}
