<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * تبدیل گاه‌شماری میلادی به هجری شمسی — بدون هیچ کتابخانهٔ بیرونی.
 *
 * چرا این‌جا؟ در پروژه اجازهٔ نصب پکیج نداریم و چند بخش (دیتاست، پرونده‌ها،
 * خروجی‌ها) به تاریخ شمسی نیاز دارند. پس یک منبع حقیقت واحد این‌جاست.
 *
 * روش استفاده:
 *   Jalali::format($sample->created_at)          → ۱۴۰۵/۰۵/۳۰
 *   Jalali::format($sample->created_at, true)    → ۱۴۰۵/۰۵/۳۰ ۱۴:۳۲
 *   Jalali::long($case->created_at)              → ۳۰ مرداد ۱۴۰۵
 *   Jalali::digits(1250)                         → ۱۲۵۰
 *   Jalali::fromGregorian(2026, 8, 21)           → [1405, 5, 30]
 *   Jalali::toGregorian(1405, 5, 30)             → [2026, 8, 21]
 *
 * نکته: تاریخ‌ها در دیتابیس با منطقه‌زمانی برنامه (UTC) ذخیره می‌شوند؛
 * پیش از تبدیل، لحظه به منطقه‌زمانی نمایش (پیش‌فرض تهران) برده می‌شود تا
 * روزِ نشان‌داده‌شده همان چیزی باشد که کاربر ایرانی انتظار دارد.
 */
final class Jalali
{
    /** چیزی که به‌جای تاریخ خالی چاپ می‌شود. */
    public const FALLBACK = '—';

    /** نام ماه‌های شمسی. */
    public const MONTHS = [
        'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
    ];

    /** شمار روزهای ماه‌های شمسی (اسفند در سال کبیسه ۳۰ روز می‌شود). */
    private const J_MONTH_DAYS = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

    /** شمار روزهای ماه‌های میلادی (فوریه در سال کبیسه ۲۹ روز می‌شود). */
    private const G_MONTH_DAYS = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    /** ارقام انگلیسی و جداکننده‌ها → معادل فارسی. */
    private const DIGIT_MAP = [
        '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
    ];

    /**
     * تاریخ کوتاه شمسی با ارقام فارسی: ۱۴۰۵/۰۵/۳۰
     * با $withTime = true ساعت هم می‌آید: ۱۴۰۵/۰۵/۳۰ ۱۴:۳۲
     */
    public static function format(?DateTimeInterface $d, bool $withTime = false): string
    {
        $moment = self::localize($d);

        if ($moment === null) {
            return self::FALLBACK;
        }

        [$jy, $jm, $jd] = self::fromGregorian(
            (int) $moment->format('Y'),
            (int) $moment->format('n'),
            (int) $moment->format('j'),
        );

        $text = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);

        if ($withTime) {
            $text .= ' '.$moment->format('H:i');
        }

        return self::digits($text);
    }

    /** تاریخ بلند شمسی: ۳۰ مرداد ۱۴۰۵ (با $withTime ساعت هم می‌آید). */
    public static function long(?DateTimeInterface $d, bool $withTime = false): string
    {
        $moment = self::localize($d);

        if ($moment === null) {
            return self::FALLBACK;
        }

        [$jy, $jm, $jd] = self::fromGregorian(
            (int) $moment->format('Y'),
            (int) $moment->format('n'),
            (int) $moment->format('j'),
        );

        $text = $jd.' '.self::monthName($jm).' '.$jy;

        if ($withTime) {
            $text .= ' ساعت '.$moment->format('H:i');
        }

        return self::digits($text);
    }

    /** تاریخ و ساعت مناسب نام فایل خروجی: 14050530-1432 (ارقام انگلیسی). */
    public static function stamp(?DateTimeInterface $d = null): string
    {
        $moment = self::localize($d ?? new DateTimeImmutable('now'));

        if ($moment === null) {
            return '';
        }

        [$jy, $jm, $jd] = self::fromGregorian(
            (int) $moment->format('Y'),
            (int) $moment->format('n'),
            (int) $moment->format('j'),
        );

        return sprintf('%04d%02d%02d-%s', $jy, $jm, $jd, $moment->format('Hi'));
    }

    /** نام ماه شمسی (۱ تا ۱۲). */
    public static function monthName(int $jm): string
    {
        return self::MONTHS[$jm - 1] ?? '';
    }

    /** ارقام انگلیسی متن را فارسی می‌کند. */
    public static function digits(string|int|float|null $value): string
    {
        return strtr((string) $value, self::DIGIT_MAP);
    }

    /** آیا سال شمسی کبیسه است؟ (اسفند ۳۰ روزه) */
    public static function isLeap(int $jy): bool
    {
        return self::dayNumberOfJalali($jy + 1, 1, 1) - self::dayNumberOfJalali($jy, 1, 1) === 366;
    }

    /** شمار روزهای یک ماه شمسی. */
    public static function monthLength(int $jy, int $jm): int
    {
        if ($jm < 1 || $jm > 12) {
            return 0;
        }

        return $jm === 12 && self::isLeap($jy) ? 30 : self::J_MONTH_DAYS[$jm - 1];
    }

    /**
     * میلادی → شمسی. خروجی [سال، ماه، روز].
     * الگوریتم: شمارش روزهای گذشته از مبدأ ثابت و بازسازی سال شمسی روی
     * چرخهٔ ۳۳ ساله (۸ سال کبیسه در هر ۳۳ سال).
     */
    public static function fromGregorian(int $gy, int $gm, int $gd): array
    {
        $dayNo = self::dayNumberOfGregorian($gy, $gm, $gd) - 79; // ۷۹ = فاصلهٔ مبدأ دو گاه‌شماری

        $cycles = intdiv($dayNo, 12053); // هر چرخهٔ ۳۳ ساله ۱۲۰۵۳ روز است
        $dayNo %= 12053;

        $jy = 979 + (33 * $cycles) + (4 * intdiv($dayNo, 1461)); // هر ۴ سال ۱۴۶۱ روز
        $dayNo %= 1461;

        if ($dayNo >= 366) {
            $jy += intdiv($dayNo - 1, 365);
            $dayNo = ($dayNo - 1) % 365;
        }

        $jm = 1;
        while ($jm < 12 && $dayNo >= self::J_MONTH_DAYS[$jm - 1]) {
            $dayNo -= self::J_MONTH_DAYS[$jm - 1];
            $jm++;
        }

        return [$jy, $jm, $dayNo + 1];
    }

    /** شمسی → میلادی. خروجی [سال، ماه، روز]. */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $dayNo = self::dayNumberOfJalali($jy, $jm, $jd) + 79;

        $gy = 1600 + (400 * intdiv($dayNo, 146097)); // هر ۴۰۰ سال میلادی ۱۴۶۰۹۷ روز
        $dayNo %= 146097;

        $leap = true;

        if ($dayNo >= 36525) { // گذر از قرن‌های غیرکبیسه
            $dayNo--;
            $gy += 100 * intdiv($dayNo, 36524);
            $dayNo %= 36524;

            if ($dayNo >= 365) {
                $dayNo++;
            } else {
                $leap = false;
            }
        }

        $gy += 4 * intdiv($dayNo, 1461);
        $dayNo %= 1461;

        if ($dayNo >= 366) {
            $leap = false;
            $dayNo--;
            $gy += intdiv($dayNo, 365);
            $dayNo %= 365;
        }

        $gm = 1;
        while (true) {
            $length = self::G_MONTH_DAYS[$gm - 1] + ($gm === 2 && $leap ? 1 : 0);

            if ($dayNo < $length) {
                break;
            }

            $dayNo -= $length;
            $gm++;
        }

        return [$gy, $gm, $dayNo + 1];
    }

    /** شمار روزهای گذشته از ۱ ژانویهٔ ۱۶۰۰ میلادی. */
    private static function dayNumberOfGregorian(int $gy, int $gm, int $gd): int
    {
        $y = $gy - 1600;

        $days = (365 * $y) + intdiv($y + 3, 4) - intdiv($y + 99, 100) + intdiv($y + 399, 400);

        for ($i = 0; $i < $gm - 1; $i++) {
            $days += self::G_MONTH_DAYS[$i];
        }

        if ($gm > 2 && (($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0)) {
            $days++;
        }

        return $days + $gd - 1;
    }

    /** شمار روزهای گذشته از ۱ فروردین ۹۷۹ شمسی. */
    private static function dayNumberOfJalali(int $jy, int $jm, int $jd): int
    {
        $y = $jy - 979;

        $days = (365 * $y) + (intdiv($y, 33) * 8) + intdiv(($y % 33) + 3, 4);

        for ($i = 0; $i < $jm - 1; $i++) {
            $days += self::J_MONTH_DAYS[$i];
        }

        return $days + $jd - 1;
    }

    /** لحظه را به منطقه‌زمانی نمایش می‌برد؛ ورودی خالی → null. */
    private static function localize(?DateTimeInterface $d): ?DateTimeImmutable
    {
        if ($d === null) {
            return null;
        }

        $moment = $d instanceof DateTimeImmutable
            ? $d
            : DateTimeImmutable::createFromInterface($d);

        try {
            return $moment->setTimezone(new DateTimeZone(self::timezone()));
        } catch (\Throwable) {
            return $moment;
        }
    }

    /** منطقه‌زمانی نمایش — همان چیزی که کامپوننت <x-jdate> هم استفاده می‌کند. */
    private static function timezone(): string
    {
        // بیرون از بستر لاراول (مثلاً در یک اسکریپت ساده) config در دسترس نیست؛
        // در آن حالت بی‌سروصدا روی تهران می‌افتیم.
        try {
            $tz = config('panel_menu.timezone') ?: config('app.timezone');

            if (is_string($tz) && $tz !== '') {
                return $tz;
            }
        } catch (\Throwable) {
            // نادیده
        }

        return 'Asia/Tehran';
    }
}
