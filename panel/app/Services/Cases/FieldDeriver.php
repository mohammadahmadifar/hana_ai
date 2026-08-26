<?php

namespace App\Services\Cases;

use App\Models\CaseDocument;
use App\Models\ExtractedField;
use App\Support\PersianValue;

/**
 * فیلدهایی که روی مدرک چاپ نشده‌اند ولی از روی فیلدِ چاپ‌شده «قانوناً» معلوم‌اند.
 *
 * ### چرا لازم شد (تسک ۷۲۵)
 * قالب گواهینامهٔ رانندگی — هم قالب واقعی و هم قالب ژنراتور — تاریخ انقضا را
 * چاپ نمی‌کند؛ فقط «تاریخ صدور» روی کارت است. پس `license_expire_date` هرگز
 * خوانده نمی‌شد و همیشه خالی می‌ماند، و اعتبارسنجی تاریخ برای گواهینامه ردیف
 * `skipped` می‌داد: «تاریخ انقضا خوانده نشد؛ اعتبار زمانی این مدرک بررسی نشد».
 * یعنی گواهینامهٔ بیست‌سالهٔ منقضی و گواهینامهٔ پارسال دقیقاً یک نتیجه می‌گرفتند.
 *
 * اعتبار گواهینامهٔ رانندگی از تاریخ صدور ده سال است، پس این تاریخ داده‌ای
 * نیست که «نخوانده‌ایم»؛ داده‌ای است که **محاسبه می‌شود**.
 *
 * ### سه قاعده‌ای که شکستنشان ساکت است
 * ۱. مقدارِ محاسبه‌شده هرگز روی خوانده‌شدهٔ موتور یا اصلاح کارشناس نمی‌نشیند.
 *    اگر مدرکی واقعاً تاریخ انقضا داشت و OCR خواندش، همان معتبر است — استنتاج
 *    فقط جای **خالی** را پر می‌کند.
 * ۲. اطمینانِ مقدارِ محاسبه‌شده دقیقاً اطمینان همان تاریخ صدوری است که از آن
 *    درآمده. با اطمینان ۱۰۰ نوشتنش یعنی «رد قطعیِ منقضی» روی تاریخی که موتور
 *    شاید بد خوانده — DocumentValidator با اطمینانِ پایین «مشکوک» می‌دهد نه
 *    «رد»، و این تفکیک باید سالم بماند.
 * ۳. اجرای دوباره ردیف تکراری نمی‌سازد و ردیف بیات جا نمی‌گذارد: تاریخ صدور
 *    که عوض شد (اصلاح کارشناس) مقدار محاسبه‌شده هم همان‌جا به‌روز می‌شود، و
 *    تاریخ صدور که ناخوانا شد ردیف محاسبه‌شده پاک می‌شود.
 */
final class FieldDeriver
{
    /** منبعِ ردیف‌هایی که این کلاس می‌نویسد — نه OCR است نه دست‌نویس کارشناس. */
    public const SOURCE = 'derived';

    /**
     * اعتبار گواهینامهٔ رانندگی از تاریخ صدور، به سال شمسی.
     *
     * عددِ قانون است نه آستانهٔ قابل تنظیم، پس در `settings` نمی‌نشیند: تغییرش
     * یعنی قانون عوض شده، و آن روز این ثابت با یک کامیت عوض می‌شود تا معلوم
     * بماند از کِی چه عددی حاکم بوده.
     */
    public const LICENSE_VALIDITY_YEARS = 10;

    /**
     * قاعده‌های استنتاج: کلید نوع مدرک ← فیلد مقصد، فیلد مبدأ، افزودنی (سال شمسی).
     *
     * `years` عددِ قانون است نه آستانهٔ قابل تنظیم: اعتبار گواهینامهٔ رانندگی
     * ایران از تاریخ صدور ده سال است. اگر روزی نوع مدرک تازه‌ای همین الگو را
     * داشت، یک ردیف این‌جا اضافه می‌شود و بقیهٔ کد دست نمی‌خورد.
     *
     * @var array<string, array{target: string, from: string, years: int}>
     */
    private const RULES = [
        'driving_license' => [
            'target' => 'license_expire_date',
            'from' => 'license_issue_date',
            'years' => self::LICENSE_VALIDITY_YEARS,
        ],
    ];

    /** آیا برای این نوع مدرک اصلاً قاعدهٔ استنتاجی هست؟ */
    public static function handles(?CaseDocument $document): bool
    {
        $type = $document?->documentType;

        if ($type === null) {
            return false;
        }

        $rule = self::RULES[(string) $type->key] ?? null;

        if ($rule === null) {
            return false;
        }

        // فیلد مقصد و مبدأ باید در تعریف همین نوع مدرک باشند، وگرنه ردیفی
        // می‌نویسیم که نه در صفحهٔ پرونده دیده می‌شود نه در اعتبارسنجی به کار
        // می‌آید — و دادهٔ مرجع بدون هماهنگی عوض می‌شود.
        $definitions = $type->fields->keyBy('key');

        return $definitions->has($rule['target']) && $definitions->has($rule['from']);
    }

    /**
     * مقداری که `derive()` می‌نوشت — بدون نوشتن. ورودی گزارش و حالت آزمایشی.
     *
     * @return array{target: string, value: string, from: string, confidence: float}|null
     */
    public function preview(CaseDocument $document): ?array
    {
        if (! self::handles($document)) {
            return null;
        }

        $rule = self::RULES[(string) $document->documentType->key];
        $rows = $this->rowsOf($document, $rule);

        /** @var ExtractedField|null $target */
        $target = $rows->get($rule['target']);

        // قاعدهٔ ۱: خوانده‌شدهٔ موتور یا دست‌نویس کارشناس حرف آخر را می‌زند.
        if ($target !== null && $target->source !== self::SOURCE && trim((string) $target->normalized_value) !== '') {
            return null;
        }

        $computed = $this->computed($rows->get($rule['from']), (int) $rule['years']);

        return $computed === null ? null : ['target' => (string) $rule['target']] + $computed;
    }

    /**
     * فیلدهای محاسبه‌شدهٔ یک مدرک را می‌نویسد / به‌روز می‌کند / پاک می‌کند.
     *
     * @return int تعداد ردیفی که نوشته یا به‌روز شد
     */
    public function derive(CaseDocument $document): int
    {
        if (! self::handles($document)) {
            return 0;
        }

        $rule = self::RULES[(string) $document->documentType->key];
        $rows = $this->rowsOf($document, $rule);

        /** @var ExtractedField|null $target */
        $target = $rows->get($rule['target']);

        if ($target !== null && $target->source !== self::SOURCE && trim((string) $target->normalized_value) !== '') {
            return 0;
        }

        $computed = $this->computed($rows->get($rule['from']), (int) $rule['years']);

        if ($computed === null) {
            // مبدأ رفت یا ناخوانا شد؛ مقدارِ محاسبه‌شدهٔ قبلی دیگر پشتوانه ندارد.
            if ($target !== null && $target->source === self::SOURCE) {
                $target->delete();
            }

            return 0;
        }

        $row = $target ?? new ExtractedField([
            'case_id' => $document->case_id,
            'case_document_id' => $document->id,
            'field_key' => $rule['target'],
        ]);

        $row->fill([
            'case_id' => $document->case_id,
            'case_document_id' => $document->id,
            'field_key' => $rule['target'],
            // raw_value همان چیزی است که این مقدار از آن درآمده — ردِ محاسبه
            // باید در خودِ ردیف بماند، وگرنه فردا معلوم نیست از کجا آمده.
            'raw_value' => $computed['from'],
            'normalized_value' => $computed['value'],
            'confidence' => $computed['confidence'],
            'source' => self::SOURCE,
            'corrected_by' => null,
            'corrected_at' => null,
        ])->save();

        return 1;
    }

    /**
     * ردیف‌های مقصد و مبدأ همین مدرک.
     *
     * @param  array{target: string, from: string, years: int}  $rule
     * @return \Illuminate\Support\Collection<string, ExtractedField>
     */
    private function rowsOf(CaseDocument $document, array $rule): \Illuminate\Support\Collection
    {
        return ExtractedField::query()
            ->where('case_id', $document->case_id)
            ->where('case_document_id', $document->id)
            ->whereIn('field_key', [$rule['target'], $rule['from']])
            ->get()
            ->keyBy('field_key');
    }

    /**
     * مقدار محاسبه‌شده از روی ردیف مبدأ — یا null اگر مبدأ نیست/ناخوانا است.
     *
     * @return array{value: string, from: string, confidence: float}|null
     */
    private function computed(?ExtractedField $from, int $years): ?array
    {
        if ($from === null) {
            return null;
        }

        $canonical = PersianValue::forEngine('jalali_date', $from->normalized_value ?? $from->raw_value);

        if ($canonical === '' || PersianValue::validate('jalali_date', $canonical, 'تاریخ صدور') !== null) {
            return null;
        }

        $plain = PersianValue::toEnglishDigits($canonical);

        if (preg_match('/^([0-9]{4})\/([0-9]{1,2})\/([0-9]{1,2})$/', $plain, $m) !== 1) {
            return null;
        }

        $value = self::addJalaliYears((int) $m[1], (int) $m[2], (int) $m[3], $years);

        return [
            'value' => PersianValue::toPersianDigits($value),
            'from' => $canonical,
            // قاعدهٔ ۲: محاسبه چیزی به قطعیتِ مبدأ اضافه نمی‌کند.
            // اصلاح دستی کارشناس اطمینان ۱۰۰ دارد و همان ۱۰۰ منتقل می‌شود.
            'confidence' => max(0.0, min(100.0, (float) $from->confidence)),
        ];
    }

    /**
     * افزودن چند سال شمسی به یک تاریخ شمسی.
     *
     * تنها ظرافتش ۳۰ اسفند است: سال کبیسه‌ای که ده سال بعدش کبیسه نیست روزِ
     * ۳۰ را ندارد و تاریخِ حاصل از اعتبارسنجی رد می‌شد. روز به آخرین روز همان
     * ماه چفت می‌شود (۳۰ اسفند ۱۳۹۹ ← ۲۹ اسفند ۱۴۰۹).
     */
    public static function addJalaliYears(int $year, int $month, int $day, int $years): string
    {
        $year += $years;
        $day = min($day, PersianValue::jalaliMonthLength($year, $month));

        return sprintf('%04d/%02d/%02d', $year, $month, $day);
    }
}
