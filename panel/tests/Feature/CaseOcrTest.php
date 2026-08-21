<?php

namespace Tests\Feature;

use App\Exceptions\EngineException;
use App\Models\CaseDocument;
use App\Models\OcrRun;
use App\Models\PermitCase;
use App\Services\Cases\DocumentOcr;
use App\Services\HanaEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Concerns\BuildsCases;
use Tests\TestCase;

/**
 * تسک ۶۳۱ — مرحلهٔ «متن‌خوانی مدرک» (DocumentOcr).
 *
 * قاعدهٔ این فایل مثل تست‌های ۶۳۰: موتور پایتون در همهٔ تست‌ها به‌جز آخری mock
 * می‌شود. چیزی که این‌جا سنجیده می‌شود «سرویس با این پاسخِ موتور چه چیزی
 * ذخیره می‌کند» است، نه دقت امروزِ Tesseract.
 *
 * تست پایانی موتور واقعی را روی یک تصویر مصنوعیِ ژنراتور (dataset/generated)
 * اجرا می‌کند و اگر موتور یا تصویرها در دسترس نبودند خودش را رد می‌کند.
 */
class CaseOcrTest extends TestCase
{
    use BuildsCases;
    use RefreshDatabase;

    /** نمونهٔ پاسخ موتور برای یک کارت ملی. */
    private const ENGINE_OK = [
        'document_type' => 'national_card',
        'preprocess' => true,
        'lang' => 'fas',
        'config' => '--oem 3 --psm 6',
        'raw_text' => "شماره ملی ۸۵۳۹۴۲۵۷۳۴\nنام ایلیا\nهاشمی",
        'char_count' => 36,
        'line_count' => 3,
        'extra' => ['vin' => null, 'plate' => null],
        'duration_ms' => 2400,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeDisks();
        $this->seedReferenceData();
    }

    // ------------------------------------------------------------------
    // تعریف done: متن خام + زمان اجرا + نسخهٔ موتور ذخیره می‌شود
    // ------------------------------------------------------------------

    public function test_ocr_stores_raw_text_duration_and_engine_version(): void
    {
        $document = $this->documentWithFile('national_card');

        $this->engineReturns(self::ENGINE_OK);

        $run = $this->ocr()->run($document);

        $this->assertSame('done', $run->status);
        $this->assertStringContainsString('ایلیا', (string) $run->raw_text);
        $this->assertSame('hana-engine/9.9-test', $run->engine_version);
        $this->assertNotNull($run->duration_ms);
        $this->assertNull($run->error);

        // params باید بگوید موتور با چه تنظیماتی خوانده — بدون این، بازتولید
        // یک نتیجهٔ عجیب ماه‌ها بعد ممکن نیست.
        $this->assertSame('national_card', $run->params['document_type']);
        $this->assertSame('fas', $run->params['lang']);
        $this->assertSame(2400, $run->params['engine_duration_ms']);

        $this->assertSame('done', $document->refresh()->ocr_status);
        $this->assertSame(CaseDocument::class, $run->subject_type);
        $this->assertSame($document->id, (int) $run->subject_id);
    }

    // ------------------------------------------------------------------
    // مسیر ویژهٔ کارت خودرو
    // ------------------------------------------------------------------

    public function test_vehicle_card_vin_and_plate_land_in_extra(): void
    {
        $document = $this->documentWithFile('vehicle_card');

        // نوع مدرک باید عیناً به موتور برود، وگرنه مسیر ویژهٔ VIN اجرا نمی‌شود.
        $this->mock(HanaEngine::class, function (MockInterface $mock): void {
            $mock->shouldReceive('version')->andReturn(['engine_version' => 'hana-engine/9.9-test']);
            $mock->shouldReceive('ocrDocument')
                ->once()
                ->withArgs(fn (string $path, ?string $type): bool => $type === 'vehicle_card')
                ->andReturn([
                    'raw_text' => "VIN : NAS675287M6771656\nPLATE : ۱۳ الف ۴۰۷ ۹۱",
                    'extra' => ['vin' => ' NAS675287M6771656 ', 'plate' => '۱۳الف۴۰۷۹۱'],
                    'duration_ms' => 3100,
                ]);
        });

        $run = $this->ocr()->run($document);

        $this->assertSame('done', $run->status);
        $this->assertSame('NAS675287M6771656', $run->extra['vin'], 'فاصلهٔ اضافه باید هرس شود');
        $this->assertSame('۱۳الف۴۰۷۹۱', $run->extra['plate']);
    }

    public function test_empty_vehicle_extra_is_stored_as_null_not_as_empty_string(): void
    {
        $document = $this->documentWithFile('vehicle_card');

        $this->engineReturns(['raw_text' => 'متنی هست', 'extra' => ['vin' => '   ', 'plate' => '']]);

        $run = $this->ocr()->run($document);

        // FieldExtractor روی null درست تصمیم می‌گیرد؛ رشتهٔ خالی «مقدار دارد»
        // به نظر می‌رسد و اطمینان الکی می‌سازد.
        $this->assertNull($run->extra['vin']);
        $this->assertNull($run->extra['plate']);
    }

    // ------------------------------------------------------------------
    // مدرکی که در اعتبارسنجی اولیه رد شده، پول موتور را خرج نمی‌کند
    // ------------------------------------------------------------------

    public function test_a_document_rejected_in_precheck_never_reaches_the_engine(): void
    {
        $document = $this->documentWithFile('national_card');
        $document->forceFill(['precheck_status' => 'failed'])->save();

        $this->mock(HanaEngine::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('ocrDocument');
            // حتی نسخهٔ موتور هم پرسیده نمی‌شود؛ آن هم یک پروسهٔ پایتون است.
            $mock->shouldNotReceive('version');
        });

        $run = $this->ocr()->run($document);

        $this->assertSame('failed', $run->status);
        $this->assertNull($run->raw_text);
        $this->assertNull($run->engine_version);
        $this->assertStringContainsString('اعتبارسنجی اولیه', (string) $run->error);
        $this->assertSame('failed', $document->refresh()->ocr_status);
    }

    public function test_a_missing_file_is_reported_in_persian_without_calling_the_engine(): void
    {
        $case = PermitCase::factory()->for($this->expertUser(), 'user')->create();

        $document = CaseDocument::factory()->create([
            'case_id' => $case->id,
            'document_type_id' => $this->documentTypeId('national_card'),
            'disk' => 'documents',
            'path' => 'cases/'.$case->id.'/gone.jpg',
        ]);

        $this->mock(HanaEngine::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('ocrDocument');
        });

        $run = $this->ocr()->run($document);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('پیدا نشد', (string) $run->error);
        $this->assertSame('failed', $document->refresh()->ocr_status);
    }

    // ------------------------------------------------------------------
    // خطای موتور نباید چیزی را بشکند
    // ------------------------------------------------------------------

    public function test_engine_failure_becomes_a_failed_run_with_a_persian_message(): void
    {
        $document = $this->documentWithFile('driving_license');

        $this->mock(HanaEngine::class, function (MockInterface $mock): void {
            $mock->shouldReceive('version')->andReturn(['engine_version' => 'hana-engine/9.9-test']);
            $mock->shouldReceive('ocrDocument')->andThrow(
                EngineException::fromEngine('ocr_document', 'فایل تصویر قابل خواندن نیست یا فرمت آن پشتیبانی نمی‌شود.', 'path=/x'),
            );
        });

        $run = $this->ocr()->run($document);

        $this->assertSame('failed', $run->status);
        $this->assertSame('فایل تصویر قابل خواندن نیست یا فرمت آن پشتیبانی نمی‌شود.', $run->error);
        $this->assertSame('failed', $document->refresh()->ocr_status);
    }

    public function test_an_unexpected_engine_crash_is_swallowed_too(): void
    {
        $document = $this->documentWithFile('national_card');

        $this->mock(HanaEngine::class, function (MockInterface $mock): void {
            $mock->shouldReceive('version')->andReturn(['engine_version' => 'hana-engine/9.9-test']);
            $mock->shouldReceive('ocrDocument')->andThrow(new \RuntimeException('boom'));
        });

        $run = $this->ocr()->run($document);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('خطای غیرمنتظره', (string) $run->error);
    }

    public function test_empty_text_is_done_but_says_why_it_is_empty(): void
    {
        $document = $this->documentWithFile('national_card');

        $this->engineReturns(['raw_text' => "\n  \n"]);

        $run = $this->ocr()->run($document);

        // «متن نخواند» شکستِ سامانه نیست؛ مدرک پردازش شد و نتیجه‌اش خالی بود.
        $this->assertSame('done', $run->status);
        $this->assertStringContainsString('هیچ متنی', (string) $run->error);
    }

    // ------------------------------------------------------------------
    // idempotent بودن و صرفه‌جویی در تماس با موتور
    // ------------------------------------------------------------------

    public function test_running_twice_keeps_exactly_one_run_row_per_document(): void
    {
        $document = $this->documentWithFile('national_card');

        $this->engineReturns(self::ENGINE_OK);

        $first = $this->ocr()->run($document);
        $second = $this->ocr()->run($document);

        $this->assertSame($first->id, $second->id, 'اجرای دوباره نباید ردیف تازه بسازد');
        $this->assertSame(1, OcrRun::query()->where('subject_id', $document->id)->count());
    }

    public function test_a_failed_rerun_does_not_leave_the_old_text_behind(): void
    {
        $document = $this->documentWithFile('national_card');

        $this->engineReturns(self::ENGINE_OK);
        $this->assertNotNull($this->ocr()->run($document)->raw_text);

        $this->mock(HanaEngine::class, function (MockInterface $mock): void {
            $mock->shouldReceive('version')->andReturn(['engine_version' => 'hana-engine/9.9-test']);
            $mock->shouldReceive('ocrDocument')->andThrow(
                EngineException::fromEngine('ocr_document', 'موتور در دسترس نیست.'),
            );
        });

        $run = $this->ocr()->run($document);

        $this->assertSame('failed', $run->status);
        $this->assertNull($run->raw_text, 'متن کهنه نباید کنار وضعیت «ناموفق» بماند');
    }

    public function test_engine_version_is_asked_once_for_a_whole_batch_of_documents(): void
    {
        $documents = [
            $this->documentWithFile('national_card'),
            $this->documentWithFile('driving_license'),
            $this->documentWithFile('vehicle_card'),
        ];

        $this->mock(HanaEngine::class, function (MockInterface $mock): void {
            // نکتهٔ اصلی: یک بار، نه سه بار. هر تماس یک پروسهٔ پایتون است.
            $mock->shouldReceive('version')->once()->andReturn(['engine_version' => 'hana-engine/9.9-test']);
            $mock->shouldReceive('ocrDocument')->times(3)->andReturn(self::ENGINE_OK);
        });

        $service = $this->ocr();

        foreach ($documents as $document) {
            $this->assertSame('done', $service->run($document)->status);
        }
    }

    public function test_the_document_is_marked_running_while_the_engine_works(): void
    {
        $document = $this->documentWithFile('national_card');
        $seen = null;

        $this->mock(HanaEngine::class, function (MockInterface $mock) use ($document, &$seen): void {
            $mock->shouldReceive('version')->andReturn(['engine_version' => 'hana-engine/9.9-test']);
            $mock->shouldReceive('ocrDocument')->andReturnUsing(function () use ($document, &$seen) {
                // وسط کار، صفحهٔ وضعیت باید «در حال متن‌خوانی» ببیند نه «در صف».
                $seen = CaseDocument::query()->find($document->id)?->ocr_status;

                return self::ENGINE_OK;
            });
        });

        $this->ocr()->run($document);

        $this->assertSame('running', $seen);
        $this->assertSame('done', $document->refresh()->ocr_status);
    }

    // ------------------------------------------------------------------
    // تنها تستی که موتور واقعی را بالا می‌آورد
    // ------------------------------------------------------------------

    public function test_the_real_engine_reads_a_real_generated_card(): void
    {
        $engine = app(HanaEngine::class);

        try {
            $version = $engine->version();
        } catch (EngineException $exception) {
            $this->markTestSkipped('موتور پایتون در این محیط در دسترس نیست: '.$exception->getMessage());
        }

        if (($version['tesseract_version'] ?? null) === null) {
            $this->markTestSkipped('tesseract روی این ماشین نصب نیست.');
        }

        // تصویر مصنوعیِ ژنراتور — هیچ مدرک هویتی واقعی در کار نیست.
        $source = dirname(base_path()).'/dataset/generated/vehicle_card/001.png';

        if (! is_file($source)) {
            $this->markTestSkipped('تصویر نمونهٔ ژنراتور موجود نیست: '.$source);
        }

        // ⚠️ موتور فقط مسیرهای زیر /home/coder/hana_ai را می‌خواند؛ دیسک جعلی
        // تست داخل panel/storage است، پس همان‌جا کپی می‌شود نه در /tmp.
        $document = $this->documentWithFile('vehicle_card', (string) file_get_contents($source));

        $startedAt = microtime(true);
        $run = app(DocumentOcr::class)->run($document);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->assertSame('done', $run->status, 'موتور واقعی باید این تصویر را بخواند. خطا: '.$run->error);
        $this->assertNotSame('', trim((string) $run->raw_text));
        $this->assertNotNull($run->engine_version);
        $this->assertGreaterThan(0, (int) $run->duration_ms);

        // کلیدهای قراردادی extra همیشه هستند، حتی اگر مقدارشان null باشد.
        $this->assertArrayHasKey('vin', (array) $run->extra);
        $this->assertArrayHasKey('plate', (array) $run->extra);

        fwrite(STDERR, PHP_EOL.'[ocr] یک OCR واقعی کارت خودرو: '.$elapsedMs.' میلی‌ثانیه'
            .' — کاراکتر: '.mb_strlen((string) $run->raw_text)
            .' — VIN: '.var_export($run->extra['vin'] ?? null, true).PHP_EOL);
    }

    // ------------------------------------------------------------------
    // ابزار داخلی تست
    // ------------------------------------------------------------------

    private function ocr(): DocumentOcr
    {
        return app(DocumentOcr::class);
    }

    /** مدرکی با فایل واقعی روی دیسک جعلی «documents». */
    private function documentWithFile(string $typeKey, ?string $contents = null): CaseDocument
    {
        $case = PermitCase::factory()->for($this->expertUser(), 'user')->create();

        $path = 'cases/'.$case->id.'/'.uniqid('doc_').'-'.$typeKey.'.jpg';

        Storage::disk('documents')->put($path, $contents ?? $this->imageBytes());

        return CaseDocument::factory()->create([
            'case_id' => $case->id,
            'document_type_id' => $this->documentTypeId($typeKey),
            'disk' => 'documents',
            'path' => $path,
            'original_name' => $typeKey.'.jpg',
            'mime' => 'image/jpeg',
            'precheck_status' => 'passed',
        ]);
    }

    private function imageBytes(): string
    {
        $path = $this->makeImageFile(extension: 'jpg');
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** @param array<string, mixed> $result */
    private function engineReturns(array $result): void
    {
        $this->mock(HanaEngine::class, function (MockInterface $mock) use ($result): void {
            $mock->shouldReceive('version')->andReturn(['engine_version' => 'hana-engine/9.9-test']);
            $mock->shouldReceive('ocrDocument')->andReturn($result);
        });
    }
}
