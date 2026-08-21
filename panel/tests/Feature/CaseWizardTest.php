<?php

namespace Tests\Feature;

use App\Models\CaseDocument;
use App\Models\PermitCase;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\HanaEngine;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCreateDirectory;
use Mockery\MockInterface;
use Tests\Concerns\BuildsCases;
use Tests\TestCase;
use Throwable;

/**
 * تسک ۶۲۹ — ویزارد «انتخاب نوع خدمت و دریافت مدارک».
 *
 * ادعای اصلی که این فایل باید ثابت کند: فهرست مدارک **از دیتابیس** ساخته
 * می‌شود و به نوع خدمت وابسته است — صدور سه مدرک، تمدید چهار مدرک — و
 * هیچ‌جای کد نام خدمت را نمی‌شناسد.
 *
 * موتور پایتون در همهٔ تست‌ها mock می‌شود: این‌جا تصمیم ویزارد سنجیده
 * می‌شود، نه عددی که امروز OpenCV برای تاری می‌دهد (آن کار تسک ۶۳۰ است).
 */
class CaseWizardTest extends TestCase
{
    use BuildsCases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeDisks();
        $this->seedReferenceData();
        $this->engineAlwaysSaysSharp();

        // ثبت پرونده از تسک ۶۳۱ به بعد Job پردازش را dispatch می‌کند. صف در
        // تست‌ها sync است، پس بدون این خط کل پایپ‌لاین OCR داخل همین درخواست
        // اجرا می‌شد و این فایل به‌جای «ویزارد» موتور را می‌سنجید.
        Queue::fake();
    }

    // ==================================================================
    // تعریف done: دو پروندهٔ کامل با فهرست مدارک متفاوت
    // ==================================================================

    public function test_issue_case_needs_three_documents_and_renew_needs_four(): void
    {
        $user = $this->expertUser();

        $issue = $this->createCase($user, 'issue');
        $renew = $this->createCase($user, 'renew');

        $this->assertSame(
            ['national_card', 'driving_license', 'vehicle_card'],
            $this->checklistKeys($user, $issue),
            'مدارک «صدور مجوز» باید دقیقاً همان سه‌تای پیوت باشد.',
        );

        $this->assertSame(
            ['national_card', 'driving_license', 'vehicle_card', 'previous_permit'],
            $this->checklistKeys($user, $renew),
            'مدارک «تمدید مجوز» باید همان سه‌تا به‌علاوهٔ مجوز قبلی باشد.',
        );

        // --- پروندهٔ صدور: سه مدرک کافی است ---
        foreach (['national_card', 'driving_license', 'vehicle_card'] as $key) {
            $this->upload($user, $issue, $key)->assertRedirect();
        }

        $this->actingAs($user)
            ->post(route('cases.submit', $issue))
            ->assertRedirect();

        // ثبت‌شدن بلافاصله پرونده را به صف پردازش می‌سپارد (تسک ۶۳۱).
        $this->assertSame('processing', $issue->refresh()->status);
        $this->assertNotNull($issue->submitted_at);
        $this->assertCount(3, $issue->documents);

        // --- پروندهٔ تمدید: همان سه مدرک کافی نیست ---
        foreach (['national_card', 'driving_license', 'vehicle_card'] as $key) {
            $this->upload($user, $renew, $key)->assertRedirect();
        }

        $this->actingAs($user)
            ->post(route('cases.submit', $renew))
            ->assertSessionHas('error');

        $this->assertSame('draft', $renew->refresh()->status, 'تمدید با سه مدرک نباید ثبت شود.');

        $this->actingAs($user)
            ->get(route('cases.documents.edit', $renew))
            ->assertOk()
            ->assertSee('ناقص')
            ->assertSee('مجوز قبلی');

        // --- مدرک چهارم که بیاید، ثبت می‌شود ---
        $this->upload($user, $renew, 'previous_permit')->assertRedirect();

        $this->actingAs($user)
            ->post(route('cases.submit', $renew))
            ->assertRedirect();

        $this->assertSame('processing', $renew->refresh()->status);
        $this->assertCount(4, $renew->documents);
    }

    // ==================================================================
    // ساخت پرونده و کد یکتا
    // ==================================================================

    public function test_case_code_follows_the_jalali_pattern_and_stays_unique(): void
    {
        $user = $this->expertUser();

        $first = $this->createCase($user, 'issue');
        $second = $this->createCase($user, 'renew');

        foreach ([$first, $second] as $case) {
            $this->assertMatchesRegularExpression(
                '/^HA-1[34][0-9]{2}-[0-9]{6}$/',
                $case->code,
                'کد پرونده باید الگوی HA-{سال شمسی}-{شش رقم} داشته باشد.',
            );
        }

        $this->assertNotSame($first->code, $second->code);
        $this->assertSame('draft', $first->status);
        $this->assertSame($user->id, $first->user_id);
    }

    public function test_the_first_step_lists_every_service_with_its_own_documents(): void
    {
        $page = $this->actingAs($this->expertUser())->get(route('cases.create'));

        $page->assertOk()
            ->assertSee('صدور مجوز')
            ->assertSee('تمدید مجوز')
            ->assertSee('مجوز قبلی')     // فقط زیر «تمدید» می‌آید
            ->assertSee('انتخاب نوع خدمت');

        $services = $page->viewData('services');

        $this->assertSame(3, $services->firstWhere('key', 'issue')->documentTypes->count());
        $this->assertSame(4, $services->firstWhere('key', 'renew')->documentTypes->count());
    }

    public function test_an_invalid_service_type_is_refused(): void
    {
        $user = $this->expertUser();

        $this->actingAs($user)
            ->post(route('cases.store'), ['service_type_id' => 9999])
            ->assertSessionHasErrors('service_type_id');

        $this->assertSame(0, PermitCase::query()->count());
    }

    public function test_dataset_expert_has_no_access_to_the_wizard(): void
    {
        $this->actingAs($this->dataUser())
            ->get(route('cases.create'))
            ->assertForbidden();
    }

    // ==================================================================
    // آپلود: مسیر امن روی دیسک خصوصی
    // ==================================================================

    public function test_upload_lands_on_the_private_disk_with_a_server_made_name(): void
    {
        $user = $this->expertUser();
        $case = $this->createCase($user, 'issue');

        // نام و پسوندِ ارسالی دروغ می‌گویند: محتوا JPEG است ولی اسمش png.
        $file = new UploadedFile($this->makeImageFile(extension: 'jpg'), 'evil.png', null, null, true);

        $this->upload($user, $case, 'national_card', $file)->assertRedirect();

        /** @var CaseDocument $document */
        $document = $case->documents()->firstOrFail();

        $this->assertSame('documents', $document->disk);
        $this->assertMatchesRegularExpression(
            '#^cases/'.$case->id.'/national_card-[0-9a-f-]{36}\.jpg$#',
            $document->path,
            'مسیر باید کاملاً سمت سرور ساخته شود و پسوندش از محتوای فایل بیاید.',
        );

        $this->assertStringNotContainsString('evil', $document->path);
        $this->assertSame('evil.png', $document->original_name, 'نام اصلی فقط برای نمایش نگه داشته می‌شود.');

        Storage::disk('documents')->assertExists($document->path);
        Storage::disk('public')->assertMissing($document->path);

        $this->assertSame('passed', $document->precheck_status);
        $this->assertSame('image/jpeg', $document->mime);
    }

    public function test_a_document_type_outside_this_service_is_refused(): void
    {
        $user = $this->expertUser();
        $case = $this->createCase($user, 'issue');

        // «مجوز قبلی» فقط مدرکِ تمدید است، نه صدور.
        $this->upload($user, $case, 'previous_permit')->assertSessionHas('error');

        $this->assertSame(0, $case->documents()->count());
    }

    // ==================================================================
    // فایلی که اعتبارسنجی اولیه ردش می‌کند
    // ==================================================================

    public function test_a_rejected_file_explains_the_problem_and_keeps_the_case_incomplete(): void
    {
        $user = $this->expertUser();
        $case = $this->createCase($user, 'issue');

        // PDF که خودش را کارت ملی جا زده — DocumentPrecheck باید ردش کند.
        $this->upload($user, $case, 'national_card', $this->uploadedFakePdf('card.jpg'))
            ->assertRedirect()
            ->assertSessionHas('error');

        /** @var CaseDocument $document */
        $document = $case->documents()->firstOrFail();

        $this->assertSame('failed', $document->precheck_status);
        $this->assertNotEmpty($document->precheck_issues);

        $page = $this->actingAs($user)->get(route('cases.documents.edit', $case));

        $page->assertOk()
            ->assertSee('ناقص')
            ->assertSee('پذیرفته نشد');

        // پیام باید بگوید مشکل چیست و کاربر چه کند.
        $issue = $document->precheck_issues[0];
        $this->assertNotEmpty($issue['message_fa']);
        $this->assertNotEmpty($issue['hint_fa']);

        // و پرونده ناقص می‌ماند
        $this->actingAs($user)->post(route('cases.submit', $case))->assertSessionHas('error');
        $this->assertSame('draft', $case->refresh()->status);
    }

    // ==================================================================
    // دیسکی که نمی‌شود روی آن نوشت
    // ==================================================================

    /**
     * سناریوی واقعیِ پروداکشن: پوشهٔ `storage/app/private/documents/cases`
     * مالکش root با مجوز 0700 بود و php-fpm (www-data) نمی‌توانست زیرپوشهٔ
     * پرونده را بسازد. `putFileAs` استثنای UnableToCreateDirectory پرتاب
     * می‌کرد، `'throw' => false` دیسک آن را نمی‌گرفت و کاربر صفحهٔ ۵۰۰
     * انگلیسی می‌دید. هر سه POST بارگذاری مدرک با ۵۰۰ می‌افتاد.
     */
    public function test_a_disk_that_cannot_be_written_shows_a_persian_message_not_a_500(): void
    {
        $user = $this->expertUser();
        $case = $this->createCase($user, 'issue');

        $this->breakDocumentsDisk(
            UnableToCreateDirectory::atLocation('cases/'.$case->id, 'mkdir(): Permission denied'),
        );

        $response = $this->upload($user, $case, 'national_card');

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $message = (string) session('error');

        // پیام باید بگوید «چه شد» و «کاربر چه کند» — نه فقط «خطا رخ داد».
        $this->assertStringContainsString('کارت ملی', $message);
        $this->assertStringContainsString('ذخیره نشد', $message);
        // پیام باید دقیقاً همین علت را نام ببرد، نه یک «خطای عمومی».
        $this->assertStringContainsString('پوشهٔ نگهداری مدارک', $message);
        $this->assertStringContainsString('مدیر سامانه', $message);
        $this->assertStringContainsString('کد پیگیری', $message);
        $this->assertMatchesRegularExpression('/[A-Z0-9]{6}/', $message, 'کد پیگیری باید در پیام بیاید.');
        $this->assertDoesNotMatchRegularExpression('/Unable to|Flysystem|Exception/i', $message);

        // و هیچ رکورد نصفه‌نیمه‌ای در دیتابیس نمی‌ماند.
        $this->assertSame(0, $case->documents()->count());
        $this->assertSame('draft', $case->refresh()->status);
    }

    public function test_a_silent_write_failure_is_also_reported_in_persian(): void
    {
        $user = $this->expertUser();
        $case = $this->createCase($user, 'issue');

        // مسیر دوم: خودِ لاراول به‌خاطر 'throw' => false استثنا را می‌خورد و
        // false برمی‌گرداند. کاربر آن‌جا هم باید همان پیام روشن را بگیرد.
        $this->breakDocumentsDisk(null);

        $this->upload($user, $case, 'national_card')
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertStringContainsString('ذخیره نشد', (string) session('error'));
        $this->assertSame(0, $case->documents()->count());
    }

    /**
     * دیسک `documents` را با یک دیسک خراب عوض می‌کند.
     *
     * @param  Throwable|null  $failure  استثنایی که putFileAs پرتاب کند؛ null یعنی false برگرداند
     */
    private function breakDocumentsDisk(?Throwable $failure): void
    {
        $broken = \Mockery::mock(Filesystem::class);

        $expectation = $broken->shouldReceive('putFileAs');

        $failure === null ? $expectation->andReturn(false) : $expectation->andThrow($failure);

        Storage::set('documents', $broken);
    }

    // ==================================================================
    // جایگزینی فایل
    // ==================================================================

    public function test_replacing_a_document_deletes_the_previous_file(): void
    {
        $user = $this->expertUser();
        $case = $this->createCase($user, 'issue');

        $this->upload($user, $case, 'national_card', $this->uploadedFakePdf('card.jpg'));

        $rejected = $case->documents()->firstOrFail();
        $oldPath = $rejected->path;

        Storage::disk('documents')->assertExists($oldPath);

        // همان نوع مدرک، این بار فایل درست
        $this->upload($user, $case, 'national_card', $this->uploadedImage('card.jpg'))
            ->assertSessionHas('success');

        $this->assertSame(1, $case->documents()->count(), 'جایگزینی نباید ردیف دوم بسازد.');

        $document = $case->documents()->firstOrFail();

        $this->assertSame($rejected->id, $document->id, 'جایگزینی روی همان ردیف انجام می‌شود.');
        $this->assertNotSame($oldPath, $document->path);
        $this->assertSame('passed', $document->precheck_status);
        $this->assertEmpty($document->precheck_issues);

        Storage::disk('documents')->assertMissing($oldPath);
        Storage::disk('documents')->assertExists($document->path);
    }

    public function test_deleting_a_document_makes_the_case_incomplete_again(): void
    {
        $user = $this->expertUser();
        $case = $this->createCase($user, 'issue');

        $this->upload($user, $case, 'national_card');

        $document = $case->documents()->firstOrFail();
        $path = $document->path;

        $this->actingAs($user)
            ->delete(route('cases.documents.destroy', [$case, $document]))
            ->assertRedirect();

        $this->assertSame(0, $case->documents()->count());
        Storage::disk('documents')->assertMissing($path);
    }

    // ==================================================================
    // مالکیت
    // ==================================================================

    public function test_one_expert_cannot_reach_another_experts_case(): void
    {
        $owner = $this->expertUser();
        $stranger = $this->expertUser();

        $case = $this->createCase($owner, 'issue');

        $this->actingAs($stranger)
            ->get(route('cases.documents.edit', $case))
            ->assertForbidden();

        $this->upload($stranger, $case, 'national_card')->assertForbidden();

        $listed = $this->actingAs($stranger)
            ->get(route('cases.index'))
            ->assertOk()
            ->viewData('cases');

        $this->assertNotContains($case->id, $listed->pluck('id')->all(), 'پروندهٔ کاربر دیگر نباید در فهرست بیاید.');

        // مدیر سامانه با all=1 همه را می‌بیند
        $all = $this->actingAs($this->adminUser())
            ->get(route('cases.index', ['all' => 1]))
            ->assertOk()
            ->viewData('cases');

        $this->assertContains($case->id, $all->pluck('id')->all());

        // مدیر سامانه همه را می‌بیند
        $this->actingAs($this->adminUser())
            ->get(route('cases.documents.edit', $case))
            ->assertOk();
    }

    public function test_a_submitted_case_is_locked_for_uploads(): void
    {
        $user = $this->expertUser();
        $case = $this->makeCaseWithDocuments('issue', $user);

        $case->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();

        $this->upload($user, $case, 'national_card')->assertForbidden();

        $this->actingAs($user)
            ->get(route('cases.documents.edit', $case))
            ->assertOk()
            ->assertSee('قفل');
    }

    // ==================================================================
    // ابزار
    // ==================================================================

    /** ساخت پرونده از راه خودِ ویزارد (نه فکتوری) تا مسیر واقعی تست شود. */
    private function createCase(User $user, string $serviceKey): PermitCase
    {
        $service = ServiceType::query()->where('key', $serviceKey)->firstOrFail();

        $response = $this->actingAs($user)->post(route('cases.store'), [
            'service_type_id' => $service->id,
            'applicant_name' => 'متقاضی آزمایشی',
        ]);

        $case = PermitCase::query()->where('service_type_id', $service->id)->latest('id')->firstOrFail();

        $response->assertRedirect(route('cases.documents.edit', $case));

        return $case;
    }

    /** بارگذاری یک مدرک روی پرونده. */
    private function upload(User $user, PermitCase $case, string $typeKey, ?UploadedFile $file = null)
    {
        return $this->actingAs($user)->post(route('cases.documents.store', $case), [
            'document_type_id' => $this->documentTypeId($typeKey),
            // پسوند jpg عمدی است: PNG تخت زیر حداقل حجم ۲۰ کیلوبایتی می‌افتد.
            'file' => $file ?? $this->uploadedImage('card.jpg'),
        ]);
    }

    /**
     * کلید مدارکی که صفحهٔ مدارک برای این پرونده می‌خواهد.
     *
     * @return list<string>
     */
    private function checklistKeys(User $user, PermitCase $case): array
    {
        $checklist = $this->actingAs($user)
            ->get(route('cases.documents.edit', $case))
            ->assertOk()
            ->viewData('checklist');

        return array_map(static fn (array $row): string => (string) $row['type']->key, $checklist);
    }

    /** موتور در تست‌های ویزارد همیشه «تصویر سالم» می‌گوید. */
    private function engineAlwaysSaysSharp(): void
    {
        $this->mock(HanaEngine::class, function (MockInterface $mock): void {
            $mock->shouldReceive('imageQuality')->andReturn([
                'width' => 960,
                'height' => 540,
                'blur_score' => 420.0,
                'brightness' => 150.0,
                'is_blurry_hint' => false,
            ]);
        });
    }
}
