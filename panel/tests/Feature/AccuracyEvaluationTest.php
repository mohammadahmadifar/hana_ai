<?php

namespace Tests\Feature;

use App\Jobs\RunAccuracyEvaluation;
use App\Models\DocumentType;
use App\Models\EvaluationRun;
use App\Services\HanaEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsCases;
use Tests\TestCase;

/**
 * تسک ۷۲۶ — ارزیابی دسته‌ای دقت.
 *
 * موتور این‌جا جعلی است و عمداً: هدف تست، «حسابداری» ارزیابی است نه کیفیت
 * OCR (آن را FieldExtractorTest می‌سنجد). چیزی که این‌جا نباید بشکند:
 *
 *   ۱) فیلدی که ژنراتور روی قالب چاپ نکرده اصلاً وارد مخرج نمی‌شود.
 *   ۲) تصویری که ساخته یا خوانده نشد در count_failed می‌نشیند و هیچ فیلدی از
 *      آن شمرده نمی‌شود — وگرنه دسته‌ای که نیمی‌اش نپخته «۱۰۰٪» می‌شود.
 *   ۳) متن خالی یعنی همهٔ فیلدهای آن تصویر غلط‌اند، نه اینکه تصویر ناپدید شود.
 *   ۴) دسته‌ای که تکه‌تکه پیش می‌رود شمارنده‌هایش جمع می‌شوند، نه بازنویسی.
 *
 * متن OCR این تست دست‌ساز است ولی واقعاً از FieldExtractor رد می‌شود؛ همان
 * شش مقدار برمی‌گردد که نوشته شده‌اند.
 */
class AccuracyEvaluationTest extends TestCase
{
    use BuildsCases;
    use RefreshDatabase;

    /** متنی که استخراج‌گر هر شش فیلد کارت ملی را از آن درست درمی‌آورد. */
    private const CLEAN_TEXT = "شماره ملی ۰۰۱۲۳۴۵۶۷۸\nنام سمیرا\nنام خانوادگی کاظمی\n"
        ."تاریخ تولد ۱۳۷۰/۰۴/۲۱\nنام پدر محمود\nتاریخ انقضای کارت ۱۴۰۸/۰۳/۱۲";

    /** همان مقادیر، به‌عنوان «چیزی که روی تصویر چاپ شده». */
    private const PRINTED = [
        'national_id' => '۰۰۱۲۳۴۵۶۷۸',
        'first_name' => 'سمیرا',
        'last_name' => 'کاظمی',
        'birth_date' => '۱۳۷۰/۰۴/۲۱',
        'father_name' => 'محمود',
        'national_card_expire' => '۱۴۰۸/۰۳/۱۲',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        $this->fakeDisks();
    }

    // ------------------------------------------------------------------
    // شمارش دقت
    // ------------------------------------------------------------------

    public function test_a_perfect_read_is_one_hundred_percent(): void
    {
        $run = $this->makeRun(3);

        $this->fakeEngine(fn (array $item): array => $this->okItem($item));

        $this->runJob($run);

        $run->refresh();

        $this->assertSame('done', $run->status);
        $this->assertSame(3, (int) $run->count_done);
        $this->assertSame(0, (int) $run->count_failed);
        $this->assertSame(18, (int) $run->fields_total);   // ۳ تصویر × ۶ فیلد
        $this->assertSame(18, (int) $run->fields_correct);
        $this->assertSame(100.0, $run->accuracy());
        $this->assertSame([], $run->misses ?? []);
    }

    public function test_a_field_the_generator_never_printed_is_not_measured(): void
    {
        $run = $this->makeRun(2);

        // «نام پدر» روی این قالب چاپ نشده — نه درست است نه غلط.
        $this->fakeEngine(function (array $item): array {
            $result = $this->okItem($item);
            unset($result['fields']['father_name']);

            return $result;
        });

        $this->runJob($run);

        $run->refresh();

        $this->assertSame(10, (int) $run->fields_total);   // ۲ تصویر × ۵ فیلد
        $this->assertSame(10, (int) $run->fields_correct);
        $this->assertSame(100.0, $run->accuracy());
        $this->assertArrayNotHasKey('father_name', $run->breakdown['national_card']['fields']);
    }

    public function test_an_unreadable_image_counts_every_printed_field_as_wrong(): void
    {
        $run = $this->makeRun(2);

        $this->fakeEngine(function (array $item): array {
            $result = $this->okItem($item);
            $result['variants'] = [['raw_text' => '', 'extra' => []]];

            return $result;
        });

        $this->runJob($run);

        $run->refresh();

        // تصویر خوانده شد (ساخته شد و OCR اجرا شد) ولی هیچ فیلدی درنیامد
        $this->assertSame(2, (int) $run->count_done);
        $this->assertSame(12, (int) $run->fields_total);
        $this->assertSame(0, (int) $run->fields_correct);
        $this->assertSame(0.0, $run->accuracy());
        $this->assertCount(12, $run->misses);
    }

    public function test_a_failed_image_contributes_no_fields_at_all(): void
    {
        $run = $this->makeRun(4);

        $this->fakeEngine(function (array $item): array {
            if ((int) $item['index'] % 2 === 0) {
                return [
                    'index' => $item['index'],
                    'document_type' => $item['document_type'],
                    'ok' => false,
                    'fields' => [],
                    'variants' => [],
                    'image' => null,
                    'error' => 'قالب این نوع مدرک روی سرور موجود نیست.',
                ];
            }

            return $this->okItem($item);
        });

        $this->runJob($run);

        $run->refresh();

        $this->assertSame(2, (int) $run->count_done);
        $this->assertSame(2, (int) $run->count_failed);
        // مخرج فقط از دو تصویر سالم آمده؛ دو تصویر ناموفق نه بالا می‌برند نه پایین
        $this->assertSame(12, (int) $run->fields_total);
        $this->assertSame(100.0, $run->accuracy());
        $this->assertStringContainsString('قالب این نوع مدرک', (string) $run->error);
    }

    public function test_a_run_that_continues_in_a_second_job_adds_up_instead_of_restarting(): void
    {
        $run = $this->makeRun(6);

        // نیمهٔ اول از قبل شمرده شده — دقیقاً همان چیزی که یک Job قبلی گذاشته
        $run->forceFill([
            'count_done' => 3,
            'fields_total' => 18,
            'fields_correct' => 18,
            'confidence_sum' => 1000,
            'status' => 'running',
        ])->save();

        $this->fakeEngine(fn (array $item): array => $this->okItem($item));

        $this->runJob($run);

        $run->refresh();

        $this->assertSame('done', $run->status);
        $this->assertSame(6, (int) $run->count_done);
        $this->assertSame(36, (int) $run->fields_total);
        $this->assertSame(36, (int) $run->fields_correct);
    }

    public function test_the_engine_is_asked_only_for_what_is_left(): void
    {
        $run = $this->makeRun(5);
        $run->forceFill(['count_done' => 4, 'status' => 'running'])->save();

        $sizes = [];

        $this->fakeEngine(fn (array $item): array => $this->okItem($item), $sizes);

        $this->runJob($run);

        $this->assertSame([1], $sizes);
    }

    public function test_an_image_with_nothing_printed_on_it_counts_as_failed_not_as_a_shrunken_denominator(): void
    {
        $run = $this->makeRun(2);

        // موتور می‌گوید «سالم» ولی هیچ متنی روی تصویر چاپ نشده: یک خرابیِ
        // سیستماتیکِ رندر نباید به‌شکل «۱۰۰٪» با مخرجِ آب‌رفته دربیاید.
        $this->fakeEngine(function (array $item): array {
            $result = $this->okItem($item);
            $result['fields'] = [];

            return $result;
        });

        $this->runJob($run);

        $run->refresh();

        $this->assertSame(0, (int) $run->count_done);
        $this->assertSame(2, (int) $run->count_failed);
        $this->assertSame(0, (int) $run->fields_total);
        $this->assertNull($run->accuracy());
        $this->assertStringContainsString('هیچ فیلدی روی آن چاپ نشده', (string) $run->error);
    }

    public function test_a_chunk_that_returns_nothing_stops_the_run_instead_of_looping_forever(): void
    {
        $run = $this->makeRun(3);

        // موتوری که نتیجه‌ای برنمی‌گرداند: بدون نگهبان، همان تکه تا ابد تکرار
        // می‌شد و کارگر صف قفل می‌ماند.
        $this->instance(HanaEngine::class, new class extends HanaEngine
        {
            public function generatePerson(int $count = 1): array
            {
                return ['people' => array_fill(0, $count, ['first_name' => 'سمیرا'])];
            }

            public function evaluateBatch(array $items, string $outDir, int $workers = 8): array
            {
                return ['items' => []];
            }
        });

        $this->runJob($run);

        $run->refresh();

        $this->assertSame('failed', $run->status);
        $this->assertSame(0, $run->processed());
        $this->assertStringContainsString('هیچ نتیجه‌ای برنگرداند', (string) $run->error);
    }

    // ------------------------------------------------------------------
    // دسترسی و فرم
    // ------------------------------------------------------------------

    public function test_only_the_admin_can_open_the_page(): void
    {
        $this->actingAs($this->adminUser())->get(route('evaluation.create'))->assertOk();

        foreach (['expert', 'data', 'applicant'] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get(route('evaluation.create'))
                ->assertForbidden();
        }
    }

    public function test_the_form_refuses_a_sample_that_is_too_small_or_too_large(): void
    {
        Queue::fake();

        $types = DocumentType::where('is_generatable', true)->pluck('id')->all();

        foreach ([99, 1001] as $count) {
            $this->actingAs($this->adminUser())
                ->from(route('evaluation.create'))
                ->post(route('evaluation.store'), [
                    'count' => $count,
                    'document_type_ids' => $types,
                    'augment_mode' => 'clean',
                ])
                ->assertRedirect(route('evaluation.create'))
                ->assertSessionHasErrors('count');
        }

        $this->assertSame(0, EvaluationRun::count());
        Queue::assertNothingPushed();
    }

    public function test_submitting_the_form_creates_a_run_and_queues_the_work(): void
    {
        Queue::fake();

        $types = DocumentType::where('is_generatable', true)->pluck('id')->all();

        $this->actingAs($this->adminUser())
            ->post(route('evaluation.store'), [
                'count' => 100,
                'document_type_ids' => $types,
                'augment_mode' => 'random',
            ])
            ->assertRedirect(route('evaluation.show', EvaluationRun::latest('id')->first()));

        $run = EvaluationRun::latest('id')->first();

        $this->assertSame(100, (int) $run->count_requested);
        $this->assertSame('random', $run->augment_mode);
        $this->assertSame('queued', $run->status);

        Queue::assertPushed(RunAccuracyEvaluation::class, fn (RunAccuracyEvaluation $job): bool => $job->runId === $run->id);
    }

    public function test_a_second_run_is_refused_while_one_is_still_active(): void
    {
        Queue::fake();

        $active = $this->makeRun(100);
        $active->forceFill(['status' => 'running'])->save();

        $this->actingAs($this->adminUser())
            ->post(route('evaluation.store'), [
                'count' => 100,
                'document_type_ids' => DocumentType::where('is_generatable', true)->pluck('id')->all(),
                'augment_mode' => 'clean',
            ])
            ->assertRedirect(route('evaluation.show', $active))
            ->assertSessionHas('info');

        // هیچ اجرای دومی ساخته نشد و صف هم شلوغ نشد
        $this->assertSame(1, EvaluationRun::count());
        Queue::assertNothingPushed();
    }

    public function test_the_result_page_and_its_live_status_show_the_numbers(): void
    {
        $run = $this->makeRun(3);

        $this->fakeEngine(fn (array $item): array => $this->okItem($item));
        $this->runJob($run);

        $this->actingAs($this->adminUser())
            ->get(route('evaluation.show', $run))
            ->assertOk()
            // جداکنندهٔ اعشار فارسی «٫» است، نه نقطهٔ لاتین (قانون ۱۲۸)
            ->assertSee('۱۰۰٫۰٪', false)
            ->assertSee('کد ملی', false);

        $this->actingAs($this->adminUser())
            ->getJson(route('evaluation.status', $run))
            ->assertOk()
            ->assertJson([
                'status' => 'done',
                'finished' => true,
                'accuracy' => 100,
                'fields_total' => 18,
                'fields_correct' => 18,
            ]);
    }

    public function test_the_kept_sample_images_render_through_the_protected_media_route(): void
    {
        $run = $this->makeRun(1);

        $run->forceFill([
            'status' => 'done',
            'count_done' => 1,
            'fields_total' => 1,
            'fields_correct' => 1,
            'previews' => [[
                'path' => 'evaluations/'.$run->id.'/run_sample.png',
                'document' => 'کارت ملی',
                'fields' => [
                    ['label' => 'کد ملی', 'expected' => '۰۰۱۲۳۴۵۶۷۸', 'got' => '۰۰۱۲۳۴۵۶۷۸', 'ok' => true],
                    ['label' => 'نام', 'expected' => 'سمیرا', 'got' => 'سميرا', 'ok' => false],
                ],
            ]],
        ])->save();

        $response = $this->actingAs($this->adminUser())->get(route('evaluation.show', $run));

        $response->assertOk();

        // تصویر فقط از مسیر محافظت‌شده سرو می‌شود، هرگز از public
        $response->assertSee(
            route('media', ['disk' => 'dataset', 'path' => 'evaluations/'.$run->id.'/run_sample.png', 'w' => 320]),
            false,
        );
        $response->assertSee('سميرا', false);
        $response->assertDontSee('/storage/evaluations', false);
    }

    // ------------------------------------------------------------------
    // کمکی‌ها
    // ------------------------------------------------------------------

    private function makeRun(int $count): EvaluationRun
    {
        $run = new EvaluationRun;
        $run->user_id = $this->adminUser()->id;
        $run->document_type_ids = [DocumentType::where('key', 'national_card')->value('id')];
        $run->count_requested = $count;
        $run->augment_mode = 'clean';
        $run->status = 'queued';
        $run->save();

        return $run;
    }

    private function runJob(EvaluationRun $run): void
    {
        $this->app->call([new RunAccuracyEvaluation($run->id), 'handle']);
    }

    /** نتیجهٔ «همه‌چیز درست خوانده شد» برای یک آیتم. */
    private function okItem(array $item): array
    {
        return [
            'index' => $item['index'],
            'document_type' => $item['document_type'],
            'ok' => true,
            'fields' => self::PRINTED,
            'variants' => [['raw_text' => self::CLEAN_TEXT, 'extra' => []]],
            'image' => null,
            'error' => null,
        ];
    }

    /**
     * موتور جعلی: به‌جای ساخت تصویر و OCR، همان چیزی را برمی‌گرداند که
     * تابع داده‌شده می‌گوید. `$sizes` اندازهٔ هر تکهٔ درخواستی را ثبت می‌کند.
     */
    private function fakeEngine(callable $shape, array &$sizes = []): void
    {
        $this->instance(HanaEngine::class, new class($shape, $sizes) extends HanaEngine
        {
            /** @param  array<int, int>  $sizes */
            public function __construct(private $shape, private array &$sizes) {}

            public function generatePerson(int $count = 1): array
            {
                $this->sizes[] = $count;

                return ['people' => array_fill(0, $count, ['first_name' => 'سمیرا'])];
            }

            public function evaluateBatch(array $items, string $outDir, int $workers = 8): array
            {
                return ['items' => array_map($this->shape, $items), 'workers' => $workers];
            }
        });
    }
}
