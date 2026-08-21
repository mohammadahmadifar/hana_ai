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
 *      **مگر** وقتی مرحلهٔ ۱ جواب داده باشد: آن‌جا مرز برچسب و مقدار قطعی
 *      است، پس فقط تطابق دقیق حق حذف دارد و نام کوچکی که تصادفاً یک ویرایش
 *      با «نام» فاصله دارد («سام») سالم می‌ماند.
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

        // وقتی جداکننده پیدا شد، مرز برچسب و مقدار **قطعی** است. از این‌جا به بعد
        // حدسِ فازی روی اولین توکن ممنوع می‌شود: «نام و نام خانوادگی: سام رضایی»
        // بعد از برش «سام رضایی» است و distance('سام','نام') = ۱، پس نام کوچک
        // به‌عنوان ته‌ماندهٔ برچسب دور ریخته می‌شد و «رضایی» می‌ماند — که شباهتش
        // با نام واقعی ۵۵٪ است، یعنی زیر آستانهٔ «مشکوک» و مساوی mismatch.
        // برچسب‌های واقعیِ باقی‌مانده («… : حقیقی حقوقی سام رضایی») هنوز با
        // تطابق دقیق پاک می‌شوند.
        $allowFuzzyLabel = true;

        if ($cut !== null) {
            $line = $cut;
            $labelSeen = true;
            $allowFuzzyLabel = false;
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

        [$kept, $dropped] = self::stripLeadingLabel($kept, LabelBook::labelWords($fieldKey), $allowFuzzyLabel);

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
     * برچسب ابتدای سطر است و آن‌جا می‌توان جسورتر بود. برای تطابق **فازی**
     * دست‌کم یک توکن همیشه باقی می‌ماند تا نامی که خودش شبیه برچسب است
     * («سام» ~ «نام») صفر نشود؛ ولی تطابق **دقیق** حق دارد آخرین توکن را هم
     * ببرد: «نام ۱۲۳۴۵» بعد از دور ریختن رقم فقط «نام» می‌ماند و برگرداندن
     * خودِ برچسب به‌عنوان نام (با اطمینان ۸۵) از خالی برگرداندن بدتر است.
     *
     * `$allowFuzzy` وقتی false است که برچسب پیش‌تر روی جداکننده قطعاً بریده
     * شده باشد. آن‌وقت هر چه مانده «مقدار» است، پس فقط تطابق **دقیق** با
     * واژهٔ برچسب («حقیقی»، «حقوقی» که بعد از پرانتز می‌مانند) اجازهٔ حذف
     * دارد؛ نزدیکیِ تصادفیِ یک نام کوچک به «نام» یا «پدر» دیگر کافی نیست.
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $labelWords
     * @return array{0: list<string>, 1: int}
     */
    private static function stripLeadingLabel(array $tokens, array $labelWords, bool $allowFuzzy = true): array
    {
        if ($labelWords === []) {
            return [$tokens, 0];
        }

        $dropped = 0;

        while ($tokens !== []) {
            $token = str_replace("\u{200C}", '', $tokens[0]);
            $isLabel = false;
            $isExact = false;

            foreach ($labelWords as $word) {
                // بودجهٔ دو ویرایش فقط برای اولین توکن و فقط وقتی طولش دقیقاً
                // به اندازهٔ واژهٔ برچسب است: «بحر» همان «پدر» است، ولی «حسام»
                // (چهار حرف) نباید قربانی نزدیکی‌اش به «نام» شود.
                $budget = match (true) {
                    ! $allowFuzzy => 0,
                    $dropped === 0 && mb_strlen($token) === mb_strlen($word) && mb_strlen($word) >= 3 => 2,
                    default => 1,
                };

                $distance = OcrText::distance($token, $word);

                if ($distance === 0) {
                    $isLabel = true;
                    $isExact = true;

                    break;
                }

                if ($distance <= $budget) {
                    $isLabel = true;
                }
            }

            if (! $isLabel) {
                break;
            }

            // حدسِ فازی حق ندارد سطر را خالی کند؛ تطابق دقیق دارد
            if (count($tokens) === 1 && ! $isExact) {
                break;
            }

            array_shift($tokens);
            $dropped++;
        }

        return [array_values($tokens), $dropped];
    }
}
