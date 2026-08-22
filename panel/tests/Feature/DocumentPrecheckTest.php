<?php

namespace Tests\Feature;

use App\Exceptions\EngineException;
use App\Models\CaseDocument;
use App\Models\PermitCase;
use App\Models\Setting;
use App\Models\ValidationResult;
use App\Services\Cases\DocumentPrecheck;
use App\Services\HanaEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Concerns\BuildsCases;
use Tests\TestCase;

/**
 * تسک ۶۳۰ — «اعتبارسنجی اولیه فایل».
 *
 * قاعدهٔ این فایل: موتور پایتون در همهٔ تست‌ها به‌جز یکی mock می‌شود.
 * دلیلش سرعت و پایداری نیست فقط — تست باید بگوید «سرویس با این عددها چه
 * تصمیمی می‌گیرد»، نه «OpenCV امروز چه عددی داد». تنها تست انتهایی موتور
 * واقعی را صدا می‌زند و اگر موتور در دسترس نبود خودش را رد می‌کند.
 */
class DocumentPrecheckTest extends TestCase
{
    use BuildsCases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeDisks();
        $this->seedReferenceData();

        // عرض قالب‌های مرجع یک روز کش می‌شود؛ بدون این، اولین تست مقدارش را
        // برای بقیه هم تثبیت می‌کند و تست بعدی موتورِ mock خودش را نمی‌بیند.
        Cache::flush();
    }

    // ------------------------------------------------------------------
    // سه ردِ اجباری تعریف done
    // ------------------------------------------------------------------

    public function test_a_pdf_renamed_to_png_is_rejected_by_real_mime_not_by_name(): void
    {
        // نام فایل و MIME اعلامی هر دو دروغ می‌گویند: تصویر است. محتوا PDF است.
        $document = $this->documentWithFile(
            $this->bytesOf($this->uploadedFakePdf()),
            'card.png',
            'image/png',
        );

        $this->engineMustNotRun();

        $this->assertFalse($this->precheck()->inspect($document));

        $document->refresh();

        $this->assertSame('failed', $document->precheck_status);
        $this->assertSame('application/pdf', $document->mime);
        $this->assertSame(['file.mime'], $this->issueCodes($document));
        $this->assertStringContainsString('PDF', $this->firstMessage($document));

        $this->assertDatabaseHas('validation_results', [
            'case_document_id' => $document->id,
            'scope' => 'file',
            'rule_key' => 'file.mime',
            'status' => 'failed',
        ]);
    }

    public function test_a_blurry_image_is_rejected_with_a_message_about_focus(): void
    {
        $document = $this->documentWithFile($this->imageBytes('blurry', extension: 'jpg'), 'card.jpg');

        $this->engineReturns(['width' => 960, 'height' => 540, 'blur_score' => 14.4, 'brightness' => 169.3]);

        $this->assertFalse($this->precheck()->inspect($document));

        $document->refresh();

        $this->assertSame('failed', $document->precheck_status);
        $this->assertSame(['file.blurry'], $this->issueCodes($document));
        $this->assertEqualsWithDelta(14.4, (float) $document->blur_score, 0.01);
        $this->assertStringContainsString('تار', $this->firstMessage($document));
        $this->assertStringContainsString('فوکوس', $document->precheck_issues[0]['hint_fa']);

        $this->assertDatabaseHas('validation_results', [
            'case_document_id' => $document->id,
            'scope' => 'file',
            'rule_key' => 'file.blurry',
            'status' => 'failed',
        ]);
    }

    public function test_a_ten_kilobyte_file_is_rejected_before_the_engine_is_touched(): void
    {
        $document = $this->documentWithFile($this->paddedTo($this->imageBytes('sharp'), 10 * 1024), 'card.png', 'image/png');

        $this->engineMustNotRun();

        $this->assertFalse($this->precheck()->inspect($document));

        $document->refresh();

        $this->assertSame(10 * 1024, $document->size_bytes);
        $this->assertSame('failed', $document->precheck_status);
        $this->assertContains('file.too_small', $this->issueCodes($document));
        $this->assertStringContainsString('حجم', $this->firstMessage($document));

        // رد سریع: چون فایل محلی رد شد، تاری اصلاً سنجیده نشده.
        $this->assertNull($document->blur_score);
    }

    public function test_the_three_rejections_do_not_share_one_generic_message(): void
    {
        $messages = [];

        $this->engineReturns(['width' => 960, 'height' => 540, 'blur_score' => 14.4, 'brightness' => 169.3]);

        $cases = [
            'pdf' => $this->documentWithFile($this->bytesOf($this->uploadedFakePdf()), 'card.png', 'image/png'),
            'tiny' => $this->documentWithFile($this->paddedTo($this->imageBytes('sharp'), 10 * 1024), 'small.png', 'image/png'),
            'blurry' => $this->documentWithFile($this->imageBytes('blurry', extension: 'jpg'), 'blurry.jpg'),
        ];

        foreach ($cases as $key => $document) {
            $this->assertFalse($this->precheck()->inspect($document), "{$key} باید رد شود");
            $messages[$key] = $this->firstMessage($document->refresh());
        }

        $this->assertCount(3, array_unique($messages), 'هر سه ایراد باید پیام متفاوت بگیرند: '.json_encode($messages, JSON_UNESCAPED_UNICODE));

        foreach ($messages as $key => $message) {
            $this->assertNotSame('', trim($message), "{$key} پیام خالی دارد");
            $this->assertStringNotContainsString('خطا رخ داد', $message);
        }
    }

    public function test_a_healthy_image_passes_and_every_measurement_is_stored(): void
    {
        $document = $this->documentWithFile($this->imageBytes('sharp', extension: 'jpg'), 'card.jpg');

        $this->engineReturns(['width' => 960, 'height' => 540, 'blur_score' => 5695.47, 'brightness' => 176.35]);

        $this->assertTrue($this->precheck()->inspect($document));

        $document->refresh();

        $this->assertSame('passed', $document->precheck_status);
        $this->assertSame([], $document->precheck_issues);
        $this->assertSame('image/jpeg', $document->mime);
        $this->assertSame(960, $document->width);
        $this->assertSame(540, $document->height);
        $this->assertEqualsWithDelta(5695.47, (float) $document->blur_score, 0.01);
        $this->assertEqualsWithDelta(176.35, (float) $document->brightness_score, 0.01);
        $this->assertGreaterThan(20 * 1024, (int) $document->size_bytes);
        $this->assertSame(64, strlen((string) $document->checksum));

        $this->assertSame(0, ValidationResult::query()->where('case_document_id', $document->id)->count());
    }

    // ------------------------------------------------------------------
    // روشنایی
    // ------------------------------------------------------------------

    public function test_a_dark_image_and_a_bright_image_get_their_own_advice(): void
    {
        $dark = $this->documentWithFile($this->imageBytes('dark', extension: 'jpg'), 'dark.jpg');
        $bright = $this->documentWithFile($this->imageBytes('bright', extension: 'jpg'), 'bright.jpg');

        $this->mock(HanaEngine::class, function (MockInterface $mock) {
            $mock->shouldReceive('imageQuality')->twice()->andReturn(
                ['width' => 960, 'height' => 540, 'blur_score' => 424.4, 'brightness' => 32.2],
                ['width' => 960, 'height' => 540, 'blur_score' => 164.5, 'brightness' => 240.9],
            );
        });

        $service = $this->precheck();

        $this->assertFalse($service->inspect($dark));
        $this->assertFalse($service->inspect($bright));

        $this->assertSame(['file.too_dark'], $this->issueCodes($dark->refresh()));
        $this->assertSame(['file.too_bright'], $this->issueCodes($bright->refresh()));

        $this->assertStringContainsString('تاریک', $this->firstMessage($dark));
        $this->assertStringContainsString('روشن', $this->firstMessage($bright));
        $this->assertNotSame($this->firstMessage($dark), $this->firstMessage($bright));
    }

    /**
     * رگرسیون: کارت ملیِ سالمِ خودِ ژنراتور نباید «بیش از حد روشن» رد شود.
     *
     * سنجهٔ روشناییِ موتور میانگین کانال V است و برای کاغذ سفید ذاتاً بالاست؛
     * اندازه‌گیری واقعی روی dataset/generated: کارت ملی ۲۴۰.۵ با کنتراست ۲۷.۴.
     * با شرط قدیمی هر کارت ملی سالمی رد می‌شد و هیچ پرونده‌ای از گام بارگذاری
     * جلوتر نمی‌رفت.
     */
    public function test_a_white_document_stays_accepted_when_its_contrast_is_healthy(): void
    {
        $document = $this->documentWithFile($this->imageBytes('bright', extension: 'jpg'), 'card.jpg');

        $this->engineReturns([
            'width' => 960, 'height' => 540,
            'blur_score' => 564.3, 'brightness' => 240.5, 'std' => 27.4,
        ]);

        $this->assertTrue(
            $this->precheck()->inspect($document),
            'مدرکِ روشن ولی پرکنتراست باید قبول شود؛ سفیدیِ کاغذ ایراد نیست.',
        );

        $this->assertSame([], $this->issueCodes($document->refresh()));
    }

    /** ولی تصویری که واقعاً سوخته — روشن **و** بی‌کنتراست — همچنان رد می‌شود. */
    public function test_a_burnt_out_image_is_still_rejected(): void
    {
        $document = $this->documentWithFile($this->imageBytes('bright', extension: 'jpg'), 'burnt.jpg');

        $this->engineReturns([
            'width' => 960, 'height' => 540,
            'blur_score' => 292.7, 'brightness' => 253.9, 'std' => 13.7,
        ]);

        $this->assertFalse($this->precheck()->inspect($document));
        $this->assertSame(['file.too_bright'], $this->issueCodes($document->refresh()));
        $this->assertStringContainsString('محو', $this->firstMessage($document));
    }

    /** آستانهٔ کنتراست هم مثل بقیه از تنظیمات می‌آید، نه هاردکد. */
    public function test_contrast_threshold_comes_from_settings(): void
    {
        $limits = Setting::get('precheck.limits');
        $limits['min_contrast'] = 40;
        Setting::put('precheck.limits', $limits);

        $document = $this->documentWithFile($this->imageBytes('bright', extension: 'jpg'), 'card.jpg');

        $this->engineReturns([
            'width' => 960, 'height' => 540,
            'blur_score' => 564.3, 'brightness' => 240.5, 'std' => 27.4,
        ]);

        $this->assertFalse($this->precheck()->inspect($document));
        $this->assertSame(['file.too_bright'], $this->issueCodes($document->refresh()));
    }

    // ------------------------------------------------------------------
    // ابعاد و تنظیمات
    // ------------------------------------------------------------------

    public function test_dimensions_come_from_settings_and_are_not_hardcoded(): void
    {
        $limits = Setting::get('precheck.limits');
        $limits['min_width'] = 4000;
        Setting::put('precheck.limits', $limits);

        $document = $this->documentWithFile($this->imageBytes('sharp', extension: 'jpg'), 'card.jpg');

        $this->engineMustNotRun();

        $this->assertFalse($this->precheck()->inspect($document));
        $this->assertSame(['file.too_narrow'], $this->issueCodes($document->refresh()));
        $this->assertStringContainsString('ابعاد', $this->firstMessage($document));
    }

    public function test_missing_settings_row_falls_back_to_safe_defaults_instead_of_crashing(): void
    {
        Setting::query()->where('key', 'precheck.limits')->delete();

        $document = $this->documentWithFile($this->imageBytes('sharp', extension: 'jpg'), 'card.jpg');

        $this->engineReturns(['width' => 960, 'height' => 540, 'blur_score' => 5695.47, 'brightness' => 176.35]);

        $this->assertTrue($this->precheck()->inspect($document));
        $this->assertSame('passed', $document->refresh()->precheck_status);
    }

    public function test_a_file_that_vanished_from_disk_is_reported_not_fatal(): void
    {
        $case = $this->emptyCase();

        $document = CaseDocument::factory()->create([
            'case_id' => $case->id,
            'document_type_id' => $this->documentTypeId('national_card'),
            'disk' => 'documents',
            'path' => 'cases/'.$case->id.'/gone.jpg',
        ]);

        $this->engineMustNotRun();

        $this->assertFalse($this->precheck()->inspect($document));
        $this->assertSame(['file.missing'], $this->issueCodes($document->refresh()));
    }

    // ------------------------------------------------------------------
    // خطای موتور و اجرای دوباره
    // ------------------------------------------------------------------

    public function test_an_engine_failure_becomes_a_warning_and_does_not_reject_the_upload(): void
    {
        $document = $this->documentWithFile($this->imageBytes('sharp', extension: 'jpg'), 'card.jpg');

        $this->mock(HanaEngine::class, function (MockInterface $mock) {
            $mock->shouldReceive('imageQuality')
                ->once()
                ->andThrow(EngineException::crashed('image_quality', 'ModuleNotFoundError: cv2'));
        });

        // چک‌های محلی انجام شده‌اند، پس مدرک قبول است؛ فقط تاری ناشناخته مانده.
        $this->assertTrue($this->precheck()->inspect($document));

        $document->refresh();

        $this->assertSame('passed', $document->precheck_status);
        $this->assertSame(['file.quality_unavailable'], $this->issueCodes($document));
        $this->assertSame('warning', $document->precheck_issues[0]['severity']);
        $this->assertSame(960, $document->width);
        $this->assertNull($document->blur_score);

        $this->assertDatabaseHas('validation_results', [
            'case_document_id' => $document->id,
            'scope' => 'file',
            'rule_key' => 'file.quality_unavailable',
            'status' => 'warning',
        ]);
    }

    public function test_running_inspect_twice_does_not_leave_duplicate_or_stale_rows(): void
    {
        $document = $this->documentWithFile($this->imageBytes('blurry', extension: 'jpg'), 'card.jpg');

        $this->mock(HanaEngine::class, function (MockInterface $mock) {
            $mock->shouldReceive('imageQuality')->andReturn(
                ['width' => 960, 'height' => 540, 'blur_score' => 14.4, 'brightness' => 169.3],
                ['width' => 960, 'height' => 540, 'blur_score' => 14.4, 'brightness' => 169.3],
                ['width' => 960, 'height' => 540, 'blur_score' => 5695.47, 'brightness' => 176.35],
            );
        });

        $service = $this->precheck();

        $service->inspect($document);
        $service->inspect($document);

        $this->assertSame(
            1,
            ValidationResult::query()->where('case_document_id', $document->id)->where('scope', 'file')->count(),
            'اجرای دوباره نباید ردیف تکراری بسازد',
        );

        // بار سوم فایل سالم است؛ ردیف کهنهٔ «تار» هم باید پاک شود.
        $this->assertTrue($service->inspect($document));
        $this->assertSame(0, ValidationResult::query()->where('case_document_id', $document->id)->count());
        $this->assertSame([], $document->refresh()->precheck_issues);
    }

    // ------------------------------------------------------------------
    // تنها تستی که واقعاً موتور را بالا می‌آورد
    // ------------------------------------------------------------------

    public function test_the_real_engine_scores_a_real_image(): void
    {
        $engine = app(HanaEngine::class);

        try {
            $engine->version();
        } catch (EngineException $exception) {
            $this->markTestSkipped('موتور پایتون در این محیط در دسترس نیست: '.$exception->getMessage());
        }

        $sharp = $this->documentWithFile($this->imageBytes('sharp', extension: 'jpg'), 'card.jpg');
        $blurry = $this->documentWithFile($this->imageBytes('blurry', extension: 'jpg'), 'blurry.jpg');

        $service = $this->precheck();

        $startedAt = microtime(true);
        $sharpPassed = $service->inspect($sharp);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->assertTrue($sharpPassed, 'تصویر سالم باید از موتور واقعی هم قبول شود');
        $this->assertGreaterThan(60, (float) $sharp->refresh()->blur_score);
        $this->assertSame(960, $sharp->width);
        $this->assertSame(540, $sharp->height);

        $this->assertFalse($service->inspect($blurry));
        $this->assertSame(['file.blurry'], $this->issueCodes($blurry->refresh()));

        // ثبت عدد برای گزارش: یک فراخوانی موتور چقدر طول می‌کشد.
        fwrite(STDERR, PHP_EOL."[precheck] یک فراخوانی واقعی image_quality: {$elapsedMs} میلی‌ثانیه".PHP_EOL);
        $this->assertLessThan(10_000, $elapsedMs);
    }

    // ------------------------------------------------------------------
    // ابزار داخلی تست
    // ------------------------------------------------------------------
    // راهنمای رزولوشن — تسک ۶۶۴
    // ------------------------------------------------------------------

    /**
     * تصویری که پیام‌رسان کوچکش کرده باید راهنما بگیرد، نه جریمه.
     *
     * پروندهٔ ۲۷۲ دقیقاً همین بود: تلگرام گواهینامه را به نصف اندازه رساند.
     * حالا که موتور در چند بزرگ‌نمایی می‌خواند، همان تصویر معمولاً درست
     * خوانده می‌شود — پس کم‌کردن امتیاز از آن جریمهٔ بی‌دلیل است.
     */
    public function test_an_undersized_image_is_flagged_but_costs_no_score(): void
    {
        $this->engineReturns(['width' => 700, 'height' => 394, 'blur_score' => 5695.47, 'brightness' => 176.35]);

        $document = $this->documentWithFile($this->imageBytes('sharp', 700, 394, 'jpg'), 'card.jpg');

        $this->assertTrue($this->precheck()->inspect($document), 'مدرک باید قبول بماند');

        $document->refresh();

        $this->assertSame('passed', $document->precheck_status);
        $this->assertContains('file.below_reference_width', $this->issueCodes($document));

        $issue = collect((array) $document->precheck_issues)
            ->firstWhere('code', 'file.below_reference_width');

        $this->assertSame('warning', $issue['severity'], 'ویو باید ⚠️ نشان دهد نه ⛔');
        $this->assertFalse($issue['scored']);
        $this->assertStringContainsString('۷۰۰', $issue['message_fa']);
        $this->assertStringContainsString('سند', $issue['hint_fa'], 'باید بگوید فایل را به‌صورت سند بفرستد');

        // و مهم‌ترین بخش: هیچ ردیف اعتبارسنجی نمی‌سازد، پس امتیاز دست‌نخورده می‌ماند
        $this->assertDatabaseMissing('validation_results', [
            'case_document_id' => $document->id,
            'rule_key' => 'file.below_reference_width',
        ]);
    }

    public function test_an_image_at_the_reference_width_gets_no_advice(): void
    {
        $this->engineReturns(['width' => 960, 'height' => 540, 'blur_score' => 5695.47, 'brightness' => 176.35]);

        $document = $this->documentWithFile($this->imageBytes('sharp', 960, 540, 'jpg'), 'card.jpg');

        $this->precheck()->inspect($document);

        $this->assertNotContains('file.below_reference_width', $this->issueCodes($document->refresh()));
    }

    public function test_the_resolution_ratio_comes_from_settings(): void
    {
        $this->engineReturns(['blur_score' => 400, 'brightness_score' => 200, 'contrast_score' => 40]);

        $limits = Setting::get('precheck.limits');
        $limits['min_reference_ratio'] = 0.4;
        Setting::put('precheck.limits', $limits);

        $document = $this->documentWithFile($this->imageBytes('sharp', 700, 394));

        $this->precheck()->inspect($document);

        $this->assertNotContains('file.below_reference_width', $this->issueCodes($document->refresh()));
    }

    /** موتوری که عرض قالب‌ها را نمی‌دهد نباید آپلود را زمین بزند. */
    public function test_a_silent_engine_only_costs_the_advice(): void
    {
        $this->engineReturns(
            ['width' => 700, 'height' => 394, 'blur_score' => 5695.47, 'brightness' => 176.35],
            referenceWidths: null,
        );

        $document = $this->documentWithFile($this->imageBytes('sharp', 700, 394, 'jpg'), 'card.jpg');

        $this->assertTrue($this->precheck()->inspect($document));
        $this->assertNotContains('file.below_reference_width', $this->issueCodes($document->refresh()));
    }

    // ------------------------------------------------------------------

    private function precheck(): DocumentPrecheck
    {
        return app(DocumentPrecheck::class);
    }

    private function emptyCase(): PermitCase
    {
        return PermitCase::factory()->for($this->expertUser(), 'user')->create();
    }

    /** یک مدرک با فایل واقعی روی دیسک جعلی «documents». */
    private function documentWithFile(string $contents, string $name = 'card.jpg', string $declaredMime = 'image/jpeg'): CaseDocument
    {
        $case = $this->emptyCase();
        $path = 'cases/'.$case->id.'/'.uniqid('doc_').'-'.$name;

        Storage::disk('documents')->put($path, $contents);

        return CaseDocument::factory()->create([
            'case_id' => $case->id,
            'document_type_id' => $this->documentTypeId('national_card'),
            'disk' => 'documents',
            'path' => $path,
            'original_name' => $name,
            // ادعای مرورگر — سرویس نباید به آن اعتماد کند.
            'mime' => $declaredMime,
        ]);
    }

    /** @param 'sharp'|'blurry'|'dark'|'bright' $look */
    private function imageBytes(string $look, int $width = 960, int $height = 540, string $extension = 'png'): string
    {
        $path = $this->makeImageFile($width, $height, $look, $extension);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function bytesOf(UploadedFile $file): string
    {
        return (string) file_get_contents($file->getRealPath());
    }

    /** PNG کوچک را تا حجم دقیق مشخص پر می‌کند (بایت‌های بعد از IEND نادیده گرفته می‌شوند). */
    private function paddedTo(string $contents, int $bytes): string
    {
        return strlen($contents) >= $bytes
            ? substr($contents, 0, $bytes)
            : $contents.str_repeat("\0", $bytes - strlen($contents));
    }

    /** @param array<string, mixed> $quality */
    /**
     * @param  array<string, mixed>  $quality
     * @param  array<string, int>|null  $referenceWidths  عرض قالب هر نوع مدرک؛ null یعنی موتور جواب ندهد
     */
    private function engineReturns(array $quality, ?array $referenceWidths = ['national_card' => 960]): void
    {
        $this->mock(HanaEngine::class, function (MockInterface $mock) use ($quality, $referenceWidths) {
            $mock->shouldReceive('imageQuality')->andReturn($quality);

            if ($referenceWidths === null) {
                $mock->shouldReceive('documentLayouts')
                    ->andThrow(EngineException::crashed('document_layouts', 'ModuleNotFoundError: cv2'));

                return;
            }

            $layouts = [];

            foreach ($referenceWidths as $key => $width) {
                $layouts[$key] = ['template_width' => $width];
            }

            $mock->shouldReceive('documentLayouts')->andReturn(['layouts' => $layouts]);
        });
    }

    /** موتور نباید اصلاً صدا زده شود — «رد سریع» یعنی همین. */
    private function engineMustNotRun(): void
    {
        $this->mock(HanaEngine::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('imageQuality');
            $mock->shouldNotReceive('documentLayouts');
        });
    }

    /** @return list<string> */
    private function issueCodes(CaseDocument $document): array
    {
        return array_column((array) $document->precheck_issues, 'code');
    }

    private function firstMessage(CaseDocument $document): string
    {
        return (string) (($document->precheck_issues[0] ?? [])['message_fa'] ?? '');
    }
}
