<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Models\DatasetTag;
use App\Models\ServiceType;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * داده مرجع سامانه: انواع مدرک و فیلدهایشان، انواع خدمت و مدارک لازم هرکدام،
 * تگ‌های اولیه دیتاست و آستانه‌های امتیازدهی.
 *
 * این seeder idempotent است — چندبار اجرا شود چیزی خراب نمی‌شود.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->documentTypes();
        $this->serviceTypes();
        $this->datasetTags();
        $this->scoringSettings();
    }

    private function documentTypes(): void
    {
        // key => [برچسب, قالب موتور, ساخت‌پذیر, فیلدها]
        // هر فیلد: [key, برچسب, نوع مقدار, اجباری, بررسی تطابق بین مدارک]
        $types = [
            'national_card' => [
                'کارت ملی', 'dataset/templates/national_card.png', true, [
                    ['national_id', 'کد ملی', 'national_id', true, true],
                    ['first_name', 'نام', 'text', true, true],
                    ['last_name', 'نام خانوادگی', 'text', true, true],
                    ['birth_date', 'تاریخ تولد', 'jalali_date', true, true],
                    ['father_name', 'نام پدر', 'text', true, false],
                    ['national_card_expire', 'تاریخ انقضای کارت', 'jalali_date', true, false],
                ],
            ],
            'driving_license' => [
                'گواهینامه رانندگی', 'dataset/templates/driving_license.png', true, [
                    ['national_id', 'کد ملی', 'national_id', true, true],
                    ['full_name', 'نام و نام خانوادگی', 'text', true, true],
                    ['birth_date', 'تاریخ تولد', 'jalali_date', true, true],
                    ['license_issue_date', 'تاریخ صدور', 'jalali_date', true, false],
                    ['license_expire_date', 'تاریخ انقضا', 'jalali_date', false, false],
                    ['license_number', 'شماره گواهینامه', 'digits', true, false],
                ],
            ],
            'vehicle_card' => [
                'کارت مالکیت خودرو', 'dataset/templates/vehicle_card.png', true, [
                    ['full_name', 'نام و نام خانوادگی مالک', 'text', true, true],
                    ['national_id', 'کد ملی مالک', 'national_id', true, true],
                    ['father_name', 'نام پدر', 'text', false, false],
                    ['vin', 'شماره شاسی', 'vin', true, false],
                    ['plate_number', 'شماره پلاک', 'plate', true, false],
                ],
            ],
            'previous_permit' => [
                'مجوز قبلی', null, false, [
                    ['permit_number', 'شماره مجوز', 'digits', true, false],
                    ['national_id', 'کد ملی دارنده', 'national_id', true, true],
                    ['permit_issue_date', 'تاریخ صدور مجوز', 'jalali_date', true, false],
                    ['permit_expire_date', 'تاریخ انقضای مجوز', 'jalali_date', true, false],
                    ['plate_number', 'شماره پلاک', 'plate', false, false],
                ],
            ],
        ];

        $sort = 0;

        foreach ($types as $key => [$label, $template, $generatable, $fields]) {
            $type = DocumentType::updateOrCreate(
                ['key' => $key],
                [
                    'label_fa' => $label,
                    'template_path' => $template,
                    'is_generatable' => $generatable,
                    'is_active' => true,
                    'sort' => $sort++,
                ],
            );

            $fieldSort = 0;

            foreach ($fields as [$fKey, $fLabel, $valueType, $required, $cross]) {
                $type->fields()->updateOrCreate(
                    ['key' => $fKey],
                    [
                        'label_fa' => $fLabel,
                        'value_type' => $valueType,
                        'is_required' => $required,
                        'is_cross_checked' => $cross,
                        'sort' => $fieldSort++,
                    ],
                );
            }
        }
    }

    private function serviceTypes(): void
    {
        // طبق فلوچارت: صدور سه مدرک می‌خواهد، تمدید همان سه‌تا به‌علاوه مجوز قبلی
        $services = [
            'issue' => [
                'صدور مجوز',
                'درخواست مجوز جدید حمل‌ونقل برای متقاضی و خودرویی که تا الان مجوز نداشته است.',
                ['national_card', 'driving_license', 'vehicle_card'],
            ],
            'renew' => [
                'تمدید مجوز',
                'تمدید مجوز حمل‌ونقلی که قبلاً صادر شده و تاریخ اعتبارش رو به پایان است.',
                ['national_card', 'driving_license', 'vehicle_card', 'previous_permit'],
            ],
        ];

        $sort = 0;

        foreach ($services as $key => [$label, $description, $docKeys]) {
            $service = ServiceType::updateOrCreate(
                ['key' => $key],
                [
                    'label_fa' => $label,
                    'description_fa' => $description,
                    'is_active' => true,
                    'sort' => $sort++,
                ],
            );

            $sync = [];
            $docSort = 0;

            foreach ($docKeys as $docKey) {
                $doc = DocumentType::where('key', $docKey)->first();

                if ($doc) {
                    $sync[$doc->id] = ['is_required' => true, 'sort' => $docSort++];
                }
            }

            $service->documentTypes()->sync($sync);
        }
    }

    private function datasetTags(): void
    {
        $tags = [
            ['کیفیت پایین', '#dc2626', 'تصویری که چشم انسان هم به‌سختی می‌خواند'],
            ['چرخیده', '#d97706', 'تصویر با زاویه محسوس'],
            ['سایه‌دار', '#7c3aed', 'سایه روی بخشی از متن افتاده'],
            ['نور نامناسب', '#0891b2', 'خیلی تاریک یا خیلی روشن'],
            ['نمونه واقعی', '#059669', 'تصویر واقعی، نه ساخته موتور'],
            ['نیازمند بازبینی', '#be123c', 'برچسبش مشکوک است و باید انسان ببیند'],
        ];

        foreach ($tags as [$name, $color, $description]) {
            DatasetTag::updateOrCreate(
                ['name' => $name],
                ['color' => $color, 'description_fa' => $description],
            );
        }
    }

    private function scoringSettings(): void
    {
        // وزن‌ها و آستانه‌ها — طبق قانون پروژه نباید هاردکد باشند
        $defaults = [
            'scoring.weights' => [
                ['وزن‌های امتیاز اطمینان', [
                    'ocr_quality' => 40,
                    'validation' => 40,
                    'completeness' => 20,
                ]],
            ],
            'scoring.thresholds' => [
                ['آستانه تصمیم پرونده', [
                    'approve_at' => 80,   // امتیاز بالا  ← تایید
                    'reject_below' => 45, // امتیاز پایین ← رد
                    // ناهمخوانی کد ملی یا نام بین دو مدرک، مستقل از امتیاز پرونده را رد می‌کند.
                    // دلیل: این تناقض با کیفیت خوبِ بقیهٔ مؤلفه‌ها جبران نمی‌شود و هرچه OCR
                    // مطمئن‌تر باشد، مغایرت واقعی‌تر است. با false فقط امتیاز پایین می‌آید.
                    'cross_fail_rejects' => true,
                    // فیلد اجباریِ خوانده‌نشده جلوی تایید خودکار را می‌گیرد
                    // (رد نمی‌کند) — CaseScorer::decide()
                    'unread_required_holds' => true,
                ]],
            ],

            'scoring.penalties' => [
                ['جریمهٔ ایرادهای اعتبارسنجی', [
                    'failed' => 25,   // هر ایراد جدی، از ۱۰۰ کم می‌شود
                    'warning' => 8,   // هر هشدار
                ]],
            ],
            'validation.limits' => [
                ['آستانه‌های اعتبارسنجی اسناد', [
                    // زیر این اطمینان، تضاد بین دو مدرک «رد قطعی» حساب نمی‌شود
                    // بلکه «مشکوک» می‌ماند — چون ممکن است خطای OCR باشد نه جعل.
                    'min_confidence' => 60,
                    // درصد شباهت نام: بالاتر از این یعنی یکی است،
                    // بین این دو یعنی مشکوک، پایین‌تر یعنی دو نام متفاوت.
                    'name_match_min' => 85,
                    'name_suspect_min' => 60,
                ]],
            ],

            // این دو مقدار اسکالرند، نه گروهِ کلید-مقدار: کد آن‌ها را با
            // (float) Setting::get(...) می‌خواند، پس بسته‌بندی در آرایه
            // بی‌سروصدا به ۱.۰ تبدیلشان می‌کند.
            'review.sla_hours' => [
                ['سقف انتظار صف بررسی انسانی (ساعت)', 72],
            ],

            'reports.low_confidence' => [
                ['مرز «خواندن ضعیف» در گزارش OCR (درصد)', 60],
            ],

            // قانون ۱۳۰ می‌گوید اصلاح کارشناس باید به دیتاست برگردد (ارزان‌ترین دادهٔ
            // آموزشی سامانه). ولی قانون ۱۲۹ می‌گوید مدرک هویتی واقعی وارد دیتاست
            // نشود. با دادهٔ مصنوعی تضادی نیست؛ روی مدرک واقعی هست. پس این مسیر
            // یک کلید خاموش‌شدنی دارد و پیش‌فرضش طبق قانون ۱۳۰ روشن است.
            'dataset.collect_case_corrections' => [
                ['بازگرداندن اصلاح کارشناس به دیتاست تگ‌گذاری', true],
            ],

            'precheck.limits' => [
                ['محدودیت اعتبارسنجی اولیه فایل', [
                    'min_bytes' => 20 * 1024,
                    'max_bytes' => 12 * 1024 * 1024,
                    'min_width' => 600,
                    'min_height' => 380,
                    // نسبت عرض تصویر به عرض قالب مرجع. زیر این عدد فقط یک
                    // راهنمای کیفیت است، نه رد کردن — DocumentPrecheck::checkResolution()
                    'min_reference_ratio' => 0.75,
                    'min_blur_score' => 60,       // واریانس لاپلاسین؛ کمتر یعنی تار
                    'min_brightness' => 40,
                    'max_brightness' => 225,
                    // کنتراست (انحراف معیار روشنایی). «بیش از حد روشن» فقط
                    // وقتی رد می‌شود که کنتراست هم زیر این عدد باشد — وگرنه
                    // کاغذ سفیدِ سالم هم رد می‌شد. DocumentPrecheck::isBurntOut()
                    'min_contrast' => 18,
                    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
                ]],
            ],
        ];

        foreach ($defaults as $key => [[$label, $value]]) {
            $existing = Setting::query()->where('key', $key)->first();

            if ($existing === null) {
                Setting::create([
                    'key' => $key,
                    'value' => $value,
                    'group' => explode('.', $key)[0],
                    'label_fa' => $label,
                ]);

                continue;
            }

            // ردیف موجود دست نمی‌خورد مگر برای کلیدهای تازه‌ای که هنوز ندارد.
            // بدون این، کلید جدیدی که به یک گروه تنظیمات اضافه می‌شود روی
            // نصب‌های قبلی هرگز نمی‌آید و کد ناچار می‌شود پیش‌فرض هاردکد نگه دارد
            // (یا بدتر: صفحهٔ تنظیمات ردیفی بی‌برچسب و بی‌گروه بسازد).
            // مقدار اسکالر ادغام نمی‌شود؛ مقدارِ ویرایش‌شدهٔ کاربر باید بماند.
            if (is_array($value) && is_array($existing->value)) {
                $missing = array_diff_key($value, $existing->value);

                if ($missing !== []) {
                    $existing->value = $existing->value + $missing;
                }
            }

            // برچسب و گروه هم اگر جا افتاده بودند تکمیل می‌شوند.
            $existing->label_fa ??= $label;
            $existing->group = $existing->group ?: explode('.', $key)[0];

            if ($existing->isDirty()) {
                $existing->save();
            }
        }
    }
}
