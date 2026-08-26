<?php

use App\Support\PersianValue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تسک ۷۴۰ — نام کاربری ورود، کد ملی است نه ایمیل.
 *
 * سه تصمیم که شکستنشان ساکت است:
 *
 * ۱) مقدار با **ارقام لاتین** ذخیره می‌شود، نه فارسی. کاربر ممکن است با
 *    صفحه‌کلید فارسی «۰۰۱۱۱۱۱۱۱۹» بنویسد و دفعهٔ بعد با لاتین؛ اگر ستون هر دو
 *    شکل را بپذیرد، همان یک نفر دو حساب می‌شود و ورودش قرعه‌کشی است. ورودیِ
 *    ورود و فرم کاربر هر دو پیش از جست‌وجو به لاتین تبدیل می‌شوند.
 *
 * ۲) ستون nullable است ولی هیچ مسیری آن را خالی نمی‌گذارد: فرم ساخت و ویرایش
 *    کاربر اجباری‌اش کرده و seeder پرش می‌کند. nullable بودن فقط برای این است
 *    که این مهاجرت روی دیتابیسِ پرِ موجود بدون قفل‌شدن اجرا شود — ولی همان
 *    ردیف‌های موجود هم همین‌جا پر می‌شوند، پس بعد از اجرا هیچ ردیف خالی نمی‌ماند.
 *    حساب بدون کد ملی یعنی کاربری که هرگز نمی‌تواند وارد شود.
 *
 * ۳) کد ملیِ حساب‌های دموی چهارگانه ثابت و معلوم است (همان چیزی که
 *    DatabaseSeeder می‌نویسد)، وگرنه همین مهاجرت محمد را از پنل بیرون می‌انداخت.
 *    بقیهٔ ردیف‌ها کدی از روی شناسه‌شان می‌گیرند: تکراری نمی‌شود، بازتولیدپذیر
 *    است، و با پیشوند ۹ از کدهای دمو جدا می‌ماند.
 *
 * همهٔ این کدها مصنوعی‌اند و رقم کنترلشان درست است — هیچ کد ملی واقعی وارد
 * پروژه نمی‌شود (قانون ۱۲۹).
 */
return new class extends Migration
{
    /** کد ملی ثابت حساب‌های دمو — آینهٔ DatabaseSeeder. */
    private const DEMO = [
        'admin@hana.local' => '0011111119',
        'expert@hana.local' => '0022222227',
        'data@hana.local' => '0033333335',
        'applicant@hana.local' => '0044444443',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('national_id', 10)->nullable()->after('email');
        });

        $this->backfill();

        Schema::table('users', function (Blueprint $table) {
            $table->unique('national_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['national_id']);
            $table->dropColumn('national_id');
        });
    }

    /**
     * پر کردن ردیف‌های موجود — پیش از ساختن ایندکس یکتا.
     *
     * دو چیز این‌جا عمدی است:
     *
     * ۱) داخل تراکنش. اگر وسط کار چیزی بترکد، نیمی از جدول پر و نیمی خالی
     *    می‌ماند، ایندکس یکتا ساخته نمی‌شود، و خودِ مهاجرت هم ثبت نمی‌شود —
     *    یعنی اجرای بعدی روی ستونی که از قبل هست می‌خورد. یا همه یا هیچ‌کدام.
     *
     * ۲) کدِ هر حسابِ غیردمو **چاپ می‌شود**. آن حساب‌ها کد را از روی شناسه‌شان
     *    می‌گیرند و هیچ‌جای دیگری — نه رابط کاربری، نه لاگ — نشانش نمی‌دهد؛
     *    بدون این چاپ، مدیرِ یک نصبِ واقعی بعد از مهاجرت نمی‌داند با چه چیزی
     *    وارد شود و باید مستقیم از دیتابیس بخواند. همان اسم کاربری است، نه رمز.
     */
    private function backfill(): void
    {
        $assigned = [];

        DB::transaction(function () use (&$assigned): void {
            foreach (DB::table('users')->select('id', 'email')->orderBy('id')->get() as $user) {
                $nationalId = self::DEMO[$user->email] ?? self::syntheticFor((int) $user->id);

                DB::table('users')->where('id', $user->id)->update(['national_id' => $nationalId]);

                if (! isset(self::DEMO[$user->email])) {
                    $assigned[] = $user->email.' → '.$nationalId;
                }
            }
        });

        if ($assigned !== []) {
            echo PHP_EOL.'  نام کاربری تازه (کد ملی) برای حساب‌های موجود — با همین‌ها وارد شوید:'.PHP_EOL;

            foreach ($assigned as $line) {
                echo '    '.$line.PHP_EOL;
            }

            echo PHP_EOL;
        }
    }

    /**
     * کد ملی مصنوعیِ معتبر از روی شناسهٔ کاربر — بازتولیدپذیر و بی‌تکرار.
     *
     * نُه رقم اول «۹» به‌علاوهٔ شناسهٔ هشت‌رقمی است، پس هرگز با کدهای دمو
     * (که با ۰۰ شروع می‌شوند) برخورد نمی‌کند.
     */
    private static function syntheticFor(int $id): string
    {
        // str_pad کوتاه نمی‌کند: شناسهٔ نه‌رقمی رشتهٔ ده‌رقمی می‌سازد و بعد هیچ
        // رقم کنترلی جور درنمی‌آید. صریح می‌ترکد، آن هم داخل تراکنش، نه اینکه
        // نیمهٔ جدول را پر بگذارد.
        if ($id <= 0 || $id > 99_999_999) {
            throw new RuntimeException(
                'شناسهٔ کاربر '.$id.' در الگوی کد ملی مصنوعی («۹» + هشت رقم) جا نمی‌شود؛ '
                .'کد ملی این ردیف‌ها را دستی بنویسید و بعد مهاجرت را اجرا کنید.'
            );
        }

        $nine = '9'.str_pad((string) $id, 8, '0', STR_PAD_LEFT);

        for ($check = 0; $check <= 9; $check++) {
            if (PersianValue::isValidNationalId($nine.$check)) {
                return $nine.$check;
            }
        }

        // رقم کنترل همیشه یکتاست، پس این خط اجرا نمی‌شود؛ ولی برگرداندن مقدار
        // نامعتبر بهتر از شکستن مهاجرت نیست — پس صریح می‌ترکد.
        throw new RuntimeException('رقم کنترل کد ملی مصنوعی برای کاربر '.$id.' پیدا نشد.');
    }
};
