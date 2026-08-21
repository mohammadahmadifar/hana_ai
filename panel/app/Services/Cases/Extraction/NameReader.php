<?php

namespace App\Services\Cases\Extraction;

/**
 * بیرون کشیدن یک «نام» از سطری که می‌دانیم متعلق به آن فیلد است.
 *
 * سطرِ نام سه بخش دارد: [برچسب] [جداکننده] [مقدار] [آشغال دنبالهٔ OCR].
 * هر سه بخش اول ممکن است خراب یا غایب باشند، پس چهار مرحله پشت سر هم:
 *
 *   ۱) **برش روی جداکننده** — روی کارت خودرو برچسب با «:» یا «)» تمام می‌شود
 *      («مشخصات مالک:(حقیقی / حقوقی) حسام تحسینی»). آخرین جداکننده‌ای که بعدش
 *      دست‌کم یک واژهٔ فارسی هست مرز برچسب و مقدار است.
 *   ۲) **دور ریختن آشغال** — توکن‌هایی که رقم یا حرف لاتین دارند، یا کوتاه‌ترند
 *      از حداقل طول یک نام واقعی. («۷۵ شادروان» → «شادروان»)
 *   ۳) **دور ریختن ته‌ماندهٔ برچسب** — توکن‌های ابتدایی که به واژه‌های برچسب
 *      نزدیک‌اند. برای اولین توکن بودجهٔ خطا بازتر است (تا ۲ ویرایش) چون
 *      می‌دانیم جای برچسب همان‌جاست: «بحر» همان «پدر» است و «نم» همان «نام».
 *   ۴) **بریدن دنباله** — حداکثر چند توکن آخر برداشته می‌شود.
 *
 * نکتهٔ کلیدی مرحلهٔ ۴: اگر در مرحله‌های ۱ تا ۳ **هیچ نشانه‌ای از برچسب**
 * پیدا نشده باشد، یعنی توکنِ اولِ باقی‌مانده به احتمال زیاد برچسبِ خراب است
 * («موی طلوعی» = «نام خانوادگی» خراب + «طلوعی»)، پس فقط یک توکن آخر برداشته
 * می‌شود. اگر برچسب شناخته شده باشد، نام چندتکه‌ای («محمد پور») سالم می‌ماند.
 */
final class NameReader
{
    /** کوتاه‌تر از این، توکن نمی‌تواند بخشی از نام باشد؛ ته‌ماندهٔ OCR است. */
    private const MIN_TOKEN = 3;

    /** جداکننده‌های برچسب از مقدار، به‌ترتیبی که روی قالب‌ها دیده می‌شوند. */
    private const SEPARATORS = [':', '،', ')', '(', '»', '«', '|', '/', '\\', '.', ',', '"', '؛', '-'];

    /**
     * @return array{value: string, tokens: int, labelSeen: bool}|null
     */
    public static function fromLine(string $line, string $fieldKey, int $maxTokens = 2): ?array
    {
        $labelSeen = false;

        $cut = self::cutAfterSeparator($line);

        if ($cut !== null) {
            $line = $cut;
            $labelSeen = true;
        }

        $tokens = OcrText::tokenize($line);
        $kept = [];

        foreach ($tokens as $token) {
            if (OcrText::isPersianWord($token, self::MIN_TOKEN)) {
                $kept[] = $token;

                continue;
            }

            $labelSeen = true; // چیزی دور ریخته شد، پس این سطر واقعاً آشغال داشت
        }

        if ($kept === []) {
            return null;
        }

        [$kept, $dropped] = self::stripLeadingLabel($kept, LabelBook::labelWords($fieldKey));

        if ($dropped > 0) {
            $labelSeen = true;
        }

        if ($kept === []) {
            return null;
        }

        $take = $labelSeen ? $maxTokens : 1;
        $kept = array_slice($kept, -min($take, count($kept)));

        return [
            'value' => implode(' ', $kept),
            'tokens' => count($kept),
            'labelSeen' => $labelSeen,
        ];
    }

    /**
     * برش بعد از آخرین جداکننده‌ای که پس از آن واژهٔ فارسیِ به‌درد‌بخور هست.
     */
    private static function cutAfterSeparator(string $line): ?string
    {
        $characters = mb_str_split($line);
        $count = count($characters);

        for ($i = $count - 1; $i >= 0; $i--) {
            if (! in_array($characters[$i], self::SEPARATORS, true)) {
                continue;
            }

            $tail = implode('', array_slice($characters, $i + 1));

            foreach (OcrText::tokenize($tail) as $token) {
                if (OcrText::isPersianWord($token, self::MIN_TOKEN)) {
                    return $tail;
                }
            }
        }

        return null;
    }

    /**
     * توکن‌های ابتداییِ نزدیک به واژه‌های برچسب را دور می‌ریزد.
     *
     * بودجهٔ خطا برای **اولین** توکن ۲ ویرایش است و برای بقیه ۱، چون جای
     * برچسب ابتدای سطر است و آن‌جا می‌توان جسورتر بود. دست‌کم یک توکن همیشه
     * باقی می‌ماند تا نامی که خودش شبیه برچسب است («سام» ~ «نام») صفر نشود.
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $labelWords
     * @return array{0: list<string>, 1: int}
     */
    private static function stripLeadingLabel(array $tokens, array $labelWords): array
    {
        if ($labelWords === []) {
            return [$tokens, 0];
        }

        $dropped = 0;

        while (count($tokens) > 1) {
            $token = str_replace("\u{200C}", '', $tokens[0]);
            $isLabel = false;

            foreach ($labelWords as $word) {
                // بودجهٔ دو ویرایش فقط برای اولین توکن و فقط وقتی طولش دقیقاً
                // به اندازهٔ واژهٔ برچسب است: «بحر» همان «پدر» است، ولی «حسام»
                // (چهار حرف) نباید قربانی نزدیکی‌اش به «نام» شود.
                $budget = $dropped === 0 && mb_strlen($token) === mb_strlen($word) && mb_strlen($word) >= 3
                    ? 2
                    : 1;

                if (OcrText::distance($token, $word) <= $budget) {
                    $isLabel = true;

                    break;
                }
            }

            if (! $isLabel) {
                break;
            }

            array_shift($tokens);
            $dropped++;
        }

        return [array_values($tokens), $dropped];
    }
}
