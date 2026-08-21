<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCases;
use Tests\TestCase;

/**
 * تست دود: پایه‌های مشترک سالم‌اند.
 *
 * جای تست نمونهٔ اسکلت لاراول را گرفته که «/» را ۲۰۰ انتظار داشت؛ در این
 * سامانه ثبت‌نام عمومی نداریم و «/» مهمان را به صفحهٔ ورود می‌فرستد.
 */
class SmokeTest extends TestCase
{
    use BuildsCases;
    use RefreshDatabase;

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_signed_in_user_reaches_dashboard(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }

    public function test_reference_data_and_case_factories_work_together(): void
    {
        $this->seedReferenceData();

        $issue = $this->makeCaseWithDocuments('issue');
        $renew = $this->makeCaseWithDocuments('renew');

        // قلب تسک ۶۲۹: فهرست مدارک از دیتابیس می‌آید و به نوع خدمت وابسته است.
        $this->assertCount(3, $issue->documents);
        $this->assertCount(4, $renew->documents);

        $this->assertSame(
            ['national_card', 'driving_license', 'vehicle_card', 'previous_permit'],
            $renew->serviceType->documentTypes->pluck('key')->all(),
        );
    }

    public function test_image_helper_tells_sharp_from_blurry(): void
    {
        // تسک ۶۳۰ روی همین دو حالت تست می‌گیرد، پس باید مطمئن باشیم «تار»
        // واقعاً تار است؛ معیار: واریانس اختلاف افقی پیکسل‌ها (لبه‌ها).
        $sharp = $this->makeImageFile(look: 'sharp');
        $blurry = $this->makeImageFile(look: 'blurry');

        $this->assertGreaterThan($this->edgeVariance($blurry) * 3, $this->edgeVariance($sharp));

        @unlink($sharp);
        @unlink($blurry);
    }

    /** واریانس اختلاف پیکسل‌های همسایه روی یک نمونهٔ سطری — سنجهٔ ارزان «وضوح». */
    private function edgeVariance(string $path): float
    {
        $image = imagecreatefrompng($path);
        $width = imagesx($image);
        $height = imagesy($image);

        $deltas = [];

        for ($y = 0; $y < $height; $y += 3) {
            for ($x = 1; $x < $width; $x += 3) {
                $left = imagecolorat($image, $x - 1, $y) & 0xFF;
                $here = imagecolorat($image, $x, $y) & 0xFF;
                $deltas[] = abs($here - $left);
            }
        }

        imagedestroy($image);

        $mean = array_sum($deltas) / max(1, count($deltas));
        $sum = 0.0;

        foreach ($deltas as $delta) {
            $sum += ($delta - $mean) ** 2;
        }

        return $sum / max(1, count($deltas));
    }
}
