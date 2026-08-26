<?php

namespace Tests\Unit;

use App\Support\PersianValue;
use PHPUnit\Framework\TestCase;

/**
 * قالب پلاک — تسک ۶۳۷.
 *
 * پیش از این تسک، ژنراتور موتور «۱۲ ۳۴۵ ب ۶۷» چاپ می‌کرد ولی format_plate
 * خروجی OCR را «۱۲ ب ۳۴۵ ۶۷» می‌چید؛ یعنی دقت این فیلد ساختاراً صفر بود.
 * حالا هر دو طرف قالب رسمی «۱۲ ب ۳۴۵ ایران ۶۷» می‌دهند و پنل هم باید همان را
 * چاپ و ذخیره کند، وگرنه «ساخت تصویر تستی» دوباره ناهماهنگ می‌شود.
 */
class PlateFormatTest extends TestCase
{
    public function test_official_format_survives_round_trip(): void
    {
        $this->assertSame(
            '۱۲ ب ۳۴۵ ایران ۶۷',
            PersianValue::forEngine('plate', '۱۲ ب ۳۴۵ ایران ۶۷'),
        );
    }

    public function test_missing_iran_word_is_filled_in(): void
    {
        $this->assertSame(
            '۱۲ ب ۳۴۵ ایران ۶۷',
            PersianValue::forEngine('plate', '۱۲ ب ۳۴۵ ۶۷'),
        );
    }

    /** مقدارهای ذخیره‌شدهٔ پیش از تسک ۶۳۷ نباید بی‌صدا رد شوند. */
    public function test_old_engine_print_order_is_migrated(): void
    {
        $this->assertSame(
            '۱۲ ب ۳۴۵ ایران ۶۷',
            PersianValue::forEngine('plate', '۱۲ ۳۴۵ ب ۶۷'),
        );
    }

    public function test_latin_digits_are_persianised(): void
    {
        $this->assertSame(
            '۱۲ ب ۳۴۵ ایران ۶۷',
            PersianValue::forEngine('plate', '12 ب 345 ایران 67'),
        );
    }

    public function test_canonical_is_idempotent(): void
    {
        $once = PersianValue::forEngine('plate', '۱۲ ۳۴۵ ب ۶۷');

        $this->assertSame($once, PersianValue::forEngine('plate', $once));
    }

    public function test_valid_plates_pass_validation(): void
    {
        foreach (['۱۲ ب ۳۴۵ ایران ۶۷', '۱۲ ب ۳۴۵ ۶۷', '۱۲ ۳۴۵ ب ۶۷', '۸۸ الف ۵۱۱ ایران ۳۵'] as $plate) {
            $this->assertNull(
                PersianValue::validate('plate', $plate, 'شماره پلاک'),
                "این پلاک باید معتبر باشد: {$plate}",
            );
        }
    }

    /**
     * تکه‌های نمایش گرافیکی (تسک ۷۴۱) از همان قالب رسمی درمی‌آیند.
     *
     * ورودی‌های زیر همه یک پلاک‌اند و باید یک نتیجه بدهند، وگرنه نمایش پلاک و
     * اعتبارسنجی پلاک دو تعریف متفاوت از «درست» پیدا می‌کنند.
     */
    public function test_plate_parts_come_from_the_official_format(): void
    {
        foreach (['۱۲ ب ۳۴۵ ایران ۶۷', '۱۲ ب ۳۴۵ ۶۷', '۱۲ ۳۴۵ ب ۶۷', '12 ب 345 ایران 67'] as $plate) {
            $this->assertSame(
                ['digits' => '۱۲', 'letter' => 'ب', 'serial' => '۳۴۵', 'province' => '۶۷'],
                PersianValue::plateParts($plate),
                "تکه‌های این پلاک باید یکی باشند: {$plate}",
            );
        }
    }

    /** حرف چندنویسه‌ای هم باید سالم دربیاید، نه بریده. */
    public function test_multi_letter_plate_letter_is_kept_whole(): void
    {
        $this->assertSame('الف', PersianValue::plateParts('۸۸ الف ۵۱۱ ایران ۳۵')['letter']);
    }

    /**
     * مقداری که با الگو نمی‌خواند null می‌دهد تا ویو به متن خام برگردد.
     *
     * کادرِ پلاکِ نصفه‌کاره به کارشناس می‌گوید «این را خواندیم»، در حالی که
     * نخوانده‌ایم — بدتر از نشان‌دادن همان متن ناخوانا.
     */
    public function test_unreadable_values_have_no_parts(): void
    {
        foreach (['', null, 'بدون پلاک', '۱۲ ب ۳۴ ایران ۶۷', '۱۲۳۴۵۶۷'] as $plate) {
            $this->assertNull(
                PersianValue::plateParts($plate),
                'این مقدار نباید تکه‌های پلاک بدهد: '.var_export($plate, true),
            );
        }
    }

    public function test_broken_plates_are_rejected_with_an_actionable_message(): void
    {
        foreach (['12 b 345 67', '۱۲ ب ۳۴ ایران ۶۷', 'بدون پلاک', '۱۲۳۴۵۶۷'] as $plate) {
            $message = PersianValue::validate('plate', $plate, 'شماره پلاک');

            $this->assertNotNull($message, "این پلاک باید رد شود: {$plate}");
            $this->assertStringContainsString('۱۲ ب ۳۴۵ ایران ۶۷', $message);
        }
    }
}
