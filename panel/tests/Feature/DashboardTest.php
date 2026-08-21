<?php

namespace Tests\Feature;

use App\Models\PermitCase;
use App\Models\User;
use Database\Seeders\DemoCasesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsCases;
use Tests\TestCase;

/**
 * داشبورد وضعیت — تسک ۶۳۶.
 *
 * سه چیز را می‌سنجد که اگر بشکنند صفحه بی‌ارزش می‌شود:
 *   ۱) دیتابیس خالی صفحه را نمی‌ترکاند و «میانگین»ِ نداشته صفر نشان داده نمی‌شود.
 *   ۲) نقش «کارشناس داده» هیچ داده‌ای از پرونده‌ها نمی‌بیند.
 *   ۳) صف بررسی واقعاً فوری‌ترین را اول می‌آورد، نه صرفاً تازه‌ترین.
 */
class DashboardTest extends TestCase
{
    use BuildsCases;
    use RefreshDatabase;

    public function test_empty_database_shows_clean_empty_state(): void
    {
        $response = $this->actingAs($this->adminUser())->get(route('dashboard'));

        $response->assertOk();

        // میانگینِ نداشته باید null بماند تا قالب «—» چاپ کند، نه صفرِ گمراه‌کننده.
        $metrics = $response->viewData('metrics');
        $this->assertNull($metrics['avg_confidence']);
        $this->assertNull($metrics['avg_processing_ms']);
        $this->assertSame(0, $metrics['scored']);
        $this->assertSame(0, $metrics['timed']);

        $this->assertTrue($response->viewData('reviewQueue')->isEmpty());
        $response->assertSee('صف بررسی انسانی خالی است');
        $response->assertSee('هنوز پرونده‌ای امتیاز نگرفته است');
    }

    public function test_status_cards_and_averages_come_from_the_database(): void
    {
        $this->seedReferenceData();
        $owner = $this->expertUser();

        PermitCase::factory()->for($owner, 'user')->create([
            'status' => 'approved',
            'confidence_score' => 90,
            'processing_ms' => 1000,
        ]);

        PermitCase::factory()->for($owner, 'user')->create([
            'status' => 'rejected',
            'confidence_score' => 30,
            'processing_ms' => 3000,
        ]);

        PermitCase::factory()->for($owner, 'user')->create([
            'status' => 'needs_review',
            'confidence_score' => 60,
            'processing_ms' => 5000,
        ]);

        // پیش‌نویس نه امتیاز دارد نه زمان پردازش؛ نباید میانگین‌ها را رقیق کند.
        PermitCase::factory()->for($owner, 'user')->create(['status' => 'draft']);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertOk();

        $stats = $response->viewData('stats');
        $this->assertSame(4, $stats['cases_total']);
        $this->assertSame(1, $stats['cases_approved']);
        $this->assertSame(1, $stats['cases_rejected']);
        $this->assertSame(1, $stats['cases_needs_review']);

        $metrics = $response->viewData('metrics');
        $this->assertSame(3, $metrics['scored']);
        $this->assertSame(3, $metrics['timed']);
        $this->assertEqualsWithDelta(60.0, $metrics['avg_confidence'], 0.01);
        $this->assertEqualsWithDelta(3000.0, $metrics['avg_processing_ms'], 0.01);
    }

    public function test_needs_review_card_matches_the_menu_counter(): void
    {
        $this->seedReferenceData();
        $owner = $this->expertUser();

        PermitCase::factory()->count(3)->for($owner, 'user')->create(['status' => 'needs_review']);
        PermitCase::factory()->for($owner, 'user')->create(['status' => 'approved']);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        // شمارندهٔ منو دقیقاً همین کوئری را می‌زند؛ دو عدد باید یکی باشند.
        $this->assertSame(
            PermitCase::where('status', 'needs_review')->count(),
            $response->viewData('stats')['cases_needs_review'],
        );
        $this->assertSame(3, $response->viewData('reviewQueueTotal'));
    }

    public function test_review_queue_puts_the_most_urgent_first(): void
    {
        $this->seedReferenceData();
        $owner = $this->expertUser();

        // آستانه‌ها از ReferenceDataSeeder: تایید ۸۰، رد ۴۵ → بازه نزدیکی = ۳۵.
        // سقف انتظار ۷۲ ساعت. امتیاز = ۶۰×نسبت انتظار + ۴۰×نزدیکی.
        $this->makeNeedsReview($owner, 'OLD-NEAR', hoursAgo: 100, score: 79);   // ~۹۹
        $this->makeNeedsReview($owner, 'OLD-FAR', hoursAgo: 100, score: 46);    // ~۶۱
        $this->makeNeedsReview($owner, 'MID-NONE', hoursAgo: 50, score: null);  // ~۴۲
        $this->makeNeedsReview($owner, 'NEW-NEAR', hoursAgo: 1, score: 79);     // ~۴۰

        $queue = $this->actingAs($owner)->get(route('dashboard'))->viewData('reviewQueue');

        $this->assertSame(
            ['OLD-NEAR', 'OLD-FAR', 'MID-NONE', 'NEW-NEAR'],
            $queue->map(fn (array $row) => $row['case']->code)->all(),
        );

        // نه فقط ترتیب — خود عدد فوریت هم باید معنادار باشد.
        $this->assertGreaterThan(90, $queue->first()['urgency']);
        $this->assertEqualsWithDelta(100.0, $queue->first()['wait_hours'], 1.0);
    }

    public function test_review_queue_is_capped_but_the_counter_is_not(): void
    {
        $this->seedReferenceData();
        $owner = $this->expertUser();

        PermitCase::factory()
            ->count(12)
            ->for($owner, 'user')
            ->create(['status' => 'needs_review', 'submitted_at' => now()->subHours(5)]);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $this->assertCount(8, $response->viewData('reviewQueue'));
        $this->assertSame(12, $response->viewData('reviewQueueTotal'));
    }

    public function test_data_expert_sees_no_case_data(): void
    {
        $this->seedReferenceData();

        $case = PermitCase::factory()->for($this->expertUser(), 'user')->create([
            'status' => 'needs_review',
            'code' => 'HA-SECRET-0001',
            'applicant_name' => 'متقاضی محرمانه',
            'submitted_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->dataUser())->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee($case->code);
        $response->assertDontSee('متقاضی محرمانه');
        $response->assertDontSee('صف بررسی انسانی');
        $response->assertDontSee('پرونده‌های اخیر');

        $this->assertTrue($response->viewData('reviewQueue')->isEmpty());
        $this->assertTrue($response->viewData('recentCases')->isEmpty());
        $this->assertFalse($response->viewData('canReviewCases'));
    }

    public function test_expert_sees_the_review_queue_rows(): void
    {
        $this->seedReferenceData();
        $owner = $this->expertUser();

        $case = $this->makeNeedsReview($owner, 'HA-VISIBLE-1', hoursAgo: 30, score: 70);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee($case->code);
        $response->assertSee('صف بررسی انسانی');
    }

    /** تعریف done تسک: ۲۰ پروندهٔ نمونه، همه اعداد درست و صفحه سالم. */
    public function test_demo_seeder_fills_the_dashboard_with_twenty_cases(): void
    {
        $this->seed(DemoCasesSeeder::class);

        $this->assertSame(20, PermitCase::count());

        $response = $this->actingAs($this->adminUser())->get(route('dashboard'));

        $response->assertOk();
        $this->assertSame(20, $response->viewData('stats')['cases_total']);
        $this->assertNotNull($response->viewData('metrics')['avg_confidence']);
        $this->assertNotNull($response->viewData('metrics')['avg_processing_ms']);
        $this->assertFalse($response->viewData('reviewQueue')->isEmpty());

        // اجرای دوباره نباید پرونده‌ها را دو برابر کند.
        $this->seed(DemoCasesSeeder::class);
        $this->assertSame(20, PermitCase::count());
    }

    /** پروندهٔ «نیاز به بررسی» با سن و امتیاز مشخص. */
    private function makeNeedsReview(User $owner, string $code, int $hoursAgo, ?float $score): PermitCase
    {
        $moment = Carbon::now()->subHours($hoursAgo);

        $case = PermitCase::factory()->for($owner, 'user')->create([
            'code' => $code,
            'status' => 'needs_review',
            'confidence_score' => $score,
            'submitted_at' => $moment,
        ]);

        $case->forceFill(['created_at' => $moment])->save();

        return $case;
    }
}
