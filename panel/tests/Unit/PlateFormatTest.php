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

    public function test_broken_plates_are_rejected_with_an_actionable_message(): void
    {
        foreach (['12 b 345 67', '۱۲ ب ۳۴ ایران ۶۷', 'بدون پلاک', '۱۲۳۴۵۶۷'] as $plate) {
            $message = PersianValue::validate('plate', $plate, 'شماره پلاک');

            $this->assertNotNull($message, "این پلاک باید رد شود: {$plate}");
            $this->assertStringContainsString('۱۲ ب ۳۴۵ ایران ۶۷', $message);
        }
    }
}
