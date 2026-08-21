<?php

namespace Tests\Feature;

use App\Models\CaseDocument;
use App\Models\ExtractedField;
use App\Models\PermitCase;
use App\Models\ValidationResult;
use Database\Seeders\DemoCasesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCases;
use Tests\TestCase;

/**
 * گزارش خطاها — تسک ۶۳۶.
 *
 * ارزشمندترین خروجی این صفحه «ضعیف‌ترین فیلدها» است، چون مستقیم می‌گوید بعد
 * کدام قسمت موتور را باید بهتر کرد. پس بیشتر تست‌ها روی درستی همان تجمیع‌اند:
 * ترتیب، تعداد نمونه، و اینکه اصلاح دستی کارشناس میانگین موتور را باد نکند.
 */
class ReportsTest extends TestCase
{
    use BuildsCases;
    use RefreshDatabase;

    public function test_empty_database_shows_clean_empty_state(): void
    {
        $response = $this->actingAs($this->adminUser())->get(route('reports.index'));

        $response->assertOk();
        $response->assertSee('هیچ ایرادی ثبت نشده است');
        $response->assertSee('هنوز فیلدی از مدارک استخراج نشده است');

        $this->assertTrue($response->viewData('failedRules')->isEmpty());
        $this->assertTrue($response->viewData('weakFields')->isEmpty());
        $this->assertNull($response->viewData('ocrAverage'));
    }

    public function test_data_expert_may_not_open_the_reports(): void
    {
        $this->actingAs($this->dataUser())->get(route('reports.index'))->assertForbidden();
        $this->actingAs($this->dataUser())->get(route('reports.rule', ['key' => 'cross.national_id']))->assertForbidden();
        $this->actingAs($this->dataUser())->get(route('reports.field', ['key' => 'plate_number']))->assertForbidden();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get(route('reports.index'))->assertRedirect(route('login'));
    }

    public function test_top_rejection_reasons_are_grouped_and_ordered(): void
    {
        $this->seedReferenceData();
        $caseA = $this->makeCaseWithDocuments();
        $caseB = $this->makeCaseWithDocuments();

        $this->failure($caseA, 'cross.national_id', 'cross');
        $this->failure($caseB, 'cross.national_id', 'cross');
        $this->failure($caseB, 'document.expired.driving_license', 'document');

        // هشدار «رد» نیست و نباید در جدول دلایل رد بیاید.
        ValidationResult::create([
            'case_id' => $caseA->id,
            'rule_key' => 'cross.full_name',
            'scope' => 'cross',
            'status' => 'warning',
            'message_fa' => 'نام کمی متفاوت خوانده شد.',
        ]);

        // بررسی پاس‌شده هم همین‌طور (از HA-S3 حوزه‌های document و cross
        // ردیف passed هم می‌نویسند).
        ValidationResult::create([
            'case_id' => $caseA->id,
            'rule_key' => 'document.expired.national_card',
            'scope' => 'document',
            'status' => 'passed',
            'message_fa' => 'تاریخ اعتبار کارت ملی معتبر است.',
        ]);

        $response = $this->actingAs($this->expertUser())->get(route('reports.index'));

        $response->assertOk();
        $this->assertSame(3, $response->viewData('failedTotal'));
        $this->assertSame(1, $response->viewData('warningTotal'));

        $rules = $response->viewData('failedRules');
        $this->assertSame(
            ['cross.national_id', 'document.expired.driving_license'],
            $rules->pluck('rule_key')->all(),
        );
        $this->assertSame(2, (int) $rules->first()->failures);
        $this->assertSame(2, (int) $rules->first()->cases_affected);
        $this->assertNotEmpty($rules->first()->sample_message);
    }

    public function test_weakest_ocr_fields_are_ordered_with_their_sample_count(): void
    {
        $this->seedReferenceData();
        $case = $this->makeCaseWithDocuments();
        $document = $case->documents->first();

        // پلاک: دو نمونهٔ خیلی ضعیف. کد ملی: سه نمونهٔ خوب.
        $this->reading($case, $document, 'plate_number', 10);
        $this->reading($case, $document, 'plate_number', 20);
        $this->reading($case, $document, 'national_id', 80);
        $this->reading($case, $document, 'national_id', 90);
        $this->reading($case, $document, 'national_id', 100);

        $response = $this->actingAs($this->expertUser())->get(route('reports.index'));

        $response->assertOk();

        $fields = $response->viewData('weakFields')->keyBy('field_key');

        $this->assertSame(['plate_number', 'national_id'], $response->viewData('weakFields')->pluck('field_key')->all());
        $this->assertEqualsWithDelta(15.0, (float) $fields['plate_number']->avg_confidence, 0.01);
        $this->assertSame(2, (int) $fields['plate_number']->ocr_samples);
        $this->assertEqualsWithDelta(10.0, (float) $fields['plate_number']->min_confidence, 0.01);
        $this->assertSame(2, (int) $fields['plate_number']->low_samples);

        $this->assertEqualsWithDelta(90.0, (float) $fields['national_id']->avg_confidence, 0.01);
        $this->assertSame(3, (int) $fields['national_id']->ocr_samples);
        $this->assertSame(0, (int) $fields['national_id']->low_samples);

        // میانگین کلی موتور = میانگین همان پنج خواندن.
        $this->assertEqualsWithDelta(60.0, $response->viewData('ocrAverage'), 0.01);

        // برچسب فارسی فیلد از جدول مرجع می‌آید، نه هاردکد در قالب.
        $this->assertSame('شماره پلاک', $response->viewData('fieldLabels')['plate_number']);
    }

    public function test_manual_corrections_do_not_inflate_the_engine_average(): void
    {
        $this->seedReferenceData();
        $case = $this->makeCaseWithDocuments();
        $document = $case->documents->first();

        $this->reading($case, $document, 'plate_number', 20);
        $this->reading($case, $document, 'plate_number', 100, source: 'manual');

        $response = $this->actingAs($this->expertUser())->get(route('reports.index'));

        $field = $response->viewData('weakFields')->firstWhere('field_key', 'plate_number');

        // میانگین فقط روی خواندن موتور: ۲۰، نه ۶۰.
        $this->assertEqualsWithDelta(20.0, (float) $field->avg_confidence, 0.01);
        $this->assertSame(1, (int) $field->ocr_samples);
        $this->assertSame(2, (int) $field->samples);
        $this->assertSame(1, (int) $field->corrected_samples);
    }

    public function test_weak_fields_by_document_name_the_guilty_template(): void
    {
        $this->seedReferenceData();
        $case = $this->makeCaseWithDocuments();

        $vehicle = $case->documents->firstWhere('document_type_id', $this->documentTypeId('vehicle_card'));
        $national = $case->documents->firstWhere('document_type_id', $this->documentTypeId('national_card'));

        $this->reading($case, $vehicle, 'national_id', 30);
        $this->reading($case, $national, 'national_id', 95);

        $rows = $this->actingAs($this->expertUser())
            ->get(route('reports.index'))
            ->viewData('weakFieldsByDocument');

        $this->assertSame('کارت مالکیت خودرو', $rows->first()->document_label);
        $this->assertEqualsWithDelta(30.0, (float) $rows->first()->avg_confidence, 0.01);
    }

    public function test_rule_drilldown_lists_only_that_rule(): void
    {
        $this->seedReferenceData();
        $caseA = $this->makeCaseWithDocuments();
        $caseB = $this->makeCaseWithDocuments();

        $this->failure($caseA, 'cross.national_id', 'cross');
        $this->failure($caseB, 'document.expired.driving_license', 'document');

        $response = $this->actingAs($this->expertUser())
            ->get(route('reports.rule', ['key' => 'cross.national_id']));

        $response->assertOk();
        $response->assertSee($caseA->code);
        $response->assertDontSee($caseB->code);
        $this->assertSame(1, $response->viewData('total'));
    }

    public function test_field_drilldown_puts_the_worst_reading_first(): void
    {
        $this->seedReferenceData();
        $case = $this->makeCaseWithDocuments();
        $document = $case->documents->first();

        $this->reading($case, $document, 'plate_number', 70, raw: 'خواندن بهتر');
        $this->reading($case, $document, 'plate_number', 12, raw: 'خواندن بدتر');

        $response = $this->actingAs($this->expertUser())
            ->get(route('reports.field', ['key' => 'plate_number']));

        $response->assertOk();
        $this->assertSame(
            ['خواندن بدتر', 'خواندن بهتر'],
            $response->viewData('rows')->pluck('raw_value')->all(),
        );
        $this->assertSame(2, (int) $response->viewData('summary')->samples);
        $this->assertSame('شماره پلاک', $response->viewData('fieldLabel'));
    }

    public function test_missing_or_array_key_does_not_break_the_drilldown(): void
    {
        $expert = $this->expertUser();

        $this->actingAs($expert)->get(route('reports.rule'))->assertOk();
        $this->actingAs($expert)->get(route('reports.field'))->assertOk();
        $this->actingAs($expert)->get('/reports/rule?key[]=a&key[]=b')->assertOk();
        $this->actingAs($expert)->get('/reports/field?key[]=a')->assertOk();
    }

    /** با دادهٔ نمایشی، هر دو جدول پر می‌شوند و صفحه سالم می‌ماند. */
    public function test_demo_data_fills_both_reports(): void
    {
        $this->seed(DemoCasesSeeder::class);

        $response = $this->actingAs($this->adminUser())->get(route('reports.index'));

        $response->assertOk();
        $this->assertGreaterThan(0, $response->viewData('failedTotal'));
        $this->assertNotEmpty($response->viewData('failedRules'));

        // دادهٔ نمایشی مثل دادهٔ واقعی ردیف passed هم دارد، ولی گزارش فقط
        // ایرادها را می‌شمارد.
        $passed = ValidationResult::where('status', 'passed')->count();
        $this->assertGreaterThan(0, $passed);
        $this->assertSame(
            ValidationResult::where('status', 'failed')->count(),
            $response->viewData('failedTotal'),
        );
        $this->assertEmpty(
            $response->viewData('failedRules')->filter(fn ($r) => str_starts_with($r->rule_key, 'zzz'))->all(),
        );

        // ضعف‌های شناخته‌شدهٔ موتور باید در صدر «ضعیف‌ترین فیلدها» بنشینند.
        $worst = $response->viewData('weakFields')->pluck('field_key')->take(2)->all();
        $this->assertSame(['plate_number', 'full_name'], $worst);
        $this->assertLessThan(50, (float) $response->viewData('weakFields')->first()->avg_confidence);
    }

    public function test_a_healthy_case_never_shows_up_in_the_rejection_reasons(): void
    {
        $this->seedReferenceData();
        $healthy = $this->makeCaseWithDocuments('renew');
        $broken = $this->makeCaseWithDocuments();

        // پروندهٔ سالم فقط ردیف passed دارد — دقیقاً همان چیزی که از HA-S3
        // به بعد در دیتابیس می‌نشیند و اگر فیلتر status نباشد، گزارش را
        // برعکس واقعیت نشان می‌دهد.
        foreach (['document.expired.national_card', 'document.missing_required.vehicle_card', 'cross.national_id'] as $rule) {
            ValidationResult::create([
                'case_id' => $healthy->id,
                'rule_key' => $rule,
                'scope' => str_starts_with($rule, 'cross.') ? 'cross' : 'document',
                'status' => 'passed',
                'message_fa' => 'بررسی بدون ایراد پاس شد.',
            ]);
        }

        $this->failure($broken, 'cross.national_id', 'cross');

        $response = $this->actingAs($this->expertUser())->get(route('reports.index'));

        $response->assertOk();
        $this->assertSame(1, $response->viewData('failedTotal'));

        $rules = $response->viewData('failedRules');
        $this->assertSame(['cross.national_id'], $rules->pluck('rule_key')->all());
        $this->assertSame(1, (int) $rules->first()->failures);
        $this->assertSame(1, (int) $rules->first()->cases_affected);

        $drill = $this->actingAs($this->expertUser())
            ->get(route('reports.rule', ['key' => 'cross.national_id']));

        $drill->assertSee($broken->code);
        $drill->assertDontSee($healthy->code);
        $this->assertSame(1, $drill->viewData('total'));
    }

    /** یک ایراد اعتبارسنجی روی یک پرونده. */
    private function failure(PermitCase $case, string $ruleKey, string $scope): ValidationResult
    {
        return ValidationResult::create([
            'case_id' => $case->id,
            'rule_key' => $ruleKey,
            'scope' => $scope,
            'status' => 'failed',
            'message_fa' => 'ایراد آزمایشی روی قانون '.$ruleKey,
        ]);
    }

    /** یک فیلد استخراج‌شده با اطمینان مشخص. */
    private function reading(
        PermitCase $case,
        ?CaseDocument $document,
        string $fieldKey,
        float $confidence,
        string $source = 'ocr',
        ?string $raw = null,
    ): ExtractedField {
        return ExtractedField::create([
            'case_id' => $case->id,
            'case_document_id' => $document?->id,
            'field_key' => $fieldKey,
            'raw_value' => $raw ?? ('مقدار آزمایشی '.$fieldKey),
            'normalized_value' => null,
            'confidence' => $confidence,
            'source' => $source,
            'corrected_at' => $source === 'manual' ? now() : null,
        ]);
    }
}
