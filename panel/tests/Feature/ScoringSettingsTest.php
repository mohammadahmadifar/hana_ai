<?php

namespace Tests\Feature;

use App\Models\ExtractedField;
use App\Models\PermitCase;
use App\Models\Setting;
use App\Services\Cases\CaseScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsCases;
use Tests\TestCase;

/**
 * تسک ۶۳۴ — صفحهٔ «تنظیمات امتیازدهی».
 *
 * مهم‌ترین تستِ این فایل آخری است: با عوض‌کردن آستانه از روی همین صفحه، تصمیمِ
 * یک پروندهٔ مشخص عوض می‌شود. این ثابت می‌کند آستانه‌ها در کد هاردکد نیستند.
 */
class ScoringSettingsTest extends TestCase
{
    use BuildsCases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    public function test_admin_sees_the_page_with_current_values(): void
    {
        $response = $this->actingAs($this->adminUser())->get(route('admin.settings.scoring'));

        $response->assertOk();
        $response->assertSee('تنظیمات امتیازدهی');
        $response->assertSee('آستانهٔ تایید');
        $response->assertSee('name="weights[ocr_quality]"', false);
        $response->assertSee('value="80"', false);   // آستانهٔ تایید از ReferenceDataSeeder
        $response->assertSee('value="45"', false);   // آستانهٔ رد

        // نام روت با config/panel_menu.php می‌خواند، پس آیتم منو دیگر «به‌زودی» نیست.
        $response->assertSee(route('admin.settings.scoring'), false);
    }

    public function test_only_admin_can_reach_the_page(): void
    {
        $this->actingAs($this->expertUser())
            ->get(route('admin.settings.scoring'))
            ->assertForbidden();

        $this->actingAs($this->dataUser())
            ->put(route('admin.settings.scoring.update'), $this->payload())
            ->assertForbidden();
    }

    public function test_guest_is_sent_to_login(): void
    {
        // بدون actingAs، وگرنه کاربر تست قبلی هنوز وارد است و ۴۰۳ می‌گیریم نه ریدایرکت.
        $this->get(route('admin.settings.scoring'))->assertRedirect(route('login'));
    }

    public function test_saving_stores_all_three_keys_and_logs_the_change(): void
    {
        Log::spy();

        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->put(route('admin.settings.scoring.update'), $this->payload([
                'weights' => ['ocr_quality' => 30, 'validation' => 50, 'completeness' => 20],
                'approve_at' => 70,
                'reject_below' => 30,
                'penalties' => ['failed' => 40, 'warning' => 10],
            ]))
            ->assertRedirect(route('admin.settings.scoring'))
            ->assertSessionHas('success');

        $this->assertSame(30.0, (float) Setting::get('scoring.weights')['ocr_quality']);
        $this->assertSame(50.0, (float) Setting::get('scoring.weights')['validation']);
        $this->assertSame(70.0, (float) Setting::get('scoring.thresholds')['approve_at']);
        $this->assertSame(30.0, (float) Setting::get('scoring.thresholds')['reject_below']);
        $this->assertSame(40.0, (float) Setting::get('scoring.penalties')['failed']);

        // موتور امتیازدهی همین مقدارها را می‌خواند، نه چیز دیگری.
        $this->assertSame(50.0, CaseScorer::weights()['validation']);
        $this->assertSame(70.0, CaseScorer::thresholds()['approve_at']);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => $message === 'تغییر تنظیمات امتیازدهی'
                && $context['key'] === 'scoring.thresholds'
                && (float) $context['old']['approve_at'] === 80.0
                && (float) $context['new']['approve_at'] === 70.0
                && $context['user_id'] === $admin->id)
            ->once();

        // «آخرین تغییر توسط ... در ...» روی صفحه دیده می‌شود.
        $this->actingAs($admin)
            ->get(route('admin.settings.scoring'))
            ->assertSee($admin->name);
    }

    public function test_weights_must_add_up_to_one_hundred(): void
    {
        $this->actingAs($this->adminUser())
            ->put(route('admin.settings.scoring.update'), $this->payload([
                'weights' => ['ocr_quality' => 50, 'validation' => 50, 'completeness' => 20],
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('weights');

        $this->assertStringContainsString(
            'جمع سه وزن باید دقیقاً ۱۰۰ باشد',
            session('errors')->first('weights'),
        );

        // چیزی ذخیره نشده است.
        $this->assertSame(40.0, (float) Setting::get('scoring.weights')['ocr_quality']);
    }

    public function test_reject_threshold_must_be_below_approve_threshold(): void
    {
        $this->actingAs($this->adminUser())
            ->put(route('admin.settings.scoring.update'), $this->payload([
                'approve_at' => 60,
                'reject_below' => 75,
            ]))
            ->assertSessionHasErrors('reject_below');

        $this->assertStringContainsString(
            'کمتر از آستانهٔ تایید',
            session('errors')->first('reject_below'),
        );

        $this->assertSame(80.0, (float) Setting::get('scoring.thresholds')['approve_at']);
    }

    public function test_warning_penalty_cannot_exceed_failure_penalty(): void
    {
        $this->actingAs($this->adminUser())
            ->put(route('admin.settings.scoring.update'), $this->payload([
                'penalties' => ['failed' => 10, 'warning' => 30],
            ]))
            ->assertSessionHasErrors('penalties.warning');
    }

    public function test_out_of_range_numbers_are_rejected_with_persian_message(): void
    {
        $this->actingAs($this->adminUser())
            ->put(route('admin.settings.scoring.update'), $this->payload([
                'approve_at' => 140,
            ]))
            ->assertSessionHasErrors('approve_at');

        $this->assertStringContainsString(
            'آستانهٔ تایید',
            session('errors')->first('approve_at'),
        );
    }

    /**
     * تعریف done: تغییر آستانه در صفحهٔ تنظیمات → تصمیم همان پرونده عوض شود.
     */
    public function test_changing_threshold_on_the_page_flips_an_existing_case_decision(): void
    {
        $admin = $this->adminUser();
        $case = $this->cleanCase();

        app(CaseScorer::class)->score($case);
        $case->refresh();

        $this->assertSame('approved', $case->decision);
        $score = (float) $case->confidence_score;

        // مدیر آستانهٔ تایید را از روی صفحه بالاتر از امتیاز این پرونده می‌برد.
        $this->actingAs($admin)
            ->put(route('admin.settings.scoring.update'), $this->payload([
                'approve_at' => min(100, $score + 1),
                'reject_below' => 45,
            ]))
            ->assertSessionHasNoErrors();

        app(CaseScorer::class)->score($case = $case->fresh());
        $case->refresh();

        $this->assertSame('needs_review', $case->decision);
        $this->assertSame('needs_review', $case->status);

        // امتیاز عوض نشده؛ فقط خط تصمیم جابه‌جا شده است.
        $this->assertEqualsWithDelta($score, (float) $case->confidence_score, 0.01);
    }

    // ——— ابزار داخلی تست ———

    /** بدنهٔ معتبر فرم؛ هر تست فقط همان چیزی را که می‌خواهد بشکند جایگزین می‌کند. */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'weights' => ['ocr_quality' => 40, 'validation' => 40, 'completeness' => 20],
            'approve_at' => 80,
            'reject_below' => 45,
            'penalties' => ['failed' => 25, 'warning' => 8],
            'cross_fail_rejects' => '1',
        ], $overrides);
    }

    /** پروندهٔ سالم با همهٔ فیلدهای اجباری و بدون هیچ ایراد اعتبارسنجی. */
    private function cleanCase(): PermitCase
    {
        $case = $this->makeCaseWithDocuments('issue');

        foreach ($case->documents as $document) {
            foreach ($document->documentType->fields as $field) {
                if (! $field->is_required) {
                    continue;
                }

                ExtractedField::create([
                    'case_id' => $case->id,
                    'case_document_id' => $document->id,
                    'field_key' => $field->key,
                    'raw_value' => 'مقدار آزمایشی',
                    'normalized_value' => 'مقدار آزمایشی',
                    'confidence' => 95,
                    'source' => 'ocr',
                ]);
            }
        }

        return $case->fresh();
    }
}
