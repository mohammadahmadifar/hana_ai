<?php

namespace Tests\Feature;

use App\Models\ExtractedField;
use App\Models\PermitCase;
use App\Models\ScoreComponent;
use App\Models\Setting;
use App\Models\ValidationResult;
use App\Services\Cases\CaseScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCases;
use Tests\TestCase;

/**
 * تسک ۶۳۴ — امتیازدهی هوشمند و تصمیم پرونده.
 *
 * ورودی این تست‌ها فقط ردیف‌های دیتابیس است (extracted_fields و
 * validation_results)، دقیقاً مثل خود CaseScorer؛ هیچ‌کدام از کلاس‌های
 * مرحله‌های قبلِ پایپ‌لاین صدا زده نمی‌شوند.
 */
class CaseScorerTest extends TestCase
{
    use BuildsCases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    /** پروندهٔ تمیز: مدارک کامل، OCR مطمئن، بدون هیچ ایراد اعتبارسنجی → تایید. */
    public function test_clean_case_is_approved(): void
    {
        $case = $this->makeCaseWithDocuments('issue');
        $this->fillRequiredFields($case, 95.0);
        // قرارداد ValidationResult: ردیف فقط برای «ایراد» نوشته می‌شود، پس
        // پروندهٔ سالم اصلاً ردیفی ندارد.

        $case = $this->runScorer($case);

        $this->assertSame('approved', $case->decision);
        $this->assertSame('approved', $case->status);
        $this->assertGreaterThanOrEqual(80.0, (float) $case->confidence_score);
        $this->assertNotNull($case->processed_at);
        $this->assertStringContainsString('تایید', $case->decision_reason);

        // سه ردیف مؤلفه با سهم‌هایی که جمعشان همان امتیاز پرونده است.
        $components = $case->scoreComponents()->get();
        $this->assertEqualsCanonicalizing(
            ['ocr_quality', 'validation', 'completeness'],
            $components->pluck('component_key')->all(),
        );
        $this->assertEqualsWithDelta(
            (float) $case->confidence_score,
            (float) $components->sum('contribution'),
            0.01,
        );

        // هر ردیف باید «چرا این عدد» را به زبان آدمیزاد بگوید — ورودی صفحهٔ نتیجه.
        $components->each(fn (ScoreComponent $row) => $this->assertNotEmpty(trim((string) $row->note_fa)));
    }

    /** پروندهٔ ناقص: یک مدرک نیامده و OCR متوسط → نیاز به بررسی. */
    public function test_incomplete_case_needs_review(): void
    {
        $case = $this->makeCaseWithDocuments('issue');

        // کارت مالکیت خودرو اصلاً بارگذاری نشده است.
        $case->documents()
            ->where('document_type_id', $this->documentTypeId('vehicle_card'))
            ->delete();

        $case = $case->fresh(['documents']);

        $this->fillRequiredFields($case, 70.0);
        $this->addResult($case, 'document.missing.vehicle_card', 'document', 'warning', 'کارت مالکیت خودرو بارگذاری نشده است.');
        $this->addResult($case, 'document.expired.driving_license', 'document', 'warning', 'اعتبار گواهینامه رو به پایان است.');

        $case = $this->runScorer($case);

        $this->assertSame('needs_review', $case->decision);
        $this->assertSame('needs_review', $case->status);
        $this->assertGreaterThanOrEqual(45.0, (float) $case->confidence_score);
        $this->assertLessThan(80.0, (float) $case->confidence_score);

        $completeness = $case->scoreComponents()->where('component_key', 'completeness')->first();
        $this->assertLessThan(100.0, (float) $completeness->value);
        $this->assertStringContainsString('کارت مالکیت خودرو', $completeness->note_fa);
    }

    /** پروندهٔ متناقض: کد ملی بین دو مدرک یکی نیست → رد، مستقل از امتیاز. */
    public function test_contradictory_case_is_rejected_even_with_high_score(): void
    {
        $case = $this->makeCaseWithDocuments('issue');
        $this->fillRequiredFields($case, 95.0);
        $this->addResult(
            $case,
            'cross.national_id',
            'cross',
            'failed',
            'کد ملی کارت ملی با کد ملی گواهینامه یکی نیست.',
        );

        $case = $this->runScorer($case);

        $this->assertSame('rejected', $case->decision);
        $this->assertSame('rejected', $case->status);
        $this->assertStringContainsString('ناهمخوانی', $case->decision_reason);
        $this->assertStringContainsString('کد ملی', $case->decision_reason);

        // نکتهٔ اصلی: امتیاز هنوز بالاست؛ چیزی که پرونده را رد کرد وتوی ناهمخوانی بود.
        $this->assertGreaterThan(80.0, (float) $case->confidence_score);
    }

    /** همان پروندهٔ متناقض، ولی با خاموش‌کردن وتو در تنظیمات → دیگر خودکار رد نمی‌شود. */
    public function test_cross_failure_veto_is_configurable(): void
    {
        $case = $this->makeCaseWithDocuments('issue');
        $this->fillRequiredFields($case, 95.0);
        $this->addResult($case, 'cross.national_id', 'cross', 'failed', 'کد ملی مدارک یکسان نیست.');

        Setting::put('scoring.thresholds', [
            'approve_at' => 80,
            'reject_below' => 45,
            'cross_fail_rejects' => false,
        ]);

        $case = $this->runScorer($case);

        // با خاموش بودن وتو، ناهمخوانی فقط از راه مؤلفهٔ اعتبارسنجی امتیاز کم می‌کند.
        // این‌که پرونده در این حالت تایید می‌شود، دقیقاً دلیل «روشن بودن» پیش‌فرض است.
        $this->assertNotSame('rejected', $case->decision);
        $this->assertSame('approved', $case->decision);
        $this->assertLessThan(
            100.0,
            (float) $case->scoreComponents()->where('component_key', 'validation')->value('value'),
        );
    }

    /** مدرک منقضی: تاریخ با اطمینان بالا خوانده شده و گذشته → رد، مستقل از امتیاز. */
    public function test_expired_document_is_rejected_even_with_high_score(): void
    {
        $case = $this->makeCaseWithDocuments('issue');
        $this->fillRequiredFields($case, 95.0);
        $this->addResult(
            $case,
            'document.expired.driving_license',
            'document',
            'failed',
            'مدرک «گواهینامه رانندگی» منقضی شده است: «تاریخ انقضا» برابر ۱۳۹۵/۰۴/۱۲ است.',
        );

        $case = $this->runScorer($case);

        $this->assertSame('rejected', $case->decision);
        $this->assertSame('rejected', $case->status);
        $this->assertStringContainsString('مدرک منقضی', $case->decision_reason);
        $this->assertStringContainsString('گواهینامه رانندگی', $case->decision_reason);

        // همان نکتهٔ وتوی ناهمخوانی: امتیاز هنوز بالای آستانهٔ تایید است و
        // چیزی که پرونده را رد کرد، انقضا بود نه عدد.
        $this->assertGreaterThan(80.0, (float) $case->confidence_score);
    }

    /** همان پروندهٔ منقضی، با خاموش‌کردن وتو در تنظیمات → دیگر خودکار رد نمی‌شود. */
    public function test_expiry_veto_is_configurable(): void
    {
        $case = $this->makeCaseWithDocuments('issue');
        $this->fillRequiredFields($case, 95.0);
        $this->addResult(
            $case,
            'document.expired.driving_license',
            'document',
            'failed',
            'مدرک «گواهینامه رانندگی» منقضی شده است.',
        );

        Setting::put('scoring.thresholds', array_merge(
            CaseScorer::DEFAULT_THRESHOLDS,
            ['expired_rejects' => false],
        ));

        $case = $this->runScorer($case);

        // با خاموش بودن وتو، انقضا فقط از راه مؤلفهٔ اعتبارسنجی امتیاز کم می‌کند.
        $this->assertNotSame('rejected', $case->decision);
        $this->assertLessThan(
            100.0,
            (float) $case->scoreComponents()->where('component_key', 'validation')->value('value'),
        );
    }

    /**
     * تاریخِ کم‌اطمینان رد نمی‌کند، ولی خودکار هم تایید نمی‌شود.
     *
     * DocumentValidator برای «به‌نظر می‌رسد منقضی شده» عمداً warning می‌نویسد نه
     * failed؛ ترجمهٔ آن به «رد» یعنی جریمهٔ متقاضی بابت محدودیت OCR ما. ولی
     * تایید خودکارش هم یعنی صدور مجوز با تاریخی که هیچ‌کس نخوانده.
     */
    public function test_low_confidence_expiry_holds_case_for_human_review(): void
    {
        $case = $this->makeCaseWithDocuments('issue');
        $this->fillRequiredFields($case, 95.0);
        $this->addResult(
            $case,
            'document.expired.driving_license',
            'document',
            'warning',
            'به‌نظر می‌رسد «گواهینامه رانندگی» منقضی شده باشد، ولی تاریخ با اطمینان پایین خوانده شده.',
        );

        $case = $this->runScorer($case);

        $this->assertSame('needs_review', $case->decision);
        $this->assertSame('needs_review', $case->status);
        $this->assertStringContainsString('اعتبار زمانی مدرک قطعی نیست', $case->decision_reason);
    }

    /** آستانه‌ها هاردکد نیستند: با تغییر تنظیمات، تصمیمِ همان پرونده عوض می‌شود. */
    public function test_decision_follows_thresholds_from_settings(): void
    {
        $case = $this->makeCaseWithDocuments('issue');
        $this->fillRequiredFields($case, 95.0);

        $case = $this->runScorer($case);
        $this->assertSame('approved', $case->decision);

        $score = (float) $case->confidence_score;

        // آستانهٔ تایید بالاتر از امتیاز این پرونده → همان پرونده «نیاز به بررسی».
        Setting::put('scoring.thresholds', [
            'approve_at' => $score + 1,
            'reject_below' => 45,
            'cross_fail_rejects' => true,
        ]);

        $case = $this->runScorer($case);
        $this->assertSame('needs_review', $case->decision);
        $this->assertSame('needs_review', $case->status);

        // آستانهٔ رد را هم بالاتر از امتیاز ببریم → همان پرونده «رد».
        Setting::put('scoring.thresholds', [
            'approve_at' => $score + 2,
            'reject_below' => $score + 1,
            'cross_fail_rejects' => true,
        ]);

        $case = $this->runScorer($case);
        $this->assertSame('rejected', $case->decision);
        $this->assertSame('rejected', $case->status);
    }

    /** وزن‌ها هم از تنظیمات می‌آیند و در ردیف مؤلفه ذخیره می‌شوند. */
    public function test_weights_come_from_settings(): void
    {
        Setting::put('scoring.weights', [
            'ocr_quality' => 10,
            'validation' => 10,
            'completeness' => 80,
        ]);

        $case = $this->makeCaseWithDocuments('issue');
        $this->fillRequiredFields($case, 50.0);

        $case = $this->runScorer($case);

        $rows = $case->scoreComponents()->get()->keyBy('component_key');

        $this->assertEqualsWithDelta(10.0, (float) $rows['ocr_quality']->weight, 0.01);
        $this->assertEqualsWithDelta(80.0, (float) $rows['completeness']->weight, 0.01);
        $this->assertEqualsWithDelta(5.0, (float) $rows['ocr_quality']->contribution, 0.01);   // ۱۰ × ۵۰٪
        $this->assertEqualsWithDelta(80.0, (float) $rows['completeness']->contribution, 0.01); // ۸۰ × ۱۰۰٪
    }

    /** جدول‌های خالی نباید کرش کنند؛ مؤلفه با صفر و یادداشت روشن ثبت می‌شود. */
    public function test_empty_pipeline_data_does_not_crash(): void
    {
        $case = $this->runScorer($this->makeCaseWithDocuments('issue'));

        $rows = $case->scoreComponents()->get()->keyBy('component_key');

        $this->assertCount(3, $rows);
        $this->assertSame(0.0, (float) $rows['ocr_quality']->value);
        $this->assertSame(0.0, (float) $rows['validation']->value);
        $this->assertStringContainsString('استخراج نشده', $rows['ocr_quality']->note_fa);
        $this->assertStringContainsString('اجرا نشده', $rows['validation']->note_fa);

        // مدارک هستند ولی هیچ فیلدی خوانده نشده → کامل بودن نیمه.
        $this->assertGreaterThan(0.0, (float) $rows['completeness']->value);
        $this->assertSame('rejected', $case->decision);
    }

    /** نبودن ردیف تنظیمات (seeder اجرا نشده) → پیش‌فرض امن، بدون کرش. */
    public function test_missing_settings_fall_back_to_safe_defaults(): void
    {
        $case = $this->makeCaseWithDocuments('issue');
        $this->fillRequiredFields($case, 90.0);

        Setting::query()->delete();

        $case = $this->runScorer($case);

        $rows = $case->scoreComponents()->get()->keyBy('component_key');

        $this->assertEqualsWithDelta(40.0, (float) $rows['ocr_quality']->weight, 0.01);
        $this->assertEqualsWithDelta(40.0, (float) $rows['validation']->weight, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $rows['completeness']->weight, 0.01);
        $this->assertSame('approved', $case->decision);
    }

    /** اجرای دوباره ردیف تکراری نمی‌سازد. */
    public function test_scoring_twice_is_idempotent(): void
    {
        $case = $this->makeCaseWithDocuments('issue');
        $this->fillRequiredFields($case, 88.0);

        $first = $this->runScorer($case);
        $second = $this->runScorer($case);

        $this->assertSame(3, ScoreComponent::query()->where('case_id', $case->id)->count());
        $this->assertSame(
            (float) $first->confidence_score,
            (float) $second->confidence_score,
        );
    }

    /** تصمیم دستی کارشناس (تسک ۶۳۵) با اجرای دوبارهٔ امتیازدهی بازنویسی نمی‌شود. */
    public function test_manual_decision_is_not_overwritten(): void
    {
        $case = $this->makeCaseWithDocuments('issue');
        $case->forceFill([
            'decision' => 'approved',
            'decision_is_manual' => true,
            'status' => 'approved',
            'decision_reason' => 'کارشناس با دیدن اصل مدارک تایید کرد.',
        ])->save();

        $case = $this->runScorer($case);

        $this->assertSame('approved', $case->decision);
        $this->assertSame('approved', $case->status);
        $this->assertNotNull($case->confidence_score);   // امتیاز به‌روز می‌شود
        $this->assertSame(3, ScoreComponent::query()->where('case_id', $case->id)->count());
    }

    /**
     * «اثرگذارترین مؤلفه» باید بر پایهٔ سهمِ ازدست‌رفته انتخاب شود، نه مقدار خام.
     *
     * وزن‌ها برابر نیستند (۴۰/۴۰/۲۰). این پرونده کیفیت OCR ۵۵ دارد (وزن ۴۰ →
     * ۱۸ امتیاز از ۱۰۰ کم می‌کند) و کامل بودن مدارک ۳۳ (وزن ۲۰ → کمتر از ۱۴
     * امتیاز). مرتب‌سازی روی مقدار خام، «کامل بودن مدارک» را اثرگذارترین
     * می‌نامید و کارشناس را می‌فرستاد سراغ گرفتن مدرک اضافه، در حالی که مشکل
     * اصلی کیفیت تصویرهاست.
     */
    public function test_driver_component_is_the_one_that_lost_the_most_points(): void
    {
        $case = $this->makeCaseWithDocuments('issue');

        // دو مدرک از سه مدرک نیامده‌اند: کامل بودن پایین می‌آید ولی وزنش نصف است
        $case->documents()
            ->whereIn('document_type_id', [
                $this->documentTypeId('driving_license'),
                $this->documentTypeId('vehicle_card'),
            ])
            ->delete();

        $case = $case->fresh(['documents']);

        $this->fillRequiredFields($case, 55.0);

        $case = $this->runScorer($case);

        $rows = $case->scoreComponents()->get()->keyBy('component_key');

        // پیش‌شرط سناریو: مقدار خامِ «کامل بودن» کمتر است ولی امتیاز کمتری هم می‌گیرد
        $this->assertLessThan((float) $rows['ocr_quality']->value, (float) $rows['completeness']->value);
        $this->assertGreaterThan(
            (float) $rows['completeness']->weight - (float) $rows['completeness']->contribution,
            (float) $rows['ocr_quality']->weight - (float) $rows['ocr_quality']->contribution,
        );

        $reason = (string) $case->decision_reason;

        $this->assertStringContainsString(
            'اثرگذارترین مؤلفه: «'.CaseScorer::COMPONENT_LABELS['ocr_quality'].'»',
            $reason,
        );
        $this->assertStringNotContainsString(CaseScorer::COMPONENT_LABELS['completeness'], $reason);

        // و بگوید چند امتیاز کم کرده، وگرنه کارشناس نمی‌فهمد چرا این یکی مهم‌تر است
        $this->assertStringContainsString('امتیاز از ۱۰۰ کم کرده است', $reason);
    }

    /**
     * فیلدی که کارشناس دستی اصلاح کرده نباید «ضعیف‌ترین خواندن» معرفی شود.
     *
     * همان یادداشت، فیلد دستی را «قطعی» و ۱۰۰ حساب می‌کند؛ اگر انتخابِ
     * ضعیف‌ترین روی confidence خام باشد، یک جمله دو معیار متضاد را کنار هم
     * می‌گذارد و کارشناس سراغ فیلدی می‌رود که همین حالا هم درست است.
     */
    public function test_weakest_field_in_the_ocr_note_ignores_manually_corrected_fields(): void
    {
        $case = $this->makeCaseWithDocuments('issue');
        $vehicleId = (int) $case->documents
            ->firstWhere('document_type_id', $this->documentTypeId('vehicle_card'))->id;

        // «شماره پلاک» را کارشناس دستی نوشته؛ عدد خامش پایین مانده ولی قطعی است
        $this->addField($case, $vehicleId, 'plate_number', 31.3, 'manual');
        $this->addField($case, $vehicleId, 'vin', 40.0);
        $this->addField($case, $vehicleId, 'national_id', 90.0);

        $case = $this->runScorer($case);

        $note = (string) $case->scoreComponents()->where('component_key', 'ocr_quality')->value('note_fa');

        $this->assertStringContainsString('ضعیف‌ترین: «شماره شاسی» با ۴۰٫۰', $note);
        $this->assertStringNotContainsString('شماره پلاک', $note);
        $this->assertStringContainsString('۱ فیلد زیر ۵۰ خوانده شده', $note);
        $this->assertStringContainsString('۱ فیلد را کارشناس دستی اصلاح کرده', $note);

        // فیلد دستی ۱۰۰ حساب می‌شود، پس میانگین (۱۰۰+۴۰+۹۰)÷۳ است نه (۳۱٫۳+۴۰+۹۰)÷۳
        $this->assertEqualsWithDelta(
            76.67,
            (float) $case->scoreComponents()->where('component_key', 'ocr_quality')->value('value'),
            0.01,
        );
    }

    // ——— ابزار داخلی تست ———

    // ------------------------------------------------------------------
    // فیلد اجباریِ خوانده‌نشده جلوی تایید خودکار را می‌گیرد — تسک ۶۶۶
    // ------------------------------------------------------------------

    /**
     * امتیاز بالا هم مجوز نمی‌دهد وقتی یک فیلد اجباری اصلاً خوانده نشده.
     *
     * از تسک ۶۶۶ ایرادِ چنین فیلدی «مشکوک» است نه «رد قطعی» (منصفانه است:
     * مدرک ناقص نیست، ما نخواندیمش). ولی همان تخفیف، بدون این نگهبان،
     * پرونده را از بررسی انسانی به تایید خودکار می‌بُرد — یعنی صدور مجوز با
     * فیلدی که هیچ‌کس ندیده است.
     */
    public function test_an_unread_required_field_holds_the_case_back_from_auto_approval(): void
    {
        $case = $this->makeCaseWithDocuments('issue');

        $this->fillRequiredFields($case, 98.0);

        $documentId = $case->documents->first()->id;

        $this->addResult(
            $case,
            'document.missing_required.vehicle_card',
            'document',
            'warning',
            'یک فیلد اجباری خالی است.',
            $documentId,
        );

        ValidationResult::query()
            ->where('case_id', $case->id)
            ->where('rule_key', 'document.missing_required.vehicle_card')
            ->update(['details' => json_encode([
                // همان شکلی که DocumentValidator می‌نویسد؛ نوعِ مدرک از این کلید
                // خوانده می‌شود نه از تجزیهٔ rule_key.
                'document' => 'vehicle_card',
                'label' => 'کارت مالکیت خودرو',
                'missing' => [['key' => 'plate_number', 'label' => 'شماره پلاک']],
            ], JSON_UNESCAPED_UNICODE)]);

        $scored = $this->runScorer($case);

        $this->assertGreaterThanOrEqual(80.0, (float) $scored->confidence_score);
        $this->assertSame('needs_review', $scored->decision);
        $this->assertStringContainsString('شماره پلاک', (string) $scored->decision_reason);
        $this->assertStringContainsString('تایید خودکار', (string) $scored->decision_reason);
    }

    /** مدیر می‌تواند این نگهبان را خاموش کند. */
    public function test_the_hold_can_be_switched_off_in_settings(): void
    {
        Setting::put('scoring.thresholds', array_merge(CaseScorer::DEFAULT_THRESHOLDS, ['unread_required_holds' => false]));

        $case = $this->makeCaseWithDocuments('issue');

        $this->fillRequiredFields($case, 98.0);

        $this->addResult(
            $case,
            'document.missing_required.vehicle_card',
            'document',
            'warning',
            'یک فیلد اجباری خالی است.',
            $case->documents->first()->id,
        );

        ValidationResult::query()
            ->where('case_id', $case->id)
            ->where('rule_key', 'document.missing_required.vehicle_card')
            ->update(['details' => json_encode([
                // همان شکلی که DocumentValidator می‌نویسد؛ نوعِ مدرک از این کلید
                // خوانده می‌شود نه از تجزیهٔ rule_key.
                'document' => 'vehicle_card',
                'label' => 'کارت مالکیت خودرو',
                'missing' => [['key' => 'plate_number', 'label' => 'شماره پلاک']],
            ], JSON_UNESCAPED_UNICODE)]);

        $this->assertSame('approved', $this->runScorer($case)->decision);
    }

    /**
     * مدرک اجباری‌ای که اصلاً نیامده هم جلوی تایید خودکار را می‌گیرد.
     *
     * این حالت ردیفش `skipped` است و CaseScorer فقط failed و warning را جریمه
     * می‌کند، پس مؤلفهٔ اعتبارسنجی دست‌نخورده ۱۰۰ می‌ماند. اندازه‌گیری‌شده:
     * پروندهٔ سالمِ سه‌مدرکی که کارت خودرویش نیامده ۹۱.۷ می‌گرفت و خودکار
     * تایید می‌شد — مجوز حمل‌ونقل بدون کارت مالکیت خودرو.
     */
    public function test_a_required_document_that_never_arrived_also_holds_the_case(): void
    {
        $case = $this->makeCaseWithDocuments('issue');

        $this->fillRequiredFields($case, 98.0);

        $this->addResult(
            $case,
            'document.missing_required.vehicle_card',
            'document',
            'skipped',
            'مدرک «کارت مالکیت خودرو» هنوز بارگذاری نشده است.',
        );

        ValidationResult::query()
            ->where('case_id', $case->id)
            ->where('rule_key', 'document.missing_required.vehicle_card')
            ->update(['details' => json_encode([
                'document' => 'vehicle_card',
                'label' => 'کارت مالکیت خودرو',
                'uploaded' => false,
            ], JSON_UNESCAPED_UNICODE)]);

        $scored = $this->runScorer($case);

        $this->assertGreaterThanOrEqual(80.0, (float) $scored->confidence_score);
        $this->assertSame('needs_review', $scored->decision);
        $this->assertStringContainsString('کارت مالکیت خودرو', (string) $scored->decision_reason);
    }

    /**
     * مدرکی که آمده ولی هیچ فیلدی از آن درنیامد، پیام خودش را می‌گیرد.
     *
     * ردیفش هم `skipped` است ولی `uploaded` ندارد؛ گفتنِ «بارگذاری نشده» به
     * کارشناسی که فایل جلوی چشمش است، او را دنبال نخود سیاه می‌فرستد.
     */
    public function test_an_uploaded_document_with_no_extraction_says_so(): void
    {
        $case = $this->makeCaseWithDocuments('issue');

        $this->fillRequiredFields($case, 98.0);

        $this->addResult(
            $case,
            'document.missing_required.vehicle_card',
            'document',
            'skipped',
            'هنوز هیچ فیلدی استخراج نشده است.',
        );

        ValidationResult::query()
            ->where('case_id', $case->id)
            ->where('rule_key', 'document.missing_required.vehicle_card')
            ->update(['details' => json_encode([
                'document' => 'vehicle_card',
                'label' => 'کارت مالکیت خودرو',
            ], JSON_UNESCAPED_UNICODE)]);

        $reason = (string) $this->runScorer($case)->decision_reason;

        $this->assertStringContainsString('هیچ فیلدی از آن خوانده نشد', $reason);
        $this->assertStringNotContainsString('بارگذاری نشده', $reason);
    }

    /**
     * کلیدِ بریده‌شدهٔ rule_key نباید نگهبان را بی‌صدا باز کند.
     *
     * DocumentValidator کلید بلندتر از ۶۰ نویسه را با پسوند sha1 می‌بُرد؛ اگر
     * نوعِ مدرک از تجزیهٔ همان کلید خوانده می‌شد، دقیقاً در حالتی که باید
     * بگیرد جور درنمی‌آمد و پرونده خودکار تایید می‌شد.
     */
    public function test_a_clipped_rule_key_still_holds_the_case(): void
    {
        $case = $this->makeCaseWithDocuments('issue');

        $this->fillRequiredFields($case, 98.0);

        $this->addResult(
            $case,
            'document.missing_required.vehicle_ca.1a2b3c4d',
            'document',
            'skipped',
            'مدرک «کارت مالکیت خودرو» هنوز بارگذاری نشده است.',
        );

        ValidationResult::query()
            ->where('case_id', $case->id)
            ->where('rule_key', 'document.missing_required.vehicle_ca.1a2b3c4d')
            ->update(['details' => json_encode([
                'document' => 'vehicle_card',
                'label' => 'کارت مالکیت خودرو',
                'uploaded' => false,
            ], JSON_UNESCAPED_UNICODE)]);

        $this->assertSame('needs_review', $this->runScorer($case)->decision);
    }

    /** مدرکی که این خدمت اصلاً لازمش ندارد، نگهبان را بیدار نمی‌کند. */
    public function test_a_document_this_service_does_not_need_never_holds_the_case(): void
    {
        // «صدور مجوز» سه مدرک می‌خواهد؛ «مجوز قبلی» فقط مال تمدید است
        $case = $this->makeCaseWithDocuments('issue');

        $this->fillRequiredFields($case, 98.0);

        $this->addResult(
            $case,
            'document.missing_required.previous_permit',
            'document',
            'skipped',
            'مدرک هنوز بارگذاری نشده است.',
        );

        ValidationResult::query()
            ->where('case_id', $case->id)
            ->where('rule_key', 'document.missing_required.previous_permit')
            ->update(['details' => json_encode([
                'document' => 'previous_permit',
                'label' => 'مجوز قبلی',
                'uploaded' => false,
            ], JSON_UNESCAPED_UNICODE)]);

        $this->assertSame('approved', $this->runScorer($case)->decision);
    }

    // ------------------------------------------------------------------

    private function runScorer(PermitCase $case): PermitCase
    {
        $fresh = $case->fresh();

        app(CaseScorer::class)->score($fresh);

        return $fresh->fresh();
    }

    /** برای هر مدرک موجود، همهٔ فیلدهای اجباری‌اش را با اطمینان داده‌شده می‌سازد. */
    private function fillRequiredFields(PermitCase $case, float $confidence): void
    {
        foreach ($case->documents as $document) {
            foreach ($document->documentType->fields as $field) {
                if (! $field->is_required) {
                    continue;
                }

                ExtractedField::create([
                    'case_id' => $case->id,
                    'case_document_id' => $document->id,
                    'field_key' => $field->key,
                    'raw_value' => 'مقدار آزمایشی',
                    'normalized_value' => 'مقدار آزمایشی',
                    'confidence' => $confidence,
                    'source' => 'ocr',
                ]);
            }
        }
    }

    /** یک ردیف extracted_fields با اطمینان و منبع دلخواه. */
    private function addField(
        PermitCase $case,
        int $documentId,
        string $fieldKey,
        float $confidence,
        string $source = 'ocr',
    ): void {
        ExtractedField::create([
            'case_id' => $case->id,
            'case_document_id' => $documentId,
            'field_key' => $fieldKey,
            'raw_value' => 'مقدار آزمایشی',
            'normalized_value' => 'مقدار آزمایشی',
            'confidence' => $confidence,
            'source' => $source,
        ]);
    }

    private function addResult(
        PermitCase $case,
        string $ruleKey,
        string $scope,
        string $status,
        string $message,
        ?int $documentId = null,
    ): void {
        ValidationResult::create([
            'case_id' => $case->id,
            'case_document_id' => $documentId,
            'rule_key' => $ruleKey,
            'scope' => $scope,
            'status' => $status,
            'message_fa' => $message,
        ]);
    }
}
