<?php

namespace Tests\Feature;

use App\Models\CaseDocument;
use App\Models\DatasetAnnotation;
use App\Models\DatasetSample;
use App\Models\ExtractedField;
use App\Models\PermitCase;
use App\Models\ValidationResult;
use App\Services\Cases\CaseScorer;
use App\Support\PersianValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCases;
use Tests\TestCase;

/**
 * تسک ۶۳۵ — صفحهٔ نتیجهٔ پرونده و بررسی انسانی.
 *
 * بیشتر تست‌ها ردیف‌های دیتابیس را مستقیم با مدل می‌سازند تا سریع بمانند؛
 * یک تست انتها‌به‌انتها هم هست که واقعاً DocumentValidator و CaseScorer را صدا
 * می‌زند و ثابت می‌کند اصلاح کارشناس امتیاز پرونده را عوض می‌کند.
 */
class CaseReviewTest extends TestCase
{
    use BuildsCases;
    use RefreshDatabase;

    /** کد ملی معتبر (رقم کنترل درست) که روی هر سه مدرک یکی است. */
    private const NID = '1122334451';

    /** کد ملی معتبرِ دیگری که عمداً روی گواهینامه بد خوانده شده است. */
    private const NID_WRONG = '4567891236';

    /** کد ملیِ نامعتبر — برای تست اینکه اصلاح غلط پذیرفته نمی‌شود. */
    private const NID_INVALID = '1234567890';

    /**
     * مقدار «درست» هر فیلد روی هر نوع مدرک.
     *
     * دادهٔ کاملاً ساختگی است (قانون پروژه: هیچ مدرک هویتی واقعی در مخزن نیست).
     */
    private const VALUES = [
        'national_card' => [
            'national_id' => self::NID,
            'first_name' => 'علی',
            'last_name' => 'رضایی',
            'birth_date' => '1370/01/01',
            'father_name' => 'حسن',
            'national_card_expire' => '1450/01/01',
        ],
        'driving_license' => [
            'national_id' => self::NID,
            'full_name' => 'علی رضایی',
            'birth_date' => '1370/01/01',
            'license_issue_date' => '1400/05/05',
            'license_expire_date' => '1450/05/05',
            'license_number' => '12345678',
        ],
        'vehicle_card' => [
            'full_name' => 'علی رضایی',
            'national_id' => self::NID,
            'father_name' => 'حسن',
            'vin' => 'WDB1234567890ABCD',
            'plate_number' => '12 ب 345 ایران 67',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        $this->fakeDisks();
    }

    // ==================================================================
    // صف بررسی
    // ==================================================================

    public function test_review_queue_lists_only_cases_that_need_review(): void
    {
        $waiting = PermitCase::factory()->submitted()->create([
            'code' => 'HA-TEST-000901',
            'status' => 'needs_review',
            'confidence_score' => 63.5,
            'decision' => 'needs_review',
            'decision_reason' => 'امتیاز بین دو آستانه افتاد.',
        ]);

        $approved = PermitCase::factory()->create([
            'code' => 'HA-TEST-000902',
            'status' => 'approved',
            'decision' => 'approved',
        ]);

        $draft = PermitCase::factory()->create(['code' => 'HA-TEST-000903']);

        $response = $this->actingAs($this->expertUser())->get(route('cases.review'));

        $response->assertOk();
        $response->assertSee(PersianValue::toPersianDigits($waiting->code));
        $response->assertDontSee(PersianValue::toPersianDigits($approved->code));
        $response->assertDontSee(PersianValue::toPersianDigits($draft->code));
    }

    public function test_review_queue_is_closed_to_the_data_expert(): void
    {
        $this->actingAs($this->dataUser())
            ->get(route('cases.review'))
            ->assertForbidden();
    }

    public function test_expert_may_open_a_case_created_by_someone_else(): void
    {
        // بررسی انسانی یعنی کسی غیر از ثبت‌کننده پرونده را ببیند؛ اگر مالکیت
        // این‌جا هم اعمال می‌شد، صف بررسی عملاً بی‌فایده بود.
        $case = $this->reviewableCase($this->adminUser());

        $this->actingAs($this->expertUser())
            ->get(route('cases.show', $case))
            ->assertOk();
    }

    // ==================================================================
    // صفحهٔ نتیجه
    // ==================================================================

    public function test_result_page_shows_score_reasons_and_each_document_image_next_to_its_fields(): void
    {
        $case = $this->reviewableCase();
        $this->fillFields($case);

        $case->forceFill(['confidence_score' => 68.25, 'decision' => 'needs_review'])->save();

        $case->scoreComponents()->create([
            'component_key' => 'ocr_quality',
            'label_fa' => 'کیفیت تشخیص متن (OCR)',
            'weight' => 40,
            'value' => 52.5,
            'contribution' => 21,
            'note_fa' => 'میانگین اطمینان OCR روی ۱۷ فیلد برابر ۵۲٫۵ است.',
        ]);

        $case->validationResults()->create([
            'rule_key' => 'cross.national_id',
            'scope' => 'cross',
            'status' => 'passed',
            'message_fa' => 'کد ملی روی هر ۳ مدرک یکی است.',
            'details' => [
                'field' => 'national_id',
                'label' => 'کد ملی',
                'match_mode' => 'exact',
                'reference' => [
                    'document_label' => 'کارت ملی',
                    'value' => PersianValue::toPersianDigits(self::NID),
                    'confidence' => 88,
                    'source' => 'ocr',
                ],
                'compared' => [[
                    'document_label' => 'گواهینامه رانندگی',
                    'value' => PersianValue::toPersianDigits(self::NID),
                    'confidence' => 71,
                    'source' => 'ocr',
                    'verdict' => 'match',
                    'similarity' => 100,
                ]],
            ],
        ]);

        $case->validationResults()->create([
            'rule_key' => 'document.expired.driving_license',
            'scope' => 'document',
            'status' => 'failed',
            'message_fa' => 'مدرک «گواهینامه رانندگی» منقضی شده است.',
            'details' => ['document' => 'driving_license', 'label' => 'گواهینامه رانندگی'],
        ]);

        $card = $this->documentOf($case, 'national_card');

        $response = $this->actingAs($this->expertUser())->get(route('cases.show', $case));

        $response->assertOk();

        // وضعیت نهایی و امتیاز اطمینان
        $response->assertSee('نیاز به بررسی');
        $response->assertSee('۶۸٫۳'); // امتیاز با یک رقم اعشار و ارقام فارسی
        $response->assertSee('کیفیت تشخیص متن (OCR)');

        // تصویر مدرک، از روت محافظت‌شدهٔ media و نه از دیسک عمومی
        $response->assertSee('id="doc-image-'.$card->id.'"', false);
        $response->assertSee(
            e(route('media', ['disk' => $card->disk, 'path' => $card->path, 'w' => 800])),
            false,
        );

        // و فیلدهای همان مدرک، به‌شکل ورودی قابل اصلاح، در همان صفحه
        $response->assertSee('name="fields[national_id]"', false);
        $response->assertSee(PersianValue::toPersianDigits(self::NID));

        // فهرست دلایل: هم پیام سبز، هم پیام قرمز
        $response->assertSee('کد ملی روی هر ۳ مدرک یکی است.');
        $response->assertSee('مدرک «گواهینامه رانندگی» منقضی شده است.');
        $response->assertSee('پاس شد');
        $response->assertSee('رد شد');
    }

    public function test_draft_case_is_sent_back_to_the_upload_wizard(): void
    {
        $case = $this->makeCaseWithDocuments('issue');

        $this->actingAs($this->expertUser())
            ->post(route('cases.decide', $case), ['decision' => 'approved'])
            ->assertRedirect(route('cases.documents.edit', $case));

        $this->assertNull($case->fresh()->decision);
    }

    // ==================================================================
    // اصلاح فیلد
    // ==================================================================

    public function test_expert_correction_is_saved_as_manual_and_returns_to_the_dataset(): void
    {
        $case = $this->reviewableCase();

        // OCR کد ملی گواهینامه را اشتباه خوانده؛ همان چیزی که کارشناس اصلاح می‌کند.
        $this->fillFields($case, 50.0, [
            'driving_license' => ['national_id' => ['value' => self::NID_WRONG]],
        ]);

        $license = $this->documentOf($case, 'driving_license');
        Storage::disk($license->disk)->put($license->path, 'تصویر مصنوعی آزمایشی');

        $expert = $this->expertUser();

        $response = $this->actingAs($expert)->post(
            route('cases.fields.update', [$case, $license]),
            ['fields' => ['national_id' => self::NID]],
        );

        $response->assertRedirect(route('cases.show', $case));

        $field = ExtractedField::query()
            ->where('case_document_id', $license->id)
            ->where('field_key', 'national_id')
            ->firstOrFail();

        $this->assertSame('manual', $field->source);
        $this->assertSame(PersianValue::toPersianDigits(self::NID), $field->normalized_value);
        $this->assertEqualsWithDelta(100.0, (float) $field->confidence, 0.01);
        $this->assertSame($expert->id, $field->corrected_by);
        $this->assertNotNull($field->corrected_at);

        // قانون ۱۳۰ — همان اصلاح باید دادهٔ آموزشی شود
        $sample = DatasetSample::query()
            ->where('disk', $license->disk)
            ->where('path', $license->path)
            ->firstOrFail();

        $this->assertSame('uploaded', $sample->source);
        $this->assertSame($license->document_type_id, $sample->document_type_id);

        $annotation = DatasetAnnotation::query()
            ->where('dataset_sample_id', $sample->id)
            ->where('field_key', 'national_id')
            ->firstOrFail();

        $this->assertSame('correction', $annotation->source);
        $this->assertSame(PersianValue::toPersianDigits(self::NID), $annotation->value);
        $this->assertSame($expert->id, $annotation->created_by);

        // اصلاح دوم روی همان مدرک نمونهٔ تکراری نمی‌سازد
        $this->actingAs($expert)->post(
            route('cases.fields.update', [$case, $license]),
            ['fields' => ['license_number' => '87654321']],
        );

        $this->assertSame(1, DatasetSample::query()->where('path', $license->path)->count());
        $this->assertSame(2, DatasetAnnotation::query()->where('dataset_sample_id', $sample->id)->count());
    }

    public function test_correction_with_an_invalid_national_id_is_refused_with_a_persian_reason(): void
    {
        $case = $this->reviewableCase();
        $this->fillFields($case);

        $license = $this->documentOf($case, 'driving_license');

        $response = $this->actingAs($this->expertUser())->post(
            route('cases.fields.update', [$case, $license]),
            ['fields' => ['national_id' => self::NID_INVALID]],
        );

        $response->assertSessionHasErrors('fields.national_id');

        $field = ExtractedField::query()
            ->where('case_document_id', $license->id)
            ->where('field_key', 'national_id')
            ->firstOrFail();

        // هیچ چیزی ذخیره نشده و ردیف همان خروجی OCR مانده است
        $this->assertSame('ocr', $field->source);
        $this->assertSame(PersianValue::toPersianDigits(self::NID), $field->normalized_value);
        $this->assertSame(0, DatasetSample::query()->count());
    }

    public function test_empty_value_is_refused_because_it_would_freeze_the_field_forever(): void
    {
        $case = $this->reviewableCase();
        $this->fillFields($case);

        $license = $this->documentOf($case, 'driving_license');

        $this->actingAs($this->expertUser())
            ->post(route('cases.fields.update', [$case, $license]), ['fields' => ['national_id' => '']])
            ->assertSessionHasErrors('fields.national_id');

        $this->assertSame('ocr', ExtractedField::query()
            ->where('case_document_id', $license->id)
            ->where('field_key', 'national_id')
            ->value('source'));
    }

    public function test_unchanged_values_do_not_become_manual_corrections(): void
    {
        // فرم هر مدرک همهٔ فیلدهایش را می‌فرستد؛ اگر همه «دستی» می‌شدند،
        // خروجی OCR برای همیشه منجمد می‌شد (تسک ۶۳۲ روی manual نمی‌نویسد).
        $case = $this->reviewableCase();
        $this->fillFields($case);

        $license = $this->documentOf($case, 'driving_license');

        $payload = [];

        foreach ($license->documentType->fields as $field) {
            $payload[$field->key] = PersianValue::forEngine(
                $field->value_type,
                self::VALUES['driving_license'][$field->key] ?? '',
            );
        }

        $this->actingAs($this->expertUser())
            ->post(route('cases.fields.update', [$case, $license]), ['fields' => $payload])
            ->assertRedirect(route('cases.show', $case));

        $this->assertSame(0, ExtractedField::query()
            ->where('case_document_id', $license->id)
            ->where('source', 'manual')
            ->count());

        $this->assertSame(0, DatasetSample::query()->count());
    }

    public function test_a_document_from_another_case_is_not_editable_here(): void
    {
        $case = $this->reviewableCase();
        $other = $this->reviewableCase();

        $foreign = $this->documentOf($other, 'national_card');

        $this->actingAs($this->expertUser())
            ->post(route('cases.fields.update', [$case, $foreign]), ['fields' => ['national_id' => self::NID]])
            ->assertNotFound();
    }

    // ==================================================================
    // تصمیم
    // ==================================================================

    public function test_rejecting_without_a_reason_is_refused(): void
    {
        $case = $this->reviewableCase();

        $this->actingAs($this->expertUser())
            ->post(route('cases.decide', $case), ['decision' => 'rejected', 'reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertFalse((bool) $case->fresh()->decision_is_manual);
    }

    public function test_manual_decision_is_recorded_and_survives_a_rescore(): void
    {
        $case = $this->reviewableCase();
        $this->fillFields($case);

        $expert = $this->expertUser();

        $this->actingAs($expert)
            ->post(route('cases.decide', $case), ['decision' => 'approved'])
            ->assertRedirect(route('cases.show', $case));

        $case->refresh();

        $this->assertSame('approved', $case->decision);
        $this->assertSame('approved', $case->status);
        $this->assertTrue((bool) $case->decision_is_manual);
        $this->assertSame($expert->id, $case->decided_by);
        $this->assertNotNull($case->decided_at);
        $this->assertStringContainsString($expert->name, (string) $case->decision_reason);

        // اجرای دوبارهٔ امتیازدهی نباید تصمیم انسانی را دور بریزد (قرارداد تسک ۶۳۴)
        app(CaseScorer::class)->score($case->fresh());

        $case->refresh();
        $this->assertSame('approved', $case->decision);
        $this->assertTrue((bool) $case->decision_is_manual);
    }

    public function test_expert_can_take_back_a_manual_decision(): void
    {
        $case = $this->reviewableCase();
        $this->fillFields($case);

        $expert = $this->expertUser();

        $this->actingAs($expert)->post(route('cases.decide', $case), [
            'decision' => 'rejected',
            'reason' => 'تصویر گواهینامه ناخوانا بود.',
        ]);

        $this->assertSame('rejected', $case->fresh()->status);

        $this->actingAs($expert)->post(route('cases.decide', $case), ['decision' => 'reset']);

        $case->refresh();

        $this->assertFalse((bool) $case->decision_is_manual);
        $this->assertNull($case->decided_by);
        // با برداشتن پرچم، امتیازدهی دوباره خودش تصمیم گرفته است
        $this->assertContains($case->decision, ['approved', 'rejected', 'needs_review']);
        $this->assertNotNull($case->confidence_score);
    }

    // ==================================================================
    // انتها به انتها — همان کاری که در «تعریف done» خواسته شده
    // ==================================================================

    /**
     * کارشناس یک پروندهٔ مشکوک را باز می‌کند، یک فیلد را اصلاح می‌کند، امتیاز
     * واقعاً عوض می‌شود و تصمیم نهایی‌اش ثبت می‌شود.
     *
     * تنها تستی است که DocumentValidator و CaseScorer را واقعاً صدا می‌زند.
     */
    public function test_end_to_end_correction_moves_the_score_and_the_decision_sticks(): void
    {
        $case = $this->reviewableCase();

        // کد ملی روی گواهینامه بد خوانده شده: مقدارش با کارت ملی نمی‌خواند و
        // اطمینانش هم زیر آستانه است، پس «مشکوک» می‌شود نه «رد قطعی» — دقیقاً
        // همان پرونده‌ای که باید به دست انسان برسد.
        $this->fillFields($case, 50.0, [
            'driving_license' => [
                'national_id' => ['value' => self::NID_WRONG, 'confidence' => 30.0],
            ],
        ]);

        $license = $this->documentOf($case, 'driving_license');
        Storage::disk($license->disk)->put($license->path, 'تصویر مصنوعی آزمایشی');

        // پردازش خودکار: اعتبارسنجی اسناد + امتیازدهی
        app(\App\Services\Cases\DocumentValidator::class)->validate($case);
        app(CaseScorer::class)->score($case);

        $case->refresh();

        $this->assertSame('needs_review', $case->status, 'پروندهٔ آزمایشی باید در بازهٔ بررسی انسانی بیفتد.');

        $scoreBefore = (float) $case->confidence_score;

        $crossBefore = ValidationResult::query()
            ->where('case_id', $case->id)
            ->where('rule_key', 'cross.national_id')
            ->firstOrFail();

        $this->assertSame('warning', $crossBefore->status);

        // کارشناس صفحه را باز می‌کند و ناهمخوانی را می‌بیند
        $expert = $this->expertUser();

        $this->actingAs($expert)
            ->get(route('cases.show', $case))
            ->assertOk()
            ->assertSee('کد ملی');

        // و همان یک فیلد را با نگاه به تصویر مدرک اصلاح می‌کند
        $this->actingAs($expert)->post(
            route('cases.fields.update', [$case, $license]),
            ['fields' => ['national_id' => self::NID]],
        )->assertRedirect(route('cases.show', $case));

        $case->refresh();

        $scoreAfter = (float) $case->confidence_score;

        $this->assertGreaterThan(
            $scoreBefore,
            $scoreAfter,
            'اصلاح کارشناس باید امتیاز اطمینان را بالا ببرد؛ وگرنه اثر کارش دیده نمی‌شود.',
        );

        $crossAfter = ValidationResult::query()
            ->where('case_id', $case->id)
            ->where('rule_key', 'cross.national_id')
            ->firstOrFail();

        $this->assertSame('passed', $crossAfter->status);

        // و اصلاح به دیتاست تگ‌گذاری برگشته است
        $this->assertSame(1, DatasetAnnotation::query()->where('source', 'correction')->count());

        // تصمیم نهایی انسانی — حتی وقتی ماشین چیز دیگری می‌گوید
        $this->actingAs($expert)->post(route('cases.decide', $case), [
            'decision' => 'rejected',
            'reason' => 'با وجود اصلاح کد ملی، تصویر کارت خودرو نصفه است و باید دوباره ارسال شود.',
        ])->assertRedirect(route('cases.show', $case));

        $case->refresh();

        $this->assertSame('rejected', $case->decision);
        $this->assertSame('rejected', $case->status);
        $this->assertTrue((bool) $case->decision_is_manual);
        $this->assertSame($expert->id, $case->decided_by);
        $this->assertStringContainsString('تصویر کارت خودرو نصفه است', (string) $case->decision_reason);

        // اجرای دوبارهٔ کل امتیازدهی هم تصمیم انسان را عوض نمی‌کند
        app(CaseScorer::class)->score($case->fresh());

        $this->assertSame('rejected', $case->fresh()->decision);
    }

    // ==================================================================
    // ابزار تست
    // ==================================================================

    /** پروندهٔ ثبت‌شده با همهٔ مدارک لازم و مدارکِ OCR‌شده. */
    private function reviewableCase(?\App\Models\User $owner = null): PermitCase
    {
        $case = $this->makeCaseWithDocuments('issue', $owner);

        $case->forceFill(['status' => 'needs_review', 'submitted_at' => now()])->save();

        CaseDocument::query()
            ->where('case_id', $case->id)
            ->update(['ocr_status' => 'done']);

        return $case->fresh(['documents.documentType.fields']);
    }

    private function documentOf(PermitCase $case, string $typeKey): CaseDocument
    {
        return CaseDocument::query()
            ->where('case_id', $case->id)
            ->where('document_type_id', $this->documentTypeId($typeKey))
            ->with('documentType.fields')
            ->firstOrFail();
    }

    /**
     * فیلدهای خوانده‌شدهٔ همهٔ مدارک پرونده را با دادهٔ ساختگیِ سازگار پر می‌کند.
     *
     * @param  array<string, array<string, array{value?: string, confidence?: float}>>  $overrides
     */
    private function fillFields(PermitCase $case, float $confidence = 50.0, array $overrides = []): void
    {
        $case->load('documents.documentType.fields');

        foreach ($case->documents as $document) {
            $typeKey = (string) $document->documentType?->key;
            $values = self::VALUES[$typeKey] ?? [];

            foreach ($document->documentType?->fields ?? [] as $field) {
                $override = $overrides[$typeKey][$field->key] ?? [];
                $raw = $override['value'] ?? ($values[$field->key] ?? null);

                if ($raw === null) {
                    continue;
                }

                ExtractedField::query()->create([
                    'case_id' => $case->id,
                    'case_document_id' => $document->id,
                    'field_key' => $field->key,
                    'raw_value' => $raw,
                    'normalized_value' => PersianValue::forEngine($field->value_type, $raw),
                    'confidence' => $override['confidence'] ?? $confidence,
                    'source' => 'ocr',
                ]);
            }
        }

        $case->unsetRelation('extractedFields');
    }
}
