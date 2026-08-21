<?php

namespace App\Services\Cases\Extraction;

use App\Support\PersianValue;

/**
 * دو فیلد ویژهٔ کارت خودرو: شمارهٔ شاسی (VIN) و پلاک.
 *
 * ماژول `vehicle_card_ocr` موتور این دو را جدا برمی‌گرداند و پنل آن‌ها را در
 * `ocr_runs.extra` نگه می‌دارد. اول همان خوانده می‌شود؛ متن خام فقط پشتیبان است.
 *
 * VIN: استاندارد جهانی حروف I و O و Q را در شمارهٔ شاسی ممنوع کرده، دقیقاً
 * چون با ۱ و ۰ اشتباه می‌شوند. پس نگاشت I→۱ و O→۰ و Q→۰ ترمیم مجاز است،
 * نه حدس. بعد از آن اگر رشته بلندتر از ۱۷ نویسه بود (آشغال چسبیده به خط،
 * مثل «VIN : NI: NAS675287M6771656»)، پنجرهٔ ۱۷تایی‌ای برداشته می‌شود که
 * با سه حرف و بعد یک رقم شروع شود — همان شکل استاندارد WMI.
 */
final class VehicleReader
{
    private const VIN_LENGTH = 17;

    // ------------------------------------------------------------------
    // شمارهٔ شاسی
    // ------------------------------------------------------------------

    /**
     * @return array{value: string, valid: bool, shaped: bool}|null
     *
     * valid  = ۱۷ نویسهٔ مجاز است
     * shaped = با سه حرف و بعد یک رقم شروع می‌شود (شکل استاندارد WMI)
     *
     * تفکیک این دو لازم است: وقتی آشغالِ چسبیده به خط را نتوانستیم درست
     * ببریم، رشتهٔ باقی‌مانده هنوز «۱۷ نویسه» هست ولی شکل شاسی ندارد
     * («NJNASS13523619931»). چنین مقداری نباید اطمینان بالا بگیرد.
     */
    public static function vin(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $plain = mb_strtoupper(PersianValue::toEnglishDigits($raw));
        $plain = preg_replace('/[^A-Z0-9]+/', '', $plain) ?? '';
        $plain = strtr($plain, ['I' => '1', 'O' => '0', 'Q' => '0']);

        if ($plain === '') {
            return null;
        }

        if (strlen($plain) > self::VIN_LENGTH) {
            $plain = self::vinWindow($plain);
        }

        return [
            'value' => $plain,
            'valid' => PersianValue::validate('vin', $plain, 'شماره شاسی') === null,
            'shaped' => (bool) preg_match('/^[A-Z]{3}[0-9]/', $plain),
        ];
    }

    /** بهترین پنجرهٔ ۱۷تایی از یک رشتهٔ بلندتر. */
    private static function vinWindow(string $plain): string
    {
        $fallback = null;

        for ($start = 0; $start + self::VIN_LENGTH <= strlen($plain); $start++) {
            $window = substr($plain, $start, self::VIN_LENGTH);

            if (preg_match('/^[A-Z]{3}[0-9]/', $window)) {
                return $window;
            }

            $fallback ??= $window;
        }

        return $fallback ?? $plain;
    }

    /** سطری که شمارهٔ شاسی رویش چاپ شده — دنبال «VIN» یا «شاسی» می‌گردد. */
    public static function vinLine(OcrText $text): ?string
    {
        for ($i = 0; $i < $text->count(); $i++) {
            $line = (string) $text->line($i);

            if (! preg_match('/(VIN|شاسی)\s*[:：]?/ui', $line, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $tail = substr($line, $m[0][1] + strlen($m[0][0]));

            if (trim($tail) !== '') {
                return $tail;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // پلاک
    // ------------------------------------------------------------------

    /** @return array{value: string, valid: bool}|null */
    public static function plate(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $found = self::matchPlate(PersianValue::toPersianDigits($raw));

        if ($found === null) {
            return null;
        }

        return [
            'value' => $found,
            'valid' => PersianValue::validate('plate', $found, 'شماره پلاک') === null,
        ];
    }

    /** جست‌وجوی الگوی پلاک در همهٔ سطرها — سطر «PLATE» اول، بعد بدنهٔ کارت. */
    public static function plateFromText(OcrText $text): ?array
    {
        $body = [];

        for ($i = 0; $i < $text->count(); $i++) {
            $line = (string) $text->line($i);

            if (preg_match('/(PLATE|پلاک)\s*[:：]?/ui', $line, $m, PREG_OFFSET_CAPTURE)) {
                $hit = self::plate(substr($line, $m[0][1] + strlen($m[0][0])));

                if ($hit !== null) {
                    return $hit;
                }

                continue;
            }

            $body[] = $line;
        }

        foreach ($body as $line) {
            $hit = self::plate($line);

            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /** الگوی چاپ ژنراتور (دو رقم، سه رقم، حرف، دو رقم) و الگوی خواندنی. */
    private static function matchPlate(string $line): ?string
    {
        $letters = implode('|', array_map(
            static fn (string $letter): string => preg_quote($letter, '/'),
            PersianValue::PLATE_LETTERS,
        ));

        $printOrder = '/([۰-۹]{2})\s*([۰-۹]{3})\s*('.$letters.')\s*([۰-۹]{2})/u';
        $readOrder = '/([۰-۹]{2})\s*('.$letters.')\s*([۰-۹]{3})\s*(?:ایران\s*)?([۰-۹]{2})/u';

        // خروجی همیشه به ترتیب رسمیِ خواندن است؛ canonical نهایی را
        // PersianValue::forEngine('plate', …) می‌سازد.
        if (preg_match($printOrder, $line, $m)) {
            return $m[1].' '.$m[3].' '.$m[2].' ایران '.$m[4];
        }

        if (preg_match($readOrder, $line, $m)) {
            return $m[1].' '.$m[2].' '.$m[3].' ایران '.$m[4];
        }

        return null;
    }
}
