<?php

namespace Tests\Feature;

use App\Models\CaseDocument;
use App\Models\PermitCase;
use App\Models\ServiceType;
use App\Models\TestImage;
use App\Models\User;
use App\Services\HanaEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Concerns\BuildsCases;
use Tests\TestCase;

/**
 * تسک ۶۲۷ — دکمهٔ «بفرست به فرایند بررسی» در صفحهٔ تصویر تستی.
 *
 * ادعایی که این فایل ثابت می‌کند: از یک تصویر تستی می‌شود بدون دانلود و
 * بارگذاری دستی، یک پروندهٔ پیش‌نویس ساخت که همان تصویر مدرک اولش باشد —
 * و این کار نه پرونده را خودکار ثبت می‌کند، نه در دست کاربری می‌افتد که
 * اصلاً حق ساختن پرونده ندارد.
 */
class TestImageToCaseTest extends TestCase
{
    use BuildsCases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeDisks();
        $this->seedReferenceData();
        $this->engineAlwaysSaysSharp();
    }

    // ==================================================================
    // مسیر اصلی
    // ==================================================================

    public function test_expert_turns_a_test_image_into_a_draft_case_with_that_document(): void
    {
        $user = $this->expertUser();
        $image = $this->testImage($user, 'national_card');

        $service = ServiceType::where('key', 'issue')->firstOrFail();

        $response = $this->actingAs($user)
            ->post(route('cases.fromTestImage', $image), ['service_type_id' => $service->id]);

        $case = PermitCase::latest('id')->firstOrFail();

        $response->assertRedirect(route('cases.documents.edit', $case));

        $this->assertSame('draft', $case->status, 'پرونده باید پیش‌نویس بماند تا کاربر بقیهٔ مدارک را بگذارد.');
        $this->assertSame($user->id, $case->user_id);
        $this->assertSame($service->id, $case->service_type_id);

        $document = $case->documents()->firstOrFail();

        $this->assertSame($this->documentTypeId('national_card'), $document->document_type_id);
        $this->assertSame('documents', $document->disk);
        Storage::disk('documents')->assertExists($document->path);

        // تصویر تستی کپی می‌شود، منتقل نمی‌شود — باید بشود دوباره از آن استفاده کرد.
        Storage::disk('testimages')->assertExists($image->path);
    }

    public function test_applicant_name_and_national_id_come_from_the_printed_data(): void
    {
        $user = $this->expertUser();

        $image = $this->testImage($user, 'national_card', [
            'first_name' => 'زهرا',
            'last_name' => 'کریمی',
            'national_id' => '۰۰۶۹۵۳۷۴۱۰',
        ]);

        $this->actingAs($user)->post(route('cases.fromTestImage', $image));

        $case = PermitCase::latest('id')->firstOrFail();

        $this->assertSame('زهرا کریمی', $case->applicant_name);
        $this->assertSame('۰۰۶۹۵۳۷۴۱۰', $case->applicant_national_id);
    }

    public function test_the_new_case_gets_its_own_unique_code(): void
    {
        $user = $this->expertUser();

        $this->actingAs($user)->post(route('cases.fromTestImage', $this->testImage($user, 'national_card')));
        $this->actingAs($user)->post(route('cases.fromTestImage', $this->testImage($user, 'driving_license')));

        $codes = PermitCase::pluck('code');

        $this->assertCount(2, $codes);
        $this->assertCount(2, $codes->unique(), 'کد دو پرونده نباید یکی باشد.');
        $this->assertMatchesRegularExpression('/^HA-1[34]\d\d-\d{6}$/', (string) $codes->first());
    }

    // ==================================================================
    // بررسی اولیهٔ فایل هم روی همین مسیر اجرا می‌شود
    // ==================================================================

    public function test_precheck_runs_on_the_copied_document(): void
    {
        $user = $this->expertUser();
        $image = $this->testImage($user, 'national_card');

        $this->actingAs($user)->post(route('cases.fromTestImage', $image));

        $document = CaseDocument::latest('id')->firstOrFail();

        $this->assertNotSame(
            'pending',
            $document->precheck_status,
            'مدرکی که از تصویر تستی می‌آید هم باید مثل مدرک آپلودی بررسی اولیه شود، نه اینکه از آن در برود.',
        );
    }

    // ==================================================================
    // دسترسی
    // ==================================================================

    public function test_data_role_cannot_open_a_case_from_a_test_image(): void
    {
        $user = $this->dataUser();
        $image = $this->testImage($user, 'national_card');

        $this->actingAs($user)
            ->post(route('cases.fromTestImage', $image))
            ->assertForbidden();

        $this->assertSame(0, PermitCase::count(), 'کارشناس داده اصلاً پرونده نمی‌سازد.');
    }

    public function test_expert_cannot_use_someone_elses_test_image(): void
    {
        $owner = $this->expertUser();
        $other = $this->userWithRole('expert');

        $image = $this->testImage($owner, 'national_card');

        $this->actingAs($other)
            ->post(route('cases.fromTestImage', $image))
            ->assertForbidden();

        $this->assertSame(0, PermitCase::count());
    }

    public function test_admin_may_use_any_test_image(): void
    {
        $owner = $this->expertUser();
        $image = $this->testImage($owner, 'national_card');

        $this->actingAs($this->adminUser())
            ->post(route('cases.fromTestImage', $image))
            ->assertRedirect();

        $this->assertSame(1, PermitCase::count());
    }

    // ==================================================================
    // حالت‌های مرزی
    // ==================================================================

    public function test_missing_file_does_not_leave_an_empty_case_behind(): void
    {
        $user = $this->expertUser();
        $image = $this->testImage($user, 'national_card');

        Storage::disk('testimages')->delete($image->path);

        $this->actingAs($user)
            ->post(route('cases.fromTestImage', $image))
            ->assertRedirect();

        $this->assertSame(0, PermitCase::count(), 'وقتی فایل نیست، نباید پروندهٔ خالی جا بماند.');
    }

    public function test_an_invalid_service_falls_back_to_one_that_needs_this_document(): void
    {
        $user = $this->expertUser();
        $image = $this->testImage($user, 'national_card');

        $this->actingAs($user)->post(route('cases.fromTestImage', $image), ['service_type_id' => 9999]);

        $case = PermitCase::latest('id')->firstOrFail();

        $this->assertTrue(
            ServiceType::find($case->service_type_id)
                ->documentTypes
                ->contains('id', $this->documentTypeId('national_card')),
            'خدمتِ انتخاب‌شده باید همان مدرک را جزو مدارک لازمش داشته باشد.',
        );
    }

    public function test_the_button_shows_on_the_page_for_an_expert_but_not_for_data_role(): void
    {
        $owner = $this->expertUser();
        $image = $this->testImage($owner, 'national_card');

        $this->actingAs($owner)
            ->get(route('testimage.show', $image))
            ->assertOk()
            ->assertSee('بفرست به فرایند بررسی');

        $dataImage = $this->testImage($this->dataUser(), 'national_card');

        $this->actingAs(User::where('role', 'data')->firstOrFail())
            ->get(route('testimage.show', $dataImage))
            ->assertOk()
            ->assertDontSee('بفرست به فرایند بررسی');
    }

    // ==================================================================
    // ابزار
    // ==================================================================

    /** @param  array<string, string>  $payload */
    private function testImage(User $user, string $typeKey, array $payload = []): TestImage
    {
        $path = 'tests/'.$typeKey.'-'.$user->id.'-'.uniqid().'.png';

        Storage::disk('testimages')->put($path, file_get_contents($this->makeImageFile()));

        return TestImage::create([
            'user_id' => $user->id,
            'document_type_id' => $this->documentTypeId($typeKey),
            'payload' => $payload ?: ['first_name' => 'علی', 'last_name' => 'زارعی'],
            'augmentations' => [],
            'disk' => 'testimages',
            'path' => $path,
            'width' => 960,
            'height' => 540,
        ]);
    }

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
