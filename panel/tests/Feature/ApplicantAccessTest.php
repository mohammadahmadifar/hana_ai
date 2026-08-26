<?php

namespace Tests\Feature;

use App\Models\CaseDocument;
use App\Models\ExtractedField;
use App\Models\PermitCase;
use App\Models\ServiceType;
use App\Services\HanaEngine;
use App\Support\PersianValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Concerns\BuildsCases;
use Tests\TestCase;

/**
 * نقش «متقاضی» — بیرونی‌ترین حلقهٔ دسترسی سامانه.
 *
 * ادعایی که این فایل باید ثابت کند دو نیمه دارد و هر دو نیمه لازم است:
 *
 *   می‌تواند: درخواست خودش را بسازد، مدرک بارگذاری کند، ثبت کند، نتیجه و
 *   دلیل تصمیم را ببیند، و تصویر مدرکِ پروندهٔ خودش را باز کند.
 *
 *   نمی‌تواند: صف بررسی، گزارش خطاها، دیتاست، تصویر تستی، مدیریت کاربران،
 *   و — مهم‌تر از همه — هیچ چیزِ پروندهٔ کاربر دیگری، نه صفحه‌اش نه فایلش.
 *
 * نیمهٔ دوم بی نیمهٔ اول هم بی‌معناست: اگر متقاضی نتواند فایل خودش را ببیند،
 * صفحهٔ نتیجه‌اش تصویر ندارد و بررسیِ «این مقدار با مدرک می‌خواند یا نه» برای
 * خودش هم ناممکن می‌شود.
 */
class ApplicantAccessTest extends TestCase
{
    use BuildsCases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeDisks();
        $this->seedReferenceData();
        $this->engineAlwaysSaysSharp();

        // پایپ‌لاین OCR کار این فایل نیست؛ این‌جا فقط مرز دسترسی سنجیده می‌شود.
        Queue::fake();
    }

    // ==================================================================
    // آنچه متقاضی می‌تواند
    // ==================================================================

    public function test_applicant_can_open_the_new_request_page(): void
    {
        $this->actingAs($this->applicantUser())
            ->get(route('cases.create'))
            ->assertOk();
    }

    public function test_applicant_can_create_a_case_and_upload_a_document(): void
    {
        $user = $this->applicantUser();
        $service = ServiceType::query()->where('key', 'issue')->firstOrFail();

        $this->actingAs($user)->post(route('cases.store'), [
            'service_type_id' => $service->id,
            'applicant_name' => 'متقاضی آزمایشی',
        ])->assertRedirect();

        $case = PermitCase::query()->latest('id')->firstOrFail();

        $this->assertSame((int) $user->id, (int) $case->user_id);

        $this->actingAs($user)->post(route('cases.documents.store', $case), [
            'document_type_id' => $this->documentTypeId('national_card'),
            'file' => new UploadedFile($this->makeImageFile(), 'card.png', 'image/png', null, true),
        ])->assertRedirect();

        $this->assertSame(1, $case->documents()->count());
    }

    public function test_applicant_sees_own_case_result_read_only(): void
    {
        $user = $this->applicantUser();

        $case = $this->makeCaseWithDocuments('issue', $user);
        $case->forceFill(['status' => 'needs_review', 'confidence_score' => 62.0])->save();

        $response = $this->actingAs($user)->get(route('cases.show', $case))->assertOk();

        // نتیجه را می‌بیند، ولی نه فرم تصمیم و نه دکمهٔ صف بررسی.
        $response->assertDontSee(route('cases.decide', $case));
        $response->assertDontSee(route('cases.review'));
        $response->assertSee('تصمیم‌گیری روی این پرونده با کارشناس بررسی است.', false);
    }

    /**
     * پلاک در حالت فقط‌خواندنی هم به شکل خودِ پلاک است، نه متن (تسک ۷۴۱).
     *
     * مسیر فقط‌خواندنی شاخهٔ جداییِ ویو است؛ بدون این تست فقط حالت «قابل اصلاحِ
     * کارشناس» پوشش داشت، در حالی که متقاضی هرگز آن شاخه را نمی‌بیند.
     */
    public function test_applicant_sees_the_plate_as_a_plate_not_as_text(): void
    {
        $user = $this->applicantUser();

        $case = $this->makeCaseWithDocuments('issue', $user);
        $case->forceFill(['status' => 'approved', 'confidence_score' => 88.0])->save();

        $vehicleCard = $case->documents()
            ->where('document_type_id', $this->documentTypeId('vehicle_card'))
            ->firstOrFail();

        ExtractedField::create([
            'case_id' => $case->id,
            'case_document_id' => $vehicleCard->id,
            'field_key' => 'plate_number',
            'raw_value' => '12 ب 345 ایران 67',
            'normalized_value' => PersianValue::forEngine('plate', '12 ب 345 ایران 67'),
            'confidence' => 90,
            'source' => 'ocr',
        ]);

        $response = $this->actingAs($user)->get(route('cases.show', $case))->assertOk();

        $response->assertSee('class="plate"', false);
        $response->assertSee('aria-label="شمارهٔ پلاک: ۱۲ ب ۳۴۵ ایران ۶۷"', false);
        $response->assertSee('<span class="plate__code">۶۷</span>', false);

        // و همچنان فقط‌خواندنی است: متقاضی ورودی اصلاح نمی‌بیند.
        $response->assertDontSee('name="fields[plate_number]"', false);
    }

    public function test_applicant_can_open_the_image_of_their_own_document(): void
    {
        $user = $this->applicantUser();
        $case = $this->makeCaseWithDocuments('issue', $user);

        /** @var CaseDocument $document */
        $document = $case->documents->first();

        Storage::disk('documents')->put($document->path, 'fake-bytes');

        $this->actingAs($user)
            ->get(route('media', ['disk' => 'documents', 'path' => $document->path]))
            ->assertOk();
    }

    public function test_applicant_dashboard_counts_only_their_own_cases(): void
    {
        $user = $this->applicantUser();

        $mine = $this->makeCaseWithDocuments('issue', $user);
        $mine->forceFill(['status' => 'approved'])->save();

        $other = $this->makeCaseWithDocuments('issue', $this->expertUser());
        $other->forceFill(['status' => 'approved'])->save();

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        // کد پرونده با رقم فارسی چاپ می‌شود، پس مقایسه هم باید با همان شکل باشد.
        $response->assertSee('درخواست‌های من', false);
        $response->assertSee(PersianValue::toPersianDigits($mine->code), false);
        $response->assertDontSee(PersianValue::toPersianDigits($other->code), false);

        // داشبورد متقاضی هیچ عدد سراسری‌ای ندارد؛ «کل پرونده‌ها» مال آن یکی صفحه است.
        $response->assertDontSee('کل پرونده‌ها', false);
        $response->assertDontSee('صف بررسی انسانی', false);
    }

    // ==================================================================
    // آنچه متقاضی نمی‌تواند
    // ==================================================================

    public function test_applicant_is_locked_out_of_staff_areas(): void
    {
        $user = $this->applicantUser();

        foreach ([
            route('cases.review'),
            route('reports.index'),
            route('dataset.samples.index'),
            route('dataset.annotate.index'),
            route('testimage.create'),
            route('admin.users.index'),
            route('admin.settings.scoring'),
        ] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }

    public function test_applicant_cannot_touch_another_users_case(): void
    {
        $user = $this->applicantUser();
        $other = $this->makeCaseWithDocuments('issue', $this->expertUser());
        $other->forceFill(['status' => 'needs_review'])->save();

        $this->actingAs($user)->get(route('cases.show', $other))->assertForbidden();
        $this->actingAs($user)->get(route('cases.documents.edit', $other))->assertForbidden();
        $this->actingAs($user)->get(route('cases.processing.status', $other))->assertForbidden();

        /** @var CaseDocument $document */
        $document = $other->documents->first();

        Storage::disk('documents')->put($document->path, 'fake-bytes');

        $this->actingAs($user)
            ->get(route('media', ['disk' => 'documents', 'path' => $document->path]))
            ->assertForbidden();
    }

    public function test_applicant_cannot_decide_or_edit_fields_even_on_their_own_case(): void
    {
        $user = $this->applicantUser();
        $case = $this->makeCaseWithDocuments('issue', $user);
        $case->forceFill(['status' => 'needs_review'])->save();

        /** @var CaseDocument $document */
        $document = $case->documents->first();

        $this->actingAs($user)
            ->post(route('cases.decide', $case), ['decision' => 'approved', 'reason' => 'خودم تایید می‌کنم'])
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('cases.fields.update', [$case, $document]), ['fields' => ['national_id' => '1234567891']])
            ->assertForbidden();

        $this->assertNull($case->fresh()->decision);
    }

    public function test_applicant_case_list_shows_only_their_own(): void
    {
        $user = $this->applicantUser();

        $mine = $this->makeCaseWithDocuments('issue', $user);
        $other = $this->makeCaseWithDocuments('issue', $this->expertUser());

        // حتی با ?all=1 — پارامتری که فقط مدیر سامانه را باز می‌کند.
        $response = $this->actingAs($user)->get(route('cases.index', ['all' => 1]))->assertOk();

        $response->assertSee(PersianValue::toPersianDigits($mine->code), false);
        $response->assertDontSee(PersianValue::toPersianDigits($other->code), false);
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
