<?php

namespace App\Services\Cases\Extraction;

/**
 * برچسب‌های چاپ‌شدهٔ روی مدارک.
 *
 * روی هر مدرک کنار هر مقدار یک برچسب فارسی چاپ شده است («شماره ملی»،
 * «تاریخ تولد»، …). OCR گاهی برچسب را سالم می‌خواند و گاهی خرابش می‌کند
 * («تاریخ تولد» → «ترجج توند»). پس برچسب یک **سرنخ** است نه یک تضمین:
 *
 *   لایهٔ ۱  برچسب دقیق روی سطر پیدا شود        → اطمینان بالا
 *   لایهٔ ۲  برچسب با فاصلهٔ ویرایشی کم پیدا شود → اطمینان متوسط
 *   لایهٔ ۳  برچسب پیدا نشود                     → FieldExtractor سراغ چیدمان
 *                                                  و شکل خودِ مقدار می‌رود
 *
 * `values()` واژه‌هایی است که اگر ابتدای مقدار دیده شوند در واقع ته‌ماندهٔ
 * برچسب‌اند نه بخشی از نام. این فهرست فقط واژه‌های **چاپ‌شده روی قالب** را
 * دارد؛ شکل‌های خراب‌شدهٔ OCR این‌جا فهرست نمی‌شوند، با فاصلهٔ ویرایشی
 * تشخیص داده می‌شوند (به Cleaner::stripLeadingLabel نگاه کن).
 */
final class LabelBook
{
    /**
     * برچسب‌های هر فیلد به تفکیک نوع مدرک.
     *
     * کلید بیرونی = document_types.key ، کلید درونی = document_type_fields.key
     *
     * @var array<string, array<string, list<string>>>
     */
    private const LABELS = [
        'national_card' => [
            'national_id' => ['شماره ملی', 'شماره شناسنامه', 'کد ملی'],
            'first_name' => ['نام'],
            'last_name' => ['نام خانوادگی'],
            'birth_date' => ['تاریخ تولد'],
            'father_name' => ['نام پدر'],
            'national_card_expire' => ['تاریخ اعتبار', 'پایان اعتبار', 'تاریخ انقضا'],
        ],
        'driving_license' => [
            'national_id' => ['شماره ملی', 'کد ملی'],
            'full_name' => ['نام و نام خانوادگی', 'نام خانوادگی', 'نام'],
            'birth_date' => ['تاریخ تولد'],
            'license_issue_date' => ['تاریخ صدور'],
            'license_expire_date' => ['تاریخ انقضا', 'تاریخ اعتبار'],
            'license_number' => ['شماره گواهینامه'],
        ],
        'vehicle_card' => [
            'full_name' => ['مشخصات مالک', 'مشخصات'],
            'national_id' => ['شماره ملی', 'کد شناسایی'],
            'father_name' => ['نام پدر', 'نماینده سازمان'],
            'vin' => ['شماره شاسی', 'VIN'],
            'plate_number' => ['شماره پلاک', 'PLATE'],
        ],
        'previous_permit' => [
            'permit_number' => ['شماره مجوز'],
            'national_id' => ['شماره ملی', 'کد ملی'],
            'permit_issue_date' => ['تاریخ صدور'],
            'permit_expire_date' => ['تاریخ انقضا', 'تاریخ اعتبار', 'پایان اعتبار'],
            'plate_number' => ['شماره پلاک'],
        ],
    ];

    /**
     * واژه‌های برچسب که اگر جلوی مقدار بمانند باید دور ریخته شوند.
     *
     * @var array<string, list<string>>
     */
    private const LABEL_WORDS = [
        'first_name' => ['نام'],
        'last_name' => ['نام', 'خانوادگی'],
        'full_name' => ['نام', 'خانوادگی', 'مشخصات', 'مالک', 'حقیقی', 'حقوقی'],
        'father_name' => ['نام', 'پدر', 'نماینده', 'سازمان'],
    ];

    /** @return list<string> */
    public static function labels(string $documentTypeKey, string $fieldKey): array
    {
        return self::LABELS[$documentTypeKey][$fieldKey] ?? [];
    }

    /** @return list<string> */
    public static function labelWords(string $fieldKey): array
    {
        return self::LABEL_WORDS[$fieldKey] ?? [];
    }
}
