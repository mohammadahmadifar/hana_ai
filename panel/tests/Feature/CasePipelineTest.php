<?php

namespace Tests\Feature;

use App\Exceptions\EngineException;
use App\Jobs\ProcessCase;
use App\Models\CaseDocument;
use App\Models\ExtractedField;
use App\Models\OcrRun;
use App\Models\PermitCase;
use App\Models\ScoreComponent;
use App\Models\ServiceType;
use App\Models\User;
use App\Models\ValidationResult;
use App\Services\Cases\CasePipeline;
use App\Services\HanaEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\BuildsCases;
use Tests\TestCase;

/**
 * تسک ۶۳۱ — زنجیرهٔ کامل فرایند مجوز.
 *
 * این فایل ادعای «تعریف done» را می‌سنجد: بعد از ثبت پرونده، کار روی صف
 * `ocr` می‌رود و وقتی اجرا شد، متن هر مدرک و فیلدها و بررسی‌ها و امتیاز
 * پرونده سر جایشان هستند.
 *
 * دو چیز که این‌جا مهم‌تر از «مسیر خوش‌بینانه» است:
 *   ۱) مدرکی که اعتبارسنجی اولیه ردش کرده نباید خرج موتور بشود.
 *   ۲) هیچ خطایی نباید پرونده را در «در حال پردازش» گیر بیندازد — نه خطای
 *      موتور، نه timeout ِ Job.
 *
 * متن‌های OCR این فایل کپی خروجی واقعی موتور روی نمونه‌های مصنوعیِ ژنراتور
 * هستند (همان‌هایی که تست تسک ۶۳۲ استفاده می‌کند)، نه متن دست‌سازِ تمیز.
 */
class CasePipelineTest extends TestCase
{
    use BuildsCases;
    use RefreshDatabase;

    /** @var array<string, string> خروجی واقعی OCR برای هر نوع مدرک */
    private const TEXTS = [
        'national_card' => "شماره صلی ۸۵۳۹۴۲۵۷۳۴۰\nنام ایلیا\nهاشمی\nسوت ۱۳۶۵/۰۳/۲۳\nمحمد\nماع ۱۴۱۱/۰۹/۰۶ 4",
        'driving_license' => "شماره‌ملی ۸۵۳۹۴۲۵۷۳۴ نت۳\nنام نام خانوادگی ایلیا هاشمی\nتاریخ تولد ۱۳۶۵/۱۱/۰۶\nتاریخ صدور ۱۳۸۵/۰۳/۲۶ مدت اعتبار ۱۰ سال\nشماره گواهینامه ۳۲۲۷۰۲۸۹۲۱",
        'vehicle_card' => "مشخصات مالک : ایلیا هاشمی\nشماره ملی / کد شناسایی: ۸۵۳۹۴۲۵۷۳۴\nنام پدر | نماینده سازمان: محمد\nVIN : NAS675287M6771656\nPLATE : ۱۳ الف ۴۰۷ ۹۱",
        'previous_permit' => "مجوز حمل و نقل\nشماره ملی ۸۵۳۹۴۲۵۷۳۴\nایلیا هاشمی\nتاریخ اعتبار ۱۴۰۶/۰۵/۰۱",
    ];

    private static ?string $imageBytes = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeDisks();
        $this->seedReferenceData();
    }

    // ==================================================================
    // ۱) ثبت پرونده → صف `ocr`
    // ==================================================================

    public function test_submitting_a_case_hands_it_to_the_ocr_queue(): void
    {
        Queue::fake();

        $user = $this->expertUser();
        $case = $this->caseWithFiles('issue', $user);

        $this->actingAs($user)
            ->post(route('cases.submit', $case))
            ->assertRedirect();

        Queue::assertPushed(
            ProcessCase::class,
            fn (ProcessCase $job): bool => $job->caseId === $case->id && $job->queue === 'ocr',
        );

        $case->refresh();

        // کاربر بلافاصله «در حال پردازش» می‌بیند و منتظر بیدارشدن کارگر نمی‌ماند.
        $this->assertSame('processing', $case->status);
        $this->assertNotNull($case->submitted_at);
        $this->assertSame(
            ['queued', 'queued', 'queued'],
            $case->documents()->pluck('ocr_status')->all(),
        );
    }

    // ==================================================================
    // ۲) تعریف done: زنجیرهٔ کامل روی یک پروندهٔ سالم
    // ==================================================================

    public function test_the_chain_fills_text_fields_validations_and_score(): void
    {
        $case = $this->caseWithFiles('issue');

        $this->engineReadsEachDocument();

        $summary = app(CasePipeline::class)->run($case);

        // --- مرحلهٔ OCR: متن هر سه مدرک ذخیره شده ---
        $this->assertSame(3, $summary['ocr_done']);
        $this->assertSame(0, $summary['ocr_failed']);

        foreach ($case->documents()->with('documentType')->get() as $document) {
            $run = OcrRun::query()
                ->where('subject_type', CaseDocument::class)
                ->where('subject_id', $document->id)
                ->firstOrFail();

            $this->assertSame('done', $run->status, $document->documentType->key.' متن نخواند');
            $this->assertNotSame('', trim((string) $run->raw_text));
            $this->assertSame('done', $document->ocr_status);
        }

        // --- کلیدهای ویژهٔ کارت خودرو در extra ---
        $vehicle = $case->documents()->whereHas(
            'documentType',
            fn ($query) => $query->where('key', 'vehicle_card'),
        )->firstOrFail();

        $vehicleRun = OcrRun::query()->where('subject_id', $vehicle->id)->firstOrFail();

        $this->assertSame('NAS675287M6771656', $vehicleRun->extra['vin']);
        $this->assertNotNull($vehicleRun->extra['plate']);

        // --- مرحله‌های بعدی هم اجرا شده‌اند ---
        $this->assertGreaterThan(0, ExtractedField::query()->where('case_id', $case->id)->count());
        $this->assertGreaterThan(0, ValidationResult::query()->where('case_id', $case->id)->count());
        $this->assertSame(3, ScoreComponent::query()->where('case_id', $case->id)->count());

        // --- پرونده تمام‌شده است، نه گیرکرده ---
        $case->refresh();

        $this->assertContains($case->status, ['approved', 'rejected', 'needs_review']);
        $this->assertNotNull($case->decision);
        $this->assertNotNull($case->confidence_score);
        $this->assertNotNull($case->processed_at, 'زمان پایان پردازش باید ثبت شود');
        $this->assertNotNull($case->processing_ms, 'مدت کل پایپ‌لاین کار Job است و باید نوشته شود');
    }

    // ==================================================================
    // ۳) مدرکِ ردشده در اعتبارسنجی اولیه، خرج موتور ندارد
    // ==================================================================

    public function test_a_precheck_failed_document_is_never_sent_to_the_engine(): void
    {
        $case = $this->caseWithFiles('issue');

        $rejected = $case->documents->first();
        $rejected->forceFill(['precheck_status' => 'failed'])->save();

        // دقیقاً دو تماس، نه سه‌تا.
        $this->mock(HanaEngine::class, function (MockInterface $mock): void {
            $mock->shouldReceive('version')->andReturn(['engine_version' => 'hana-engine/9.9-test']);
            $mock->shouldReceive('ocrDocument')->twice()->andReturnUsing($this->engineAnswer());
        });

        $summary = app(CasePipeline::class)->run($case);

        $this->assertSame(2, $summary['ocr_done']);
        $this->assertSame(1, $summary['ocr_failed']);
        $this->assertSame('failed', $rejected->refresh()->ocr_status);

        // ولی پرونده باز هم به نتیجه می‌رسد، نه به بن‌بست.
        $this->assertNotSame('processing', $case->refresh()->status);
    }

    // ==================================================================
    // ۴) هیچ خطایی پرونده را در «در حال پردازش» گیر نمی‌اندازد
    // ==================================================================

    public function test_an_engine_outage_does_not_leave_the_case_processing(): void
    {
        $case = $this->caseWithFiles('issue');

        CasePipeline::markQueued($case);
        $this->assertSame('processing', $case->refresh()->status);

        $this->mock(HanaEngine::class, function (MockInterface $mock): void {
            $mock->shouldReceive('version')->andReturn(['engine_version' => 'hana-engine/9.9-test']);
            $mock->shouldReceive('ocrDocument')->andThrow(
                EngineException::fromEngine('ocr_document', 'موتور پردازش تصویر در دسترس نیست.'),
            );
        });

        app(CasePipeline::class)->run($case);

        $case->refresh();

        $this->assertNotSame('processing', $case->status);
        $this->assertNotNull($case->processed_at);
        $this->assertSame(['failed', 'failed', 'failed'], $case->documents()->pluck('ocr_status')->all());
    }

    public function test_a_crashed_job_cleans_the_case_up_instead_of_abandoning_it(): void
    {
        $case = $this->caseWithFiles('issue');

        CasePipeline::markQueued($case);

        // شبیه‌سازی timeout یا کرشِ کارگر: handle هرگز تمام نشد.
        (new ProcessCase($case->id))->failed(new RuntimeException('worker timeout'));

        $case->refresh();

        $this->assertSame('needs_review', $case->status);
        $this->assertSame('needs_review', $case->decision);
        $this->assertStringContainsString('ناتمام', (string) $case->decision_reason);
        $this->assertNotNull($case->processed_at);
        $this->assertSame(['failed', 'failed', 'failed'], $case->documents()->pluck('ocr_status')->all());

        // هر مدرک باید بگوید چرا متن ندارد.
        $this->assertSame(3, OcrRun::query()->where('status', 'failed')->count());
    }

    // ==================================================================
    // ۵) idempotent بودن
    // ==================================================================

    public function test_running_the_pipeline_twice_does_not_duplicate_anything(): void
    {
        $case = $this->caseWithFiles('issue');

        $this->engineReadsEachDocument();

        $pipeline = app(CasePipeline::class);

        $pipeline->run($case);

        $before = $this->rowCounts($case);

        $pipeline->run($case);

        $this->assertSame($before, $this->rowCounts($case), 'اجرای دوباره نباید ردیف تکراری بسازد');
    }

    public function test_a_manual_decision_survives_reprocessing(): void
    {
        $case = $this->caseWithFiles('issue');

        $case->forceFill([
            'status' => 'approved',
            'decision' => 'approved',
            'decision_reason' => 'کارشناس مدارک را حضوری دید.',
            'decision_is_manual' => true,
        ])->save();

        $this->engineReadsEachDocument();

        app(CasePipeline::class)->run($case);

        $case->refresh();

        // نه «در حال پردازش» رویش نوشته شد، نه تصمیم انسانی عوض شد.
        $this->assertSame('approved', $case->status);
        $this->assertSame('approved', $case->decision);
        $this->assertSame('کارشناس مدارک را حضوری دید.', $case->decision_reason);
        $this->assertNotNull($case->processing_ms);
    }

    // ==================================================================
    // ۶) اندپوینت وضعیت زنده
    // ==================================================================

    public function test_the_status_endpoint_reports_progress_then_the_result(): void
    {
        $user = $this->expertUser();
        $case = $this->caseWithFiles('issue', $user);

        CasePipeline::markQueued($case);

        $waiting = $this->actingAs($user)
            ->getJson(route('cases.processing.status', $case))
            ->assertOk()
            ->json();

        $this->assertTrue($waiting['processing']);
        $this->assertFalse($waiting['finished']);
        $this->assertSame(3, $waiting['counts']['total']);
        $this->assertSame(0, $waiting['percent']);
        $this->assertSame('در صف', $waiting['documents'][0]['ocr_status_label']);
        $this->assertStringContainsString('در حال پردازش', $waiting['message_fa']);

        $this->engineReadsEachDocument();
        app(CasePipeline::class)->run($case);

        $done = $this->actingAs($user)
            ->getJson(route('cases.processing.status', $case))
            ->assertOk()
            ->json();

        $this->assertFalse($done['processing']);
        $this->assertTrue($done['finished']);
        $this->assertSame(100, $done['percent']);
        $this->assertSame(3, $done['counts']['done']);
        $this->assertNotNull($done['confidence_score']);

        foreach ($done['documents'] as $row) {
            $this->assertTrue($row['has_text'], $row['label_fa'].' متن ندارد');
            $this->assertGreaterThan(0, $row['char_count']);
        }

        $vehicle = collect($done['documents'])->firstWhere('type_key', 'vehicle_card');
        $this->assertSame('NAS675287M6771656', $vehicle['vin']);
    }

    public function test_the_status_of_someone_elses_case_is_forbidden(): void
    {
        $case = $this->caseWithFiles('issue');

        $this->actingAs($this->expertUser())
            ->getJson(route('cases.processing.status', $case))
            ->assertForbidden();

        // مدیر سامانه همهٔ پرونده‌ها را می‌بیند.
        $this->actingAs($this->adminUser())
            ->getJson(route('cases.processing.status', $case))
            ->assertOk();
    }

    // ==================================================================
    // ۷) تنها تستی که موتور واقعی را روی کل زنجیره اجرا می‌کند
    // ==================================================================

    public function test_the_real_engine_finishes_a_whole_case_in_a_few_seconds(): void
    {
        try {
            $version = app(HanaEngine::class)->version();
        } catch (EngineException $exception) {
            $this->markTestSkipped('موتور پایتون در این محیط در دسترس نیست: '.$exception->getMessage());
        }

        if (($version['tesseract_version'] ?? null) === null) {
            $this->markTestSkipped('tesseract روی این ماشین نصب نیست.');
        }

        $case = $this->caseWithRealImages();

        if ($case === null) {
            $this->markTestSkipped('تصویرهای نمونهٔ ژنراتور (dataset/generated) موجود نیستند.');
        }

        $startedAt = microtime(true);
        $summary = app(CasePipeline::class)->run($case);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->assertSame(3, $summary['ocr_done'], 'متن هر سه مدرک باید خوانده شود');
        $this->assertGreaterThan(0, $summary['fields'], 'دست‌کم یک فیلد باید استخراج شود');

        $case->refresh();

        $this->assertNotSame('processing', $case->status);
        $this->assertNotNull($case->confidence_score);
        $this->assertNotNull($case->processing_ms);

        fwrite(STDERR, PHP_EOL.'[pipeline] پروندهٔ سه‌مدرکی با موتور واقعی: '.$elapsedMs.' میلی‌ثانیه'
            .' — فیلد استخراج‌شده: '.$summary['fields']
            .' — امتیاز: '.$case->confidence_score.PHP_EOL);

        // «چند ثانیه» یعنی چند ثانیه، نه چند دقیقه.
        $this->assertLessThan(120_000, $elapsedMs);
    }

    // ==================================================================
    // ابزار داخلی تست
    // ==================================================================

    /** پرونده‌ای با همهٔ مدارک لازم و فایل واقعی روی دیسک جعلی. */
    private function caseWithFiles(string $serviceKey = 'issue', ?User $user = null): PermitCase
    {
        $service = ServiceType::query()->where('key', $serviceKey)->firstOrFail();

        $case = PermitCase::factory()
            ->for($user ?? $this->expertUser(), 'user')
            ->create(['service_type_id' => $service->id]);

        foreach ($service->documentTypes as $type) {
            $this->attachDocument($case, (int) $type->id, $type->key, $this->imageBytes());
        }

        return $case->fresh(['documents']);
    }

    /** همان پرونده، ولی با تصویرهای واقعیِ ژنراتور. null اگر تصویرها نبودند. */
    private function caseWithRealImages(): ?PermitCase
    {
        $service = ServiceType::query()->where('key', 'issue')->firstOrFail();
        $root = dirname(base_path()).'/dataset/generated';

        $case = PermitCase::factory()->for($this->expertUser(), 'user')
            ->create(['service_type_id' => $service->id]);

        foreach ($service->documentTypes as $type) {
            $source = $root.'/'.$type->key.'/001.png';

            if (! is_file($source)) {
                return null;
            }

            $this->attachDocument($case, (int) $type->id, $type->key, (string) file_get_contents($source));
        }

        return $case->fresh(['documents']);
    }

    private function attachDocument(PermitCase $case, int $typeId, string $typeKey, string $bytes): void
    {
        $path = 'cases/'.$case->id.'/'.$typeKey.'.jpg';

        Storage::disk('documents')->put($path, $bytes);

        CaseDocument::factory()->prechecked()->create([
            'case_id' => $case->id,
            'document_type_id' => $typeId,
            'disk' => 'documents',
            'path' => $path,
            'original_name' => $typeKey.'.jpg',
            'mime' => 'image/jpeg',
        ]);
    }

    /** موتور برای هر نوع مدرک متن مخصوص همان مدرک را برمی‌گرداند. */
    private function engineReadsEachDocument(): void
    {
        $this->mock(HanaEngine::class, function (MockInterface $mock): void {
            $mock->shouldReceive('version')->andReturn(['engine_version' => 'hana-engine/9.9-test']);
            $mock->shouldReceive('ocrDocument')->andReturnUsing($this->engineAnswer());
        });
    }

    private function engineAnswer(): callable
    {
        return static function (string $path, ?string $type = null): array {
            $text = self::TEXTS[$type] ?? 'متن نمونه';

            return [
                'raw_text' => $text,
                'char_count' => mb_strlen($text),
                'line_count' => substr_count($text, "\n") + 1,
                'lang' => 'fas',
                'preprocess' => true,
                'duration_ms' => 2100,
                'extra' => $type === 'vehicle_card'
                    ? ['vin' => 'NAS675287M6771656', 'plate' => '۱۳الف۴۰۷۹۱']
                    : ['vin' => null, 'plate' => null],
            ];
        };
    }

    /** @return array<string, int> */
    private function rowCounts(PermitCase $case): array
    {
        return [
            'ocr_runs' => OcrRun::query()->count(),
            'extracted_fields' => ExtractedField::query()->where('case_id', $case->id)->count(),
            'validation_results' => ValidationResult::query()->where('case_id', $case->id)->count(),
            'score_components' => ScoreComponent::query()->where('case_id', $case->id)->count(),
        ];
    }

    private function imageBytes(): string
    {
        if (self::$imageBytes === null) {
            $path = $this->makeImageFile(extension: 'jpg');
            self::$imageBytes = (string) file_get_contents($path);
            @unlink($path);
        }

        return self::$imageBytes;
    }
}
