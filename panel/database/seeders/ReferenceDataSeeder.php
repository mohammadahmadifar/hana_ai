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
                    ['full_name', 'نام و نام خانوادگی', 'text', true, false],
                    ['birth_date', 'تاریخ تولد', 'jalali_date', true, true],
                    ['license_issue_date', 'تاریخ صدور', 'jalali_date', true, false],
                    ['license_expire_date', 'تاریخ انقضا', 'jalali_date', false, false],
                    ['license_number', 'شماره گواهینامه', 'digits', true, false],
                ],
            ],
            'vehicle_card' => [
                'کارت مالکیت خودرو', 'dataset/templates/vehicle_card.png', true, [
                    ['full_name', 'نام و نام خانوادگی مالک', 'text', true, false],
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
                ]],
            ],
            'precheck.limits' => [
                ['محدودیت اعتبارسنجی اولیه فایل', [
                    'min_bytes' => 20 * 1024,
                    'max_bytes' => 12 * 1024 * 1024,
                    'min_width' => 600,
                    'min_height' => 380,
                    'min_blur_score' => 60,       // واریانس لاپلاسین؛ کمتر یعنی تار
                    'min_brightness' => 40,
                    'max_brightness' => 225,
                    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
                ]],
            ],
        ];

        foreach ($defaults as $key => [[$label, $value]]) {
            if (Setting::query()->where('key', $key)->exists()) {
                continue;
            }

            Setting::create([
                'key' => $key,
                'value' => $value,
                'group' => explode('.', $key)[0],
                'label_fa' => $label,
            ]);
        }
    }
}
