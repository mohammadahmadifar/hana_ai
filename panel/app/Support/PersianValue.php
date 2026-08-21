<?php

namespace App\Support;

/**
 * ابزار مقادیر فارسی مدارک.
 *
 * دو کار انجام می‌دهد:
 *   ۱) یکسان‌سازی نوشتار — ارقام لاتین و عربی به ارقام فارسی، حرف «ي» و «ك»
 *      عربی به «ی» و «ک» فارسی، فاصله‌های اضافی و نیم‌فاصله‌های پراکنده.
 *      موتور پایتون ارقام فارسی چاپ می‌کند، پس هر چه از فرم می‌آید باید
 *      پیش از رفتن به موتور به همان شکل درآید وگرنه تصویر تستی با تصویر
 *      تولید انبوه یکی نمی‌شود و OCR دو رفتار متفاوت نشان می‌دهد.
 *   ۲) اعتبارسنجی بر پایه value_type فیلد (جدول document_type_fields).
 *
 * هیچ دادهٔ هویتی واقعی این‌جا نیست؛ فقط قاعدهٔ شکل مقدار.
 */
final class PersianValue
{
    /** ارقام فارسی به ترتیب صفر تا نه. */
    private const FA = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    /** ارقام لاتین. */
    private const EN = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    /** ارقام عربی (هندی) — کاربر گاهی از کیبورد عربی می‌چسباند. */
    private const AR = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    /** حروف مجاز پلاک ایران (به‌همراه حروف ویژه). */
    public const PLATE_LETTERS = [
        'الف', 'ب', 'پ', 'ت', 'ث', 'ج', 'چ', 'ح', 'خ', 'د', 'ذ', 'ر', 'ز', 'ژ',
        'س', 'ش', 'ص', 'ض', 'ط', 'ظ', 'ع', 'غ', 'ف', 'ق', 'ک', 'گ', 'ل', 'م',
        'ن', 'و', 'ه', 'ی',
    ];

    /** نوع‌های مقداری که این کلاس می‌شناسد. */
    public const VALUE_TYPES = ['text', 'digits', 'national_id', 'jalali_date', 'vin', 'plate'];

    // ------------------------------------------------------------------
    // تبدیل ارقام و یکسان‌سازی نوشتار
    // ------------------------------------------------------------------

    /** ارقام لاتین و عربی → ارقام فارسی. */
    public static function toPersianDigits(?string $value): string
    {
        $value = (string) $value;

        return str_replace(
            array_merge(self::EN, self::AR),
            array_merge(self::FA, self::FA),
            $value,
        );
    }

    /** ارقام فارسی و عربی → ارقام لاتین (برای مقایسه و regex). */
    public static function toEnglishDigits(?string $value): string
    {
        $value = (string) $value;

        return str_replace(
            array_merge(self::FA, self::AR),
            array_merge(self::EN, self::EN),
            $value,
        );
    }

    /**
     * عدد اعشاری با ارقام فارسی و جداکنندهٔ اعشار فارسی (٫)، مثل ۰٫۷۵.
     * برای نمایش شدت اعوجاج‌ها لازم است؛ نقطهٔ لاتین وسط عدد فارسی زشت است.
     */
    public static function decimal(float $value, int $decimals = 0): string
    {
        $text = number_format($value, max(0, $decimals), '.', '');

        return strtr(self::toPersianDigits($text), ['.' => '٫']);
    }

    /**
     * یکسان‌سازی پایه: حذف کاراکترهای نامرئی، عربی → فارسی، فشرده‌کردن فاصله‌ها.
     */
    public static function normalize(?string $value): string
    {
        $value = (string) $value;

        // کاراکترهای جهت‌دهی و نویسه‌های نامرئی که با کپی‌پیست وارد می‌شوند
        $value = str_replace(
            ["\u{200E}", "\u{200F}", "\u{202A}", "\u{202B}", "\u{202C}", "\u{FEFF}", "\u{00A0}"],
            ['', '', '', '', '', '', ' '],
            $value,
        );

        // نیم‌فاصله را نگه می‌داریم (بخشی از املای فارسی است) اما عربی را فارسی می‌کنیم
        $value = str_replace(['ي', 'ك', 'ۀ', 'أ', 'إ', 'ؤ'], ['ی', 'ک', 'ه', 'ا', 'ا', 'و'], $value);

        $value = preg_replace('/[ \t\r\n]+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * شکل قانونی (canonical) یک مقدار — تنها منبع حقیقت.
     *
     * خروجی همین تابع است که هم اعتبارسنجی می‌شود، هم به موتور می‌رود،
     * هم روی مدرک چاپ می‌شود و هم به‌عنوان برچسب آموزشی ذخیره می‌شود.
     *
     * پیش‌تر اعتبارسنجی جداکننده‌ها را نادیده می‌گرفت ولی این مسیر آن‌ها را
     * نگه می‌داشت، پس «۰۰۶-۹۵۳-۷۴۱۰» به‌عنوان کد ملی پذیرفته می‌شد و با
     * همان خط تیره روی کارت چاپ و ذخیره می‌شد؛ یعنی برچسب آموزشی خراب.
     *
     * قاعده: از هر نوع دقیقاً همان جداکننده‌هایی حذف می‌شود که اعتبارسنجی
     * همان نوع نادیده می‌گرفت. این تابع خودتکرار است (canonical(canonical(x)) = canonical(x)).
     *
     * VIN تنها استثناست: شمارهٔ شاسی روی کارت واقعی لاتین چاپ می‌شود و
     * ماژول app/ocr/vehicle_card_ocr.py هم لاتین می‌خواند، پس فارسی‌سازی
     * نمی‌شود و فقط بزرگ می‌شود.
     */
    public static function forEngine(string $valueType, ?string $value): string
    {
        $value = self::normalize($value);

        if ($value === '') {
            return '';
        }

        return match ($valueType) {
            'text' => $value,
            'vin' => mb_strtoupper(str_replace([' ', '-'], '', self::toEnglishDigits($value))),
            'national_id' => self::toPersianDigits(str_replace([' ', '-'], '', $value)),
            'digits' => self::toPersianDigits(str_replace(' ', '', $value)),
            'jalali_date' => self::canonicalJalaliDate($value),
            'plate' => self::canonicalPlate($value),
            default => self::toPersianDigits($value),
        };
    }

    /**
     * تاریخ شمسی به همان شکلی که ژنراتور موتور چاپ می‌کند: YYYY/MM/DD با صفر ابتدایی
     * (app/person/person_generator.py → format_date با strftime("%Y/%m/%d")).
     *
     * جداکنندهٔ «-» و «.» به «/» تبدیل و فاصله‌ها حذف می‌شوند — همان چیزی که
     * اعتبارسنجی تاریخ از قبل نادیده می‌گرفت. اگر ورودی اصلاً شکل تاریخ نداشته
     * باشد دست‌نخورده برمی‌گردد تا اعتبارسنجی خطای درست بدهد.
     */
    private static function canonicalJalaliDate(string $value): string
    {
        $plain = str_replace(['-', '.', '\\'], '/', self::toEnglishDigits($value));
        $plain = str_replace(' ', '', $plain);

        if (preg_match('/^([0-9]{4})\/([0-9]{1,2})\/([0-9]{1,2})$/', $plain, $m)) {
            $plain = $m[1].'/'
                .str_pad($m[2], 2, '0', STR_PAD_LEFT).'/'
                .str_pad($m[3], 2, '0', STR_PAD_LEFT);
        }

        return self::toPersianDigits($plain);
    }

    /**
     * پلاک به همان ترتیبی که روی کارت خودرو چاپ می‌شود: دو رقم، سه رقم، حرف، دو رقم
     * (خروجی generate_plate_number در موتور).
     *
     * اعتبارسنجی ترتیب خواندنی («۸۸ و ۵۱۱ ایران ۳۵») را هم قبول می‌کند، پس اگر
     * کاربر آن را بنویسد همین‌جا به ترتیب چاپ برگردانده می‌شود؛ وگرنه مقدار
     * چاپ‌شده با مقداری که موتور می‌شناسد یکی نمی‌شود.
     */
    private static function canonicalPlate(string $value): string
    {
        $plain = preg_replace('/\s+/u', ' ', self::toPersianDigits($value));
        $plain = trim($plain ?? $value);

        $pattern = '/^([۰-۹]{2}) ('.self::plateLetterPattern().') ([۰-۹]{3})(?: ایران)? ([۰-۹]{2})$/u';

        if (preg_match($pattern, $plain, $m)) {
            return $m[1].' '.$m[3].' '.$m[2].' '.$m[4];
        }

        return $plain;
    }

    /** الگوی regex حروف مجاز پلاک — یک جا تعریف می‌شود و دو جا استفاده. */
    private static function plateLetterPattern(): string
    {
        return implode('|', array_map(
            static fn (string $letter): string => preg_quote($letter, '/'),
            self::PLATE_LETTERS,
        ));
    }

    // ------------------------------------------------------------------
    // اعتبارسنجی
    // ------------------------------------------------------------------

    /**
     * اعتبارسنجی یک مقدار بر پایهٔ نوعش.
     *
     * @return string|null  پیام خطای فارسی، یا null اگر مقدار درست باشد
     */
    public static function validate(string $valueType, ?string $value, string $label): ?string
    {
        // دقیقاً همان شکلی اعتبارسنجی می‌شود که چاپ و ذخیره خواهد شد
        $value = self::forEngine($valueType, $value);

        if ($value === '') {
            return null; // خالی‌بودن را قانون «الزامی» جدا بررسی می‌کند
        }

        return match ($valueType) {
            'national_id' => self::validateNationalId($value, $label),
            'jalali_date' => self::validateJalaliDate($value, $label),
            'digits' => self::validateDigits($value, $label),
            'vin' => self::validateVin($value, $label),
            'plate' => self::validatePlate($value, $label),
            default => self::validateText($value, $label),
        };
    }

    private static function validateText(string $value, string $label): ?string
    {
        if (mb_strlen($value) > 60) {
            return "«{$label}» نباید بیشتر از ۶۰ نویسه باشد.";
        }

        return null;
    }

    private static function validateDigits(string $value, string $label): ?string
    {
        $plain = str_replace(' ', '', self::toEnglishDigits($value));

        if (! preg_match('/^[0-9]+$/', $plain)) {
            return "«{$label}» فقط می‌تواند رقم باشد (ارقام فارسی یا انگلیسی، بدون حرف و علامت).";
        }

        if (mb_strlen($plain) > 20) {
            return "«{$label}» نباید بیشتر از ۲۰ رقم باشد.";
        }

        return null;
    }

    /**
     * کد ملی: ده رقم + رقم کنترل.
     *
     * الگوریتم رقم کنترل — مجموع وزنی نه رقم اول با وزن‌های ۱۰ تا ۲،
     * باقیمانده بر ۱۱: اگر کمتر از ۲ بود رقم دهم باید خودِ باقیمانده باشد،
     * وگرنه باید ۱۱ منهای باقیمانده باشد. (همان قاعده‌ای که ژنراتور موتور
     * در app/person/person_generator.py رعایت می‌کند.)
     */
    private static function validateNationalId(string $value, string $label): ?string
    {
        $plain = str_replace([' ', '-'], '', self::toEnglishDigits($value));

        if (! preg_match('/^[0-9]+$/', $plain)) {
            return "«{$label}» فقط می‌تواند رقم باشد (ارقام فارسی یا انگلیسی).";
        }

        if (mb_strlen($plain) !== 10) {
            return "«{$label}» باید دقیقاً ۱۰ رقم باشد؛ الان "
                .self::toPersianDigits((string) mb_strlen($plain)).' رقم وارد شده است.';
        }

        if (! self::isValidNationalId($plain)) {
            return "«{$label}» از نظر رقم کنترل معتبر نیست؛ کد ملی وارد‌شده وجود خارجی ندارد."
                .' از دکمهٔ «تصادفی پر کن» یک کد ملی معتبر بگیرید.';
        }

        return null;
    }

    /** آیا این رشتهٔ ده‌رقمی (لاتین) کد ملی معتبر است؟ */
    public static function isValidNationalId(string $plainDigits): bool
    {
        if (! preg_match('/^[0-9]{10}$/', $plainDigits)) {
            return false;
        }

        $total = 0;

        for ($i = 0; $i < 9; $i++) {
            $total += ((int) $plainDigits[$i]) * (10 - $i);
        }

        $remainder = $total % 11;
        $check = (int) $plainDigits[9];

        return $remainder < 2
            ? $check === $remainder
            : $check === (11 - $remainder);
    }

    /**
     * تاریخ شمسی به شکل YYYY/MM/DD.
     *
     * ماه ۱ تا ۶ سی‌ویک روز، ۷ تا ۱۱ سی روز، اسفند ۲۹ روز و در سال کبیسه ۳۰ روز.
     */
    private static function validateJalaliDate(string $value, string $label): ?string
    {
        $plain = str_replace(['-', '.', '\\'], '/', self::toEnglishDigits($value));
        $plain = str_replace(' ', '', $plain);

        if (! preg_match('/^([0-9]{4})\/([0-9]{1,2})\/([0-9]{1,2})$/', $plain, $m)) {
            return "«{$label}» باید به شکل سال/ماه/روز باشد، مثل ۱۳۷۵/۰۴/۲۱.";
        }

        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if ($year < 1200 || $year > 1500) {
            return "سالِ «{$label}» باید بین ۱۲۰۰ تا ۱۵۰۰ باشد.";
        }

        if ($month < 1 || $month > 12) {
            return "ماهِ «{$label}» باید بین ۱ تا ۱۲ باشد.";
        }

        $maxDay = self::jalaliMonthLength($year, $month);

        if ($day < 1 || $day > $maxDay) {
            return "روزِ «{$label}» باید بین ۱ تا "
                .self::toPersianDigits((string) $maxDay)
                .' باشد (ماه '.self::toPersianDigits((string) $month).' این سال '
                .self::toPersianDigits((string) $maxDay).' روز دارد).';
        }

        return null;
    }

    /** تعداد روزهای یک ماه شمسی. */
    public static function jalaliMonthLength(int $year, int $month): int
    {
        if ($month <= 6) {
            return 31;
        }

        if ($month <= 11) {
            return 30;
        }

        return self::isJalaliLeapYear($year) ? 30 : 29;
    }

    /**
     * سال کبیسهٔ شمسی — همان قاعدهٔ کتابخانهٔ jdatetime که موتور استفاده می‌کند،
     * تا اعتبارسنجی پنل با تاریخ‌های تولیدشدهٔ موتور اختلاف پیدا نکند.
     */
    public static function isJalaliLeapYear(int $year): bool
    {
        return in_array($year % 33, [1, 5, 9, 13, 17, 22, 26, 30], true);
    }

    /** شمارهٔ شاسی: ۱۷ نویسهٔ الفبایی‌عددی. */
    private static function validateVin(string $value, string $label): ?string
    {
        $plain = mb_strtoupper(str_replace([' ', '-'], '', self::toEnglishDigits($value)));

        if (! preg_match('/^[A-Z0-9]{17}$/', $plain)) {
            $length = mb_strlen($plain);

            return "«{$label}» باید دقیقاً ۱۷ نویسهٔ لاتین یا رقم باشد (مثل NAS123456M7654321)؛"
                .' الان '.self::toPersianDigits((string) $length).' نویسه وارد شده است.';
        }

        return null;
    }

    /**
     * پلاک: دو رقم + سه رقم + حرف + دو رقم (همان ترتیبی که ژنراتور موتور
     * می‌سازد و روی قالب چاپ می‌شود)، یا ترتیب خواندنیِ دو رقم + حرف +
     * سه رقم + [ایران] + دو رقم.
     */
    private static function validatePlate(string $value, string $label): ?string
    {
        $plain = self::canonicalPlate($value);

        $letters = self::plateLetterPattern();

        $generatorOrder = '/^[۰-۹]{2} [۰-۹]{3} (?:'.$letters.') [۰-۹]{2}$/u';
        $readingOrder = '/^[۰-۹]{2} (?:'.$letters.') [۰-۹]{3}(?: ایران)? [۰-۹]{2}$/u';

        if (preg_match($generatorOrder, $plain) || preg_match($readingOrder, $plain)) {
            return null;
        }

        return "«{$label}» با الگوی پلاک نمی‌خواند. الگوی درست: دو رقم، سه رقم، حرف، دو رقم —"
            .' مثل «۸۸ ۵۱۱ و ۳۵». حرف پلاک باید یکی از حروف مجاز باشد.';
    }

    // ------------------------------------------------------------------
    // مقایسه برای بررسی نتیجهٔ OCR
    // ------------------------------------------------------------------

    /**
     * ساده‌سازی یک رشته برای «آیا در متن OCR پیدا شد؟».
     *
     * OCR فارسی فاصله‌ها را جابه‌جا می‌کند و گاهی جداکننده‌ها را می‌خورد،
     * پس هر دو طرف مقایسه به ارقام لاتین، حروف کوچک و بدون فاصله و
     * بدون علائم تبدیل می‌شوند.
     */
    public static function compareKey(?string $value): string
    {
        $value = self::normalize($value);
        $value = self::toEnglishDigits($value);
        $value = mb_strtolower($value);

        return preg_replace('/[^0-9a-z\p{Arabic}]+/u', '', $value) ?? $value;
    }

    /** آیا مقدار مورد انتظار داخل متن OCR دیده می‌شود؟ */
    public static function foundInText(?string $expected, ?string $haystack): bool
    {
        $needle = self::compareKey($expected);

        if ($needle === '') {
            return false;
        }

        return str_contains(self::compareKey($haystack), $needle);
    }
}
