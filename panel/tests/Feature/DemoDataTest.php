<?php

namespace Tests\Feature;

use App\Models\CaseDocument;
use App\Models\ExtractedField;
use App\Models\PermitCase;
use App\Models\ValidationResult;
use App\Services\Cases\CaseScorer;
use App\Services\Cases\DocumentValidator;
use Database\Seeders\DemoCasesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCases;
use Tests\TestCase;

/**
 * دادهٔ نمایشی — تسک ۶۴۸ (زیرکار ۶۳۶).
 *
 * دو ادعای این فایل، هر دو از بازبینی آمده‌اند:
 *
 *  ۱) **هر مدرک نمایشی فایل تصویر واقعی دارد و سرو می‌شود.** قبلاً seeder فقط
 *     مسیر می‌ساخت و روی صفحهٔ نتیجه هر سه تصویر ۴۰۴ می‌گرفتند — یعنی مهم‌ترین
 *     کار کارشناس (سنجیدن مقدار فیلد با تصویر مدرک) روی کل دادهٔ نمونه شدنی نبود.
 *
 *  ۲) **دادهٔ نمایشی با خروجی موتور یکی است.** قبلاً وضعیت و امتیاز و
 *     ردیف‌های اعتبارسنجی دستی نوشته می‌شدند و اولین «پردازش دوباره» وضعیت را
 *     می‌پراند. حالا seeder خودش DocumentValidator و CaseScorer را اجرا می‌کند،
 *     پس اجرای دوبارهٔ همان‌ها باید *هیچ* چیزی را عوض نکند.
 */
class DemoDataTest extends TestCase
{
    use BuildsCases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeDisks();
    }

    // ==================================================================
    // ۱) تصویر مدارک
    // ==================================================================

    public function test_every_demo_document_has_a_readable_image_on_the_private_disk(): void
    {
        $this->seed(DemoCasesSeeder::class);

        $documents = CaseDocument::query()
            ->whereHas('permitCase', fn ($q) => $q->where('code', 'like', DemoCasesSeeder::CODE_PREFIX.'%'))
            ->get();

        $this->assertGreaterThanOrEqual(60, $documents->count(), 'بیست پروندهٔ نمایشی باید دست‌کم ۶۰ مدرک داشته باشند.');

        $disk = Storage::disk('documents');

        foreach ($documents as $document) {
            $this->assertSame('documents', $document->disk, 'مدرک نمایشی هم باید روی دیسک خصوصی باشد.');
            $this->assertTrue($disk->exists($document->path), 'فایل نبود: '.$document->path);

            $bytes = (string) $disk->get($document->path);

            // حداقل حجم precheck.limits — تصویری که از این کوچک‌تر باشد را
            // خودِ سامانه هم رد می‌کند، پس دادهٔ نمایشی نباید بسازدش.
            $this->assertGreaterThan(20 * 1024, strlen($bytes), 'تصویر نمایشی زیر حداقل حجم است: '.$document->path);
            $this->assertSame($document->size_bytes, strlen($bytes), 'size_bytes با فایل واقعی نمی‌خواند.');
            $this->assertSame($document->checksum, hash('sha256', $bytes), 'checksum با فایل واقعی نمی‌خواند.');

            $size = getimagesizefromstring($bytes);

            $this->assertIsArray($size, 'فایل نمایشی تصویر خوانا نیست: '.$document->path);
            $this->assertSame($document->width, $size[0]);
            $this->assertSame($document->height, $size[1]);
            $this->assertSame('image/jpeg', $size['mime']);
            $this->assertGreaterThanOrEqual(600, $size[0], 'عرض تصویر باید از حداقل precheck بیشتر باشد.');
            $this->assertGreaterThanOrEqual(380, $size[1], 'ارتفاع تصویر باید از حداقل precheck بیشتر باشد.');
        }
    }

    public function test_a_demo_document_image_is_served_through_the_protected_media_route(): void
    {
        $this->seed(DemoCasesSeeder::class);

        $document = CaseDocument::query()
            ->whereHas('permitCase', fn ($q) => $q->where('code', 'like', DemoCasesSeeder::CODE_PREFIX.'%'))
            ->firstOrFail();

        // اول مهمان: مدرک نمایشی هم مثل مدرک واقعی خصوصی است
        $this->get(route('media', ['disk' => $document->disk, 'path' => $document->path]))
            ->assertRedirect(route('login'));

        $expert = $this->expertUser();

        // همان نشانی‌ای که صفحهٔ نتیجهٔ پرونده می‌سازد
        $this->actingAs($expert)
            ->get(route('media', ['disk' => $document->disk, 'path' => $document->path, 'w' => 800]))
            ->assertOk();

        $this->actingAs($expert)
            ->get(route('media', ['disk' => $document->disk, 'path' => $document->path]))
            ->assertOk();

        $this->actingAs($this->dataUser())
            ->get(route('media', ['disk' => $document->disk, 'path' => $document->path]))
            ->assertForbidden();
    }

    // ==================================================================
    // ۲) سازگاری با موتور
    // ==================================================================

    public function test_running_the_engine_again_moves_no_demo_case(): void
    {
        $this->seed(DemoCasesSeeder::class);

        $before = $this->snapshot();

        $this->assertNotEmpty($before);

        $validator = app(DocumentValidator::class);
        $scorer = app(CaseScorer::class);

        foreach ($this->decidedDemoCases() as $case) {
            $validator->validate($case);
            $scorer->score($case);
        }

        $this->assertSame(
            $before,
            $this->snapshot(),
            'اجرای دوبارهٔ اعتبارسنجی و امتیازدهی نباید وضعیت یا امتیاز هیچ پروندهٔ نمایشی را عوض کند.',
        );
    }

    public function test_the_review_queue_of_the_demo_data_survives_a_reprocess(): void
    {
        $this->seed(DemoCasesSeeder::class);

        $queue = PermitCase::query()
            ->where('code', 'like', DemoCasesSeeder::CODE_PREFIX.'%')
            ->where('status', 'needs_review')
            ->pluck('code')
            ->sort()
            ->values()
            ->all();

        $this->assertNotEmpty($queue, 'داشبورد بدون صف بررسی انسانی نصفه است.');

        $validator = app(DocumentValidator::class);
        $scorer = app(CaseScorer::class);

        foreach ($this->decidedDemoCases() as $case) {
            $validator->validate($case);
            $scorer->score($case);
        }

        $this->assertSame(
            $queue,
            PermitCase::query()
                ->where('code', 'like', DemoCasesSeeder::CODE_PREFIX.'%')
                ->where('status', 'needs_review')
                ->pluck('code')
                ->sort()
                ->values()
                ->all(),
        );
    }

    public function test_no_demo_case_has_a_cross_failure_that_contradicts_its_status(): void
    {
        $this->seed(DemoCasesSeeder::class);

        $crossFailures = ValidationResult::query()
            ->where('scope', 'cross')
            ->where('status', 'failed')
            ->pluck('case_id')
            ->unique();

        // با پیش‌فرض scoring.thresholds.cross_fail_rejects = true هر پرونده‌ای
        // که ردیف cross/failed دارد باید «رد» باشد — همان چیزی که موتور می‌کند.
        $this->assertTrue($crossFailures->isNotEmpty(), 'دادهٔ نمایشی باید نمونهٔ ناهمخوانی بین مدارک داشته باشد.');

        foreach (PermitCase::query()->whereIn('id', $crossFailures)->get() as $case) {
            $this->assertSame(
                'rejected',
                $case->status,
                'پروندهٔ '.$case->code.' ردیف cross/failed دارد ولی وضعیتش «رد» نیست.',
            );
        }
    }

    // ==================================================================
    // ۳) پوشش وضعیت‌ها و idempotent بودن
    // ==================================================================

    public function test_the_demo_data_covers_every_dashboard_state(): void
    {
        $this->seed(DemoCasesSeeder::class);

        $counts = PermitCase::query()
            ->where('code', 'like', DemoCasesSeeder::CODE_PREFIX.'%')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        foreach (array_keys(PermitCase::STATUSES) as $status) {
            $this->assertGreaterThan(
                0,
                (int) $counts->get($status, 0),
                'وضعیت «'.$status.'» در دادهٔ نمایشی نماینده ندارد و نمودار توزیع را نصفه نشان می‌دهد.',
            );
        }

        // امتیاز و ایراد هم باید واقعی باشد، نه صفرِ خالی
        $this->assertGreaterThan(0, ValidationResult::where('status', 'failed')->count());
        $this->assertGreaterThan(0, ValidationResult::where('status', 'passed')->count());
        $this->assertGreaterThan(0, ExtractedField::where('source', 'manual')->count());
    }

    public function test_running_the_seeder_twice_leaves_no_duplicate_and_no_orphan_file(): void
    {
        $this->seed(DemoCasesSeeder::class);
        $this->seed(DemoCasesSeeder::class);

        $this->assertSame(20, PermitCase::query()->where('code', 'like', DemoCasesSeeder::CODE_PREFIX.'%')->count());

        $documents = CaseDocument::query()
            ->whereHas('permitCase', fn ($q) => $q->where('code', 'like', DemoCasesSeeder::CODE_PREFIX.'%'))
            ->get();

        $disk = Storage::disk('documents');

        foreach ($documents as $document) {
            $this->assertTrue($disk->exists($document->path));
        }

        // هیچ فایلی زیر demo/ نباید بی‌صاحب مانده باشد
        $onDisk = collect($disk->allFiles('demo'))->sort()->values()->all();
        $inDatabase = $documents->pluck('path')->sort()->values()->all();

        $this->assertSame($inDatabase, $onDisk, 'فایل یتیم یا فایل گمشده زیر پوشهٔ demo هست.');
    }

    // ==================================================================
    // ابزار
    // ==================================================================

    /**
     * وضعیت/تصمیم/امتیاز همهٔ پرونده‌های نمایشی — کلید مقایسهٔ «چیزی تکان نخورد».
     *
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        return PermitCase::query()
            ->where('code', 'like', DemoCasesSeeder::CODE_PREFIX.'%')
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (PermitCase $case): array => [
                $case->code => $case->status.'|'.$case->decision.'|'.$case->confidence_score,
            ])
            ->all();
    }

    /** پرونده‌هایی که پایپ‌لاین رویشان کامل اجرا شده است. */
    private function decidedDemoCases()
    {
        return PermitCase::query()
            ->where('code', 'like', DemoCasesSeeder::CODE_PREFIX.'%')
            ->whereIn('status', ['approved', 'rejected', 'needs_review'])
            ->get();
    }
}
