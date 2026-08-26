<?php

namespace Tests\Feature;

use App\Models\CaseDocument;
use App\Models\ExtractedField;
use App\Models\PermitCase;
use App\Services\Cases\CaseScorer;
use App\Services\Cases\DocumentValidator;
use App\Services\Cases\FieldDeriver;
use App\Support\PersianValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsCases;
use Tests\TestCase;

/**
 * تسک ۷۲۵ — تاریخ انقضای گواهینامه از روی تاریخ صدور.
 *
 * قالب گواهینامه تاریخ انقضا را چاپ نمی‌کند، پس `license_expire_date` همیشه
 * خالی می‌ماند و اعتبار زمانی گواهینامه اصلاً بررسی نمی‌شد. حالا این تاریخ از
 * «تاریخ صدور + ۱۰ سال» محاسبه می‌شود.
 *
 * چهار چیزی که این تست نگهبانشان است:
 *   ۱) مقدار محاسبه‌شده جای خالی را پر می‌کند، نه جای خوانده‌شده یا دست‌نویس را.
 *   ۲) گواهینامهٔ قدیمی «منقضی» می‌شود و پیامش می‌گوید تاریخ محاسبه شده است.
 *   ۳) اطمینانِ محاسبه همان اطمینان تاریخ صدور است — با اطمینان پایین، «رد قطعی»
 *      صادر نمی‌شود بلکه «مشکوک» می‌شود.
 *   ۴) مبدأ که عوض شد مقدار محاسبه‌شده تازه می‌شود، و مبدأ که رفت پاک می‌شود.
 */
class LicenseExpiryDerivationTest extends TestCase
{
    use BuildsCases;
    use RefreshDatabase;

    /** ۱۴۰۵/۰۵/۳۰ شمسی — «امروزِ» ثابت این تست. */
    private const TODAY_GREGORIAN = '2026-08-21 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::TODAY_GREGORIAN, 'Asia/Tehran'));
        $this->seedReferenceData();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // محاسبه
    // ------------------------------------------------------------------

    public function test_expiry_is_derived_from_the_issue_date_plus_ten_years(): void
    {
        [$case, $document] = $this->licenseCase('۱۳۹۸/۰۹/۰۵');

        $this->assertSame(1, (new FieldDeriver)->derive($document));

        $row = $this->expiryRow($case);

        $this->assertNotNull($row);
        $this->assertSame('۱۴۰۸/۰۹/۰۵', $row->normalized_value);
        $this->assertSame(FieldDeriver::SOURCE, $row->source);
        // ردِ محاسبه در خودِ ردیف می‌ماند تا فردا معلوم باشد از کجا آمده
        $this->assertSame('۱۳۹۸/۰۹/۰۵', $row->raw_value);
        // اطمینان دقیقاً همان اطمینان مبدأ است، نه ۱۰۰
        $this->assertSame('95.00', (string) $row->confidence);
    }

    public function test_the_thirtieth_of_esfand_falls_back_to_the_last_day_of_that_month(): void
    {
        // ۱۳۹۹ کبیسه است و ۳۰ اسفند دارد؛ ۱۴۰۹ ندارد.
        $this->assertSame('1409/12/29', FieldDeriver::addJalaliYears(1399, 12, 30, 10));
        $this->assertSame('1412/12/30', FieldDeriver::addJalaliYears(1402, 12, 30, 10));
        $this->assertSame('1408/09/05', FieldDeriver::addJalaliYears(1398, 9, 5, 10));
    }

    public function test_running_twice_does_not_add_a_second_row(): void
    {
        [$case, $document] = $this->licenseCase('۱۳۹۸/۰۹/۰۵');

        (new FieldDeriver)->derive($document);
        (new FieldDeriver)->derive($document);

        $this->assertSame(1, ExtractedField::query()
            ->where('case_id', $case->id)
            ->where('field_key', 'license_expire_date')
            ->count());
    }

    // ------------------------------------------------------------------
    // مرزها: محاسبه جای خوانده‌شده و دست‌نویس را نمی‌گیرد
    // ------------------------------------------------------------------

    public function test_a_value_read_by_the_engine_is_never_overwritten(): void
    {
        [$case, $document] = $this->licenseCase('۱۳۹۸/۰۹/۰۵');

        $this->writeField($case, $document, 'license_expire_date', '۱۴۱۲/۰۱/۰۱', 70.0, 'ocr');

        $this->assertSame(0, (new FieldDeriver)->derive($document));

        $row = $this->expiryRow($case);

        $this->assertSame('۱۴۱۲/۰۱/۰۱', $row->normalized_value);
        $this->assertSame('ocr', $row->source);
    }

    public function test_a_correction_by_the_expert_is_never_overwritten(): void
    {
        [$case, $document] = $this->licenseCase('۱۳۹۸/۰۹/۰۵');

        $this->writeField($case, $document, 'license_expire_date', '۱۴۱۰/۰۲/۰۲', 100.0, 'manual');

        $this->assertSame(0, (new FieldDeriver)->derive($document));

        $this->assertSame('manual', $this->expiryRow($case)->source);
    }

    // ------------------------------------------------------------------
    // تازه‌ماندن
    // ------------------------------------------------------------------

    public function test_changing_the_issue_date_refreshes_the_derived_value(): void
    {
        [$case, $document] = $this->licenseCase('۱۳۹۸/۰۹/۰۵');

        (new FieldDeriver)->derive($document);

        $this->writeField($case, $document, 'license_issue_date', '۱۴۰۰/۰۳/۱۱', 100.0, 'manual');

        (new FieldDeriver)->derive($document);

        $row = $this->expiryRow($case);

        $this->assertSame('۱۴۱۰/۰۳/۱۱', $row->normalized_value);
        $this->assertSame(FieldDeriver::SOURCE, $row->source);
        $this->assertSame('100.00', (string) $row->confidence);
    }

    public function test_an_unreadable_issue_date_removes_the_derived_value(): void
    {
        [$case, $document] = $this->licenseCase('۱۳۹۸/۰۹/۰۵');

        (new FieldDeriver)->derive($document);
        $this->assertNotNull($this->expiryRow($case));

        ExtractedField::query()
            ->where('case_id', $case->id)
            ->where('field_key', 'license_issue_date')
            ->update(['normalized_value' => 'خط‌خطی', 'raw_value' => 'خط‌خطی']);

        $this->assertSame(0, (new FieldDeriver)->derive($document->fresh()));
        $this->assertNull($this->expiryRow($case));
    }

    public function test_without_an_issue_date_nothing_is_derived(): void
    {
        [$case, $document] = $this->licenseCase(null);

        $this->assertSame(0, (new FieldDeriver)->derive($document));
        $this->assertNull($this->expiryRow($case));
    }

    // ------------------------------------------------------------------
    // اثر روی اعتبارسنجی — همان چیزی که تسک می‌خواست
    // ------------------------------------------------------------------

    public function test_an_old_license_is_reported_as_expired_and_the_message_says_it_was_computed(): void
    {
        // ۱۳۹۰ + ۱۰ = ۱۴۰۰، و امروزِ تست ۱۴۰۵/۰۵/۳۰ است
        [$case, $document] = $this->licenseCase('۱۳۹۰/۰۲/۱۵');

        (new FieldDeriver)->derive($document);
        (new DocumentValidator)->validate($case);

        $row = $case->validationResults()
            ->where('rule_key', 'document.expired.driving_license')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('۱۴۰۰/۰۲/۱۵', $row->message_fa);
        $this->assertStringContainsString('محاسبه شده', $row->message_fa);
        $this->assertStringContainsString('تاریخ صدور', $row->message_fa);
    }

    public function test_a_current_license_passes_the_expiry_check(): void
    {
        [$case, $document] = $this->licenseCase('۱۴۰۰/۰۲/۱۵');

        (new FieldDeriver)->derive($document);
        (new DocumentValidator)->validate($case);

        $row = $case->validationResults()
            ->where('rule_key', 'document.expired.driving_license')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('passed', $row->status);
        $this->assertStringContainsString('۱۴۱۰/۰۲/۱۵', $row->message_fa);
    }

    public function test_a_badly_read_issue_date_only_makes_the_expiry_suspicious(): void
    {
        [$case, $document] = $this->licenseCase('۱۳۹۰/۰۲/۱۵', confidence: 20.0);

        (new FieldDeriver)->derive($document);
        (new DocumentValidator)->validate($case);

        $row = $case->validationResults()
            ->where('rule_key', 'document.expired.driving_license')
            ->first();

        $this->assertNotNull($row);
        // «رد قطعی» روی تاریخی که موتور بد خوانده صادر نمی‌شود
        $this->assertSame('warning', $row->status);
    }

    public function test_before_this_task_the_expiry_check_had_nothing_to_judge(): void
    {
        // بدون مقدار محاسبه‌شده، همان رفتار قدیمی: قضاوت‌نشده
        [$case] = $this->licenseCase('۱۳۹۰/۰۲/۱۵');

        (new DocumentValidator)->validate($case);

        $row = $case->validationResults()
            ->where('rule_key', 'document.expired.driving_license')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('skipped', $row->status);
    }

    public function test_the_derived_value_stays_out_of_the_ocr_quality_score(): void
    {
        [$case, $document] = $this->licenseCase('۱۳۹۸/۰۹/۰۵', confidence: 40.0);

        (new DocumentValidator)->validate($case);
        (new CaseScorer)->score($case);

        $before = $case->scoreComponents()->where('component_key', 'ocr_quality')->value('value');

        (new FieldDeriver)->derive($document);
        (new CaseScorer)->score($case->fresh());

        $after = $case->scoreComponents()->where('component_key', 'ocr_quality')->value('value');

        // مقدار محاسبه‌شده رونوشتِ اطمینان تاریخ صدور است؛ اگر وارد میانگین
        // می‌شد همان یک خواندنِ ضعیف دو بار شمرده می‌شد.
        $this->assertSame((string) $before, (string) $after);
    }

    // ------------------------------------------------------------------
    // صفحهٔ پرونده و اصلاح کارشناس
    // ------------------------------------------------------------------

    public function test_the_case_page_marks_the_value_as_computed(): void
    {
        [$case, $document] = $this->licenseCase('۱۴۰۰/۰۲/۱۵');

        (new FieldDeriver)->derive($document);

        $this->actingAs($this->expertUser())
            ->get(route('cases.show', $case))
            ->assertOk()
            ->assertSee('۱۴۱۰/۰۲/۱۵')
            ->assertSee('محاسبه‌شده', false)
            ->assertSee('روی مدرک چاپ نشده است', false);
    }

    public function test_correcting_the_issue_date_through_the_panel_refreshes_the_expiry(): void
    {
        [$case, $document] = $this->licenseCase('۱۳۹۰/۰۲/۱۵');

        (new FieldDeriver)->derive($document);
        $this->assertSame('۱۴۰۰/۰۲/۱۵', $this->expiryRow($case)->normalized_value);

        $case->forceFill(['status' => 'needs_review', 'decision' => 'needs_review'])->save();

        $this->actingAs($this->expertUser())->post(
            route('cases.fields.update', ['case' => $case->id, 'document' => $document->id]),
            ['fields' => ['license_issue_date' => '۱۴۰۰/۰۲/۱۵']],
        )->assertRedirect(route('cases.show', $case));

        $row = $this->expiryRow($case);

        $this->assertSame('۱۴۱۰/۰۲/۱۵', $row->normalized_value);
        $this->assertSame(FieldDeriver::SOURCE, $row->source);
    }

    // ------------------------------------------------------------------
    // کمکی‌ها
    // ------------------------------------------------------------------

    /**
     * پرونده‌ای با گواهینامه‌ای که فقط تاریخ صدور دارد.
     *
     * @return array{0: PermitCase, 1: CaseDocument}
     */
    private function licenseCase(?string $issueDate, float $confidence = 95.0): array
    {
        $case = $this->makeCaseWithDocuments('issue');

        /** @var CaseDocument $document */
        $document = $case->documents
            ->firstWhere('document_type_id', $this->documentTypeId('driving_license'));

        $document->update(['ocr_status' => 'done']);

        if ($issueDate !== null) {
            $this->writeField($case, $document, 'license_issue_date', $issueDate, $confidence, 'ocr');
        }

        return [$case->fresh(['documents.documentType']), $document->fresh('documentType')];
    }

    private function writeField(
        PermitCase $case,
        CaseDocument $document,
        string $key,
        string $value,
        float $confidence,
        string $source,
    ): void {
        ExtractedField::query()->updateOrCreate(
            ['case_id' => $case->id, 'case_document_id' => $document->id, 'field_key' => $key],
            [
                'raw_value' => $value,
                'normalized_value' => PersianValue::forEngine('jalali_date', $value),
                'confidence' => $confidence,
                'source' => $source,
            ],
        );
    }

    private function expiryRow(PermitCase $case): ?ExtractedField
    {
        return ExtractedField::query()
            ->where('case_id', $case->id)
            ->where('field_key', 'license_expire_date')
            ->first();
    }
}
