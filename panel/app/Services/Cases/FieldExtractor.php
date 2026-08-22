<?php

namespace App\Services\Cases;

use App\Models\CaseDocument;
use App\Models\DocumentType;
use App\Models\DocumentTypeField;
use App\Models\ExtractedField;
use App\Models\OcrRun;
use App\Services\Cases\Extraction\Candidate;
use App\Services\Cases\Extraction\DateReader;
use App\Services\Cases\Extraction\LabelBook;
use App\Services\Cases\Extraction\LabelLocator;
use App\Services\Cases\Extraction\NameReader;
use App\Services\Cases\Extraction\NationalIdReader;
use App\Services\Cases\Extraction\OcrText;
use App\Services\Cases\Extraction\VehicleReader;
use App\Support\PersianValue;

/**
 * مرحلهٔ سوم فرایند مجوز: از متن خام OCR فیلدهای ساخت‌یافته درمی‌آید.
 *
 * ورودی متنی است که Tesseract از روی یک مدرک اسکن‌شده داده و پر از خرابی است:
 * برچسب‌ها نصفه‌نیمه («تاریخ تولد» → «ترجج توند»)، ارقام اضافه (کد ملی ده‌رقمی
 * به‌شکل شانزده رقم)، سطرهای آشغال و گاهی یک سطرِ کامل غایب.
 *
 * سه لایه روی هم کار می‌کنند و هر کدام اطمینان خودش را دارد:
 *
 *   ۱) **برچسب** — «شماره ملی ۸۱۴۳۰۳۷۳۸۱». دقیق یا فازی (LabelLocator).
 *   ۲) **چیدمان** — ترتیب سطرها همان ترتیب چاپ فیلدهاست. روی کارت ملی نام
 *      همیشه سطر بعدِ کد ملی است، حتی وقتی برچسبش کاملاً از بین رفته.
 *   ۳) **شکل مقدار** — رقم کنترل کد ملی، تقویم شمسی، ۱۷ نویسهٔ VIN، الگوی
 *      پلاک. این لایه است که می‌گوید مقداری که پیدا کردیم «می‌تواند درست باشد».
 *
 * ### اطمینان (۰ تا ۱۰۰)
 * ورودی مستقیم تسک امتیازدهی است، پس عمداً از دو تکه ساخته می‌شود:
 *   پایه = مقدار **از کجا** آمد (extra موتور > برچسب دقیق > برچسب فازی >
 *          چیدمان > حدس شکلی)
 *   افزودنی = مقدار **چقدر درست‌شکل** است (رقم کنترل، تقویم، طول، الگو)
 * هر ترمیمی که لازم شده (حذف رقم اضافه، بریدن روز سه‌رقمی) امتیاز کم می‌کند.
 *
 * ### قواعد نوشتن
 * اجرای دوباره روی همان مدرک ردیف تکراری نمی‌سازد و — مهم‌تر — فیلدی که
 * کارشناس دستی اصلاح کرده (`source = manual`) هرگز بازنویسی نمی‌شود.
 */
final class FieldExtractor
{
    /** پایهٔ اطمینان بر پایهٔ منبع مقدار. */
    private const FROM_ENGINE_EXTRA = 60.0;

    private const FROM_EXACT_LABEL = 50.0;

    private const FROM_FUZZY_LABEL = 38.0;

    private const FROM_LAYOUT = 30.0;

    private const FROM_SHAPE = 20.0;

    /**
     * چیدمان سطرهای «نام» روی هر نوع مدرک، نسبت به لنگرها.
     *
     * anchor: nid = سطر کد ملی ، date = سطر اولین تاریخ
     *
     * `below` یعنی روی این قالب مقدار **زیر** برچسبش چاپ می‌شود، نه کنارش.
     * تنها گواهینامه این‌طور است: «نام و نام خانوادگی» در یک سطر و خودِ نام
     * در سطر بعد. بدون این پرچم، هر دو منبعِ readName (چیدمان و برچسب) روی
     * همان سطرِ برچسب می‌افتند که هیچ نامی ندارد و فیلد خالی می‌ماند — روی
     * ۲۵ نمونه، ۱۱ تا از ۲۵ نام گواهینامه دقیقاً به همین دلیل خالی بود، در
     * حالی که OCR نام را درست خوانده بود و یک سطر پایین‌تر نشسته بود.
     *
     * عمداً پرچمِ هر فیلد است نه رفتار پیش‌فرض: روی کارت ملی «نام» و «نام
     * خانوادگی» سطرهای پشت سر هم‌اند، پس اگر «نام» به سطر بعد سُر بخورد،
     * نام خانوادگی را به‌جای نام برمی‌دارد.
     *
     * @var array<string, array<string, array{anchor: string, offset: int, tokens: int, below?: bool}>>
     */
    private const NAME_LAYOUT = [
        'national_card' => [
            'first_name' => ['anchor' => 'nid', 'offset' => 1, 'tokens' => 1],
            'last_name' => ['anchor' => 'nid', 'offset' => 2, 'tokens' => 2],
            'father_name' => ['anchor' => 'date', 'offset' => 1, 'tokens' => 2],
        ],
        'driving_license' => [
            'full_name' => ['anchor' => 'nid', 'offset' => 1, 'tokens' => 3, 'below' => true],
        ],
        'vehicle_card' => [
            'full_name' => ['anchor' => 'nid', 'offset' => -1, 'tokens' => 2],
            'father_name' => ['anchor' => 'nid', 'offset' => 1, 'tokens' => 2],
        ],
    ];

    /**
     * فیلدهای یک مدرک را در extracted_fields می‌نویسد.
     *
     * @return int تعداد فیلدی که نوشته یا به‌روز شد
     */
    public function extract(CaseDocument $document, OcrRun $run): int
    {
        $type = $document->documentType;

        if ($type === null) {
            return 0;
        }

        $fields = $this->fieldsFromVariants(
            $type,
            self::variantsOf($run),
        );

        $touched = [];
        $written = 0;

        foreach ($fields as $key => $field) {
            if ($field['normalized'] === null || $field['normalized'] === '') {
                continue;
            }

            $existing = ExtractedField::query()
                ->where('case_id', $document->case_id)
                ->where('case_document_id', $document->id)
                ->where('field_key', $key)
                ->first();

            // اصلاح دستی کارشناس حرف آخر را می‌زند؛ OCR رویش نمی‌نویسد
            if ($existing !== null && $existing->source === 'manual') {
                $touched[] = $key;

                continue;
            }

            $row = $existing ?? new ExtractedField([
                'case_id' => $document->case_id,
                'case_document_id' => $document->id,
                'field_key' => $key,
            ]);

            $row->fill([
                'case_id' => $document->case_id,
                'case_document_id' => $document->id,
                'field_key' => $key,
                'raw_value' => $field['raw'],
                'normalized_value' => $field['normalized'],
                'confidence' => $field['confidence'],
                'source' => 'ocr',
            ])->save();

            $touched[] = $key;
            $written++;
        }

        // ردیف‌های بیاتِ اجرای قبلی که این بار پیدا نشدند؛ اصلاح دستی دست‌نخورده می‌ماند.
        //
        // اگر این اجرا **هیچ** فیلدی نداد (متن خام خالی یا خرابِ یک OCR ناموفق)،
        // هیچ ردیفی پاک نمی‌شود. قبلاً شرط whereNotIn در این حالت غیرفعال می‌شد و
        // delete بی‌قید همهٔ فیلدهای درستِ اجرای قبلی را می‌برد؛ یعنی یک اجرای
        // ناموفق OCR دادهٔ سالم را نابود می‌کرد و مدرک از passed به failed می‌رفت.
        // «چیزی پیدا نشد» شاهدِ «قبلی‌ها دیگر معتبر نیستند» نیست.
        if ($touched !== []) {
            ExtractedField::query()
                ->where('case_id', $document->case_id)
                ->where('case_document_id', $document->id)
                ->where('source', 'ocr')
                ->whereNotIn('field_key', $touched)
                ->delete();
        }

        return $written;
    }

    /**
     * نسخه‌های متن یک اجرای OCR — همیشه دست‌کم یکی.
     *
     * موتور از تسک ۶۶۲ همان مدرک را در چند بزرگ‌نمایی می‌خواند و متن‌ها را در
     * `extra.variants` می‌گذارد. اجراهای قدیمی این کلید را ندارند، پس
     * `raw_text` تنها نسخه می‌شود و رفتار دقیقاً مثل قبل می‌ماند.
     *
     * نسخهٔ اول همیشه اولِ فهرست است تا رأی مساوی به نفع مقیاس مرجع بشکند.
     *
     * @return list<array{raw_text: string, extra: array<string, mixed>}>
     */
    public static function variantsOf(OcrRun $run): array
    {
        $extra = is_array($run->extra) ? $run->extra : [];
        $variants = is_array($extra['variants'] ?? null) ? $extra['variants'] : [];

        // کلید variants خودش داخل هر نسخه معنا ندارد
        unset($extra['variants']);

        $out = [];
        $seen = [];

        foreach (array_merge([['raw_text' => (string) $run->raw_text] + $extra], $variants) as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $rawText = (string) ($variant['raw_text'] ?? '');

            // موتور نسخه‌ها را تخت می‌دهد (`vin`) و DocumentOcr هم تخت ذخیره
            // می‌کند، ولی هر مسیری که خروجی خام موتور را مستقیم بدهد شکل
            // تودرتو دارد (`extra.vin`). هر دو را می‌فهمیم تا VIN بی‌صدا گم نشود.
            $nested = is_array($variant['extra'] ?? null) ? $variant['extra'] : [];

            $extraOf = [
                'vin' => $variant['vin'] ?? $nested['vin'] ?? null,
                'plate' => $variant['plate'] ?? $nested['plate'] ?? null,
            ];

            // متن خالی به‌تنهایی دلیل دور انداختن نسخه نیست: مسیر ویژهٔ کارت
            // خودرو VIN و پلاک را از برشِ خودش می‌خواند، نه از متن صفحه. اگر
            // این‌جا رد می‌شد، مدرکی که صفحه‌اش خوانده نشده ولی شاسی‌اش خوانده
            // شده، شاسی‌اش را هم از دست می‌داد.
            if (trim($rawText) === '' && $extraOf['vin'] === null && $extraOf['plate'] === null) {
                continue;
            }

            // موتور نسخهٔ اول را هم داخل variants می‌گذارد و هم در raw_text؛
            // دوباره خواندنش فقط وقت می‌برد. کلید یکتایی، vin و پلاک را هم
            // در بر می‌گیرد چون آن‌ها از برشِ جدا می‌آیند و می‌توانند بین دو
            // بزرگ‌نمایی فرق کنند حتی وقتی متن صفحه مو‌به‌مو یکی است.
            $key = md5($rawText.'|'.($extraOf['vin'] ?? '').'|'.($extraOf['plate'] ?? ''));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $out[] = ['raw_text' => $rawText, 'extra' => $extraOf];
        }

        return $out === [] ? [['raw_text' => '', 'extra' => []]] : $out;
    }

    /**
     * همان `fieldsFromText`، ولی روی چند نسخه از متن همان مدرک.
     *
     * ### چرا انتخاب فیلدبه‌فیلد است، نه انتخاب «بهترین متن»
     * هیچ بزرگ‌نمایی‌ای برای همهٔ فیلدها بهترین نیست. روی همان کارت ملیِ
     * پروندهٔ ۲۷۲، مقیاس ۱.۰ کد ملی را درست می‌خواند و تاریخ تولد را غلط،
     * و مقیاس ۱.۲۵ برعکس. پس اگر یک متن را «برنده» اعلام کنیم، به‌ازای هر
     * فیلدی که می‌بریم یکی را می‌بازیم. اندازه‌گیری روی ۷۵ نمونه: اجتماع
     * سه مقیاس تطابق کامل را از ۷۴.۸٪ به ۸۳.۵٪ می‌برد.
     *
     * ### داورِ انتخاب همان اطمینان است
     * `fieldsFromText` برای هر فیلد عددی می‌دهد که نیمی‌اش «مقدار از کجا آمد»
     * است و نیمی‌اش «چقدر درست‌شکل است» (رقم کنترل کد ملی، تقویم شمسی معتبر،
     * طول محتمل، الگوی پلاک). دقیقاً همین نیمهٔ دوم است که مقدار درست را از
     * مقدارِ خوش‌ظاهرِ غلط جدا می‌کند، پس بیشینهٔ اطمینان داور درستی است.
     * تساوی به نفع نسخهٔ اول (مقیاس مرجع) شکسته می‌شود.
     *
     * @param  list<array{raw_text: string, extra?: array<string, mixed>}>  $variants
     * @return array<string, array{raw: ?string, normalized: ?string, confidence: float}>
     */
    public function fieldsFromVariants(DocumentType $type, array $variants): array
    {
        $best = [];

        foreach ($variants as $variant) {
            $fields = $this->fieldsFromText(
                $type,
                (string) ($variant['raw_text'] ?? ''),
                is_array($variant['extra'] ?? null) ? $variant['extra'] : [],
            );

            foreach ($fields as $key => $field) {
                $found = $field['normalized'] !== null && $field['normalized'] !== '';

                // فیلدی که هیچ نسخه‌ای پیدایش نکرده هم باید در خروجی باشد
                // (با مقدار null)، وگرنه قرارداد fieldsFromText می‌شکند.
                if (! isset($best[$key])) {
                    $best[$key] = $field;

                    continue;
                }

                $current = $best[$key];
                $currentFound = $current['normalized'] !== null && $current['normalized'] !== '';

                if (! $found) {
                    continue;
                }

                if (! $currentFound || $field['confidence'] > $current['confidence']) {
                    $best[$key] = $field;
                }
            }
        }

        return $best;
    }

    /**
     * هستهٔ خالص و بدون دیتابیس — تست و اندازه‌گیری روی دیتاست از همین می‌آید.
     *
     * @param  array<string, mixed>  $extra  همان ocr_runs.extra (کلیدهای vin و plate)
     * @return array<string, array{raw: ?string, normalized: ?string, confidence: float}>
     */
    public function fieldsFromText(DocumentType $type, string $rawText, array $extra = []): array
    {
        $typeKey = (string) $type->key;
        $text = new OcrText($rawText);

        /** @var list<DocumentTypeField> $fields */
        $fields = $type->relationLoaded('fields')
            ? $type->fields->all()
            : $type->fields()->get()->all();

        usort($fields, static fn (DocumentTypeField $a, DocumentTypeField $b): int => ($a->sort ?? 0) <=> ($b->sort ?? 0));

        $anchors = $this->anchors($text, $typeKey, $fields);
        $dates = $this->readDates($text, $typeKey, $fields);

        $out = [];

        foreach ($fields as $field) {
            $key = (string) $field->key;
            $valueType = (string) $field->value_type;

            $candidate = match ($valueType) {
                'national_id' => $this->readNationalId($text, $typeKey, $key, $anchors),
                'jalali_date' => $dates[$key] ?? Candidate::none('date'),
                'vin' => $this->readVin($text, $typeKey, $key, $extra),
                'plate' => $this->readPlate($text, $extra),
                'digits' => $this->readDigits($text, $typeKey, $key, $anchors),
                default => $this->readName($text, $typeKey, $key, $anchors),
            };

            $candidate = $candidate->clamped();

            $out[$key] = [
                'raw' => $candidate->found() ? $candidate->raw : null,
                'normalized' => $candidate->found()
                    ? PersianValue::forEngine($valueType, $candidate->value)
                    : null,
                'confidence' => round($candidate->confidence, 2),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // لنگرهای چیدمان
    // ------------------------------------------------------------------

    /**
     * دو لنگری که بقیهٔ سطرها نسبت به آن‌ها شمرده می‌شوند.
     *
     * @param  list<DocumentTypeField>  $fields
     * @return array{nid: ?int, date: ?int}
     */
    private function anchors(OcrText $text, string $typeKey, array $fields): array
    {
        $nid = null;

        foreach ($fields as $field) {
            if ($field->value_type !== 'national_id') {
                continue;
            }

            $hit = LabelLocator::find($text, LabelBook::labels($typeKey, (string) $field->key));
            $nid = $hit['line'] ?? null;

            break;
        }

        // اگر برچسب کد ملی هم خراب بود، سطری که بلندترین رشتهٔ رقم را دارد لنگر می‌شود
        if ($nid === null) {
            $longest = 0;

            for ($i = 0; $i < $text->count(); $i++) {
                foreach (OcrText::digitRuns((string) $text->latin($i), 10) as $run) {
                    if (strlen($run) > $longest) {
                        $longest = strlen($run);
                        $nid = $i;
                    }
                }
            }
        }

        $date = null;

        for ($i = 0; $i < $text->count(); $i++) {
            if (OcrText::dateMatches((string) $text->latin($i)) !== []) {
                $date = $i;

                break;
            }
        }

        return ['nid' => $nid, 'date' => $date];
    }

    // ------------------------------------------------------------------
    // کد ملی
    // ------------------------------------------------------------------

    /** @param  array{nid: ?int, date: ?int}  $anchors */
    private function readNationalId(OcrText $text, string $typeKey, string $fieldKey, array $anchors): Candidate
    {
        $hit = LabelLocator::find($text, LabelBook::labels($typeKey, $fieldKey));

        if ($hit !== null) {
            $base = $hit['exact'] ? self::FROM_EXACT_LABEL : self::FROM_FUZZY_LABEL;
            $runs = OcrText::digitRuns((string) $text->latin($hit['line']), 10);
        } elseif ($anchors['nid'] !== null) {
            $base = self::FROM_LAYOUT;
            $runs = OcrText::digitRuns((string) $text->latin($anchors['nid']), 10);
        } else {
            return Candidate::none('national_id');
        }

        // برچسب پیدا شد ولی روی همان سطر عدد به‌دردبخوری نبود: جای دیگر حدس نمی‌زنیم.
        // هر عدد دیگری روی مدرک (شماره سریال، شماره شاسی) با رقم کنترل هم ممکن است
        // تصادفاً جور دربیاید و «کد ملیِ بااطمینانِ غلط» بدترین خروجی ممکن است.
        if ($runs === []) {
            return Candidate::none('national_id');
        }

        $best = NationalIdReader::best($runs);

        if ($best === null) {
            return Candidate::none('national_id');
        }

        $confidence = $base
            + ($best['valid'] ? 45.0 : -20.0)
            - ($best['interior'] * 12.0)
            - ($best['trimmed'] * 5.0)
            - min(20.0, $best['alternatives'] * 8.0);

        return new Candidate(
            PersianValue::toPersianDigits(implode(' ', $runs)),
            $best['value'],
            $confidence,
            $best['interior'] > 0 || $best['trimmed'] > 0 ? 'national_id/repaired' : 'national_id',
        );
    }

    // ------------------------------------------------------------------
    // تاریخ‌های شمسی
    // ------------------------------------------------------------------

    /**
     * همهٔ فیلدهای تاریخ یک مدرک با هم حل می‌شوند.
     *
     * دلیل: روی کارت ملی هیچ‌کدام از برچسب‌های تاریخ سالم نمی‌مانند، ولی
     * **ترتیب** چاپشان ثابت است (اول تولد، بعد پایان اعتبار). پس اول هر
     * تاریخی که برچسبِ قابل‌تشخیص دارد به فیلدش بسته می‌شود و بعد تاریخ‌های
     * بی‌صاحب به‌ترتیبِ چاپ به فیلدهای بی‌مقدار می‌رسند.
     *
     * @param  list<DocumentTypeField>  $fields
     * @return array<string, Candidate>
     */
    private function readDates(OcrText $text, string $typeKey, array $fields): array
    {
        $dateFields = array_values(array_filter(
            $fields,
            static fn (DocumentTypeField $f): bool => $f->value_type === 'jalali_date',
        ));

        if ($dateFields === []) {
            return [];
        }

        $occurrences = [];

        for ($i = 0; $i < $text->count(); $i++) {
            foreach (OcrText::dateMatches((string) $text->latin($i)) as $match) {
                $occurrences[] = ['line' => $i, 'match' => $match];
            }
        }

        $used = [];
        $out = [];

        // گذر اول: برچسب
        foreach ($dateFields as $field) {
            $hit = LabelLocator::find($text, LabelBook::labels($typeKey, (string) $field->key));

            if ($hit === null) {
                continue;
            }

            foreach ($occurrences as $index => $occurrence) {
                if (isset($used[$index]) || $occurrence['line'] !== $hit['line']) {
                    continue;
                }

                $used[$index] = true;
                $out[(string) $field->key] = $this->dateCandidate(
                    $occurrence['match'],
                    $hit['exact'] ? self::FROM_EXACT_LABEL : self::FROM_FUZZY_LABEL,
                );

                break;
            }
        }

        // گذر دوم: ترتیب چاپ
        foreach ($dateFields as $field) {
            if (isset($out[(string) $field->key])) {
                continue;
            }

            foreach ($occurrences as $index => $occurrence) {
                if (isset($used[$index])) {
                    continue;
                }

                $used[$index] = true;
                $out[(string) $field->key] = $this->dateCandidate($occurrence['match'], self::FROM_LAYOUT);

                break;
            }
        }

        return $out;
    }

    /** @param  array{raw: string, year: int, month: string, day: string}  $match */
    private function dateCandidate(array $match, float $base): Candidate
    {
        $read = DateReader::read($match);

        $confidence = $base
            + ($read['valid'] ? 35.0 : -15.0)
            - ($read['repaired'] ? 15.0 : 0.0)
            - ($read['ambiguous'] ? 10.0 : 0.0);

        return new Candidate(
            PersianValue::toPersianDigits($match['raw']),
            $read['value'],
            $confidence,
            $read['repaired'] ? 'date/repaired' : 'date',
        );
    }

    // ------------------------------------------------------------------
    // شمارهٔ شاسی و پلاک
    // ------------------------------------------------------------------

    /** @param  array<string, mixed>  $extra */
    private function readVin(OcrText $text, string $typeKey, string $fieldKey, array $extra): Candidate
    {
        $fromExtra = isset($extra['vin']) && is_scalar($extra['vin']) ? (string) $extra['vin'] : null;
        $read = VehicleReader::vin($fromExtra);
        $base = self::FROM_ENGINE_EXTRA;
        $raw = $fromExtra;

        if ($read === null) {
            $raw = VehicleReader::vinLine($text);
            $read = VehicleReader::vin($raw);
            $base = self::FROM_EXACT_LABEL;
        }

        if ($read === null) {
            return Candidate::none('vin');
        }

        $shape = match (true) {
            $read['valid'] && $read['shaped'] => 35.0,
            $read['valid'] => 0.0,
            default => -20.0,
        };

        return new Candidate(
            $raw === null ? null : trim($raw),
            $read['value'],
            $base + $shape,
            'vin',
        );
    }

    /** @param  array<string, mixed>  $extra */
    private function readPlate(OcrText $text, array $extra): Candidate
    {
        $fromExtra = isset($extra['plate']) && is_scalar($extra['plate']) ? (string) $extra['plate'] : null;
        $read = VehicleReader::plate($fromExtra);
        $base = self::FROM_ENGINE_EXTRA;
        $raw = $fromExtra;

        if ($read === null) {
            $read = VehicleReader::plateFromText($text);
            $base = self::FROM_SHAPE;
            $raw = $read['value'] ?? null;
        }

        if ($read === null) {
            return Candidate::none('plate');
        }

        return new Candidate(
            $raw === null ? null : trim($raw),
            $read['value'],
            $base + ($read['valid'] ? 35.0 : -20.0),
            'plate',
        );
    }

    // ------------------------------------------------------------------
    // شماره‌های صرفاً رقمی (شماره گواهینامه، شماره مجوز)
    // ------------------------------------------------------------------

    /** @param  array{nid: ?int, date: ?int}  $anchors */
    private function readDigits(OcrText $text, string $typeKey, string $fieldKey, array $anchors): Candidate
    {
        $hit = LabelLocator::find($text, LabelBook::labels($typeKey, $fieldKey));

        if ($hit === null) {
            return Candidate::none('digits');
        }

        $runs = OcrText::digitRuns((string) $text->latin($hit['line']), 6);

        if ($runs === []) {
            return Candidate::none('digits');
        }

        usort($runs, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $value = $runs[0];

        $base = $hit['exact'] ? self::FROM_EXACT_LABEL : self::FROM_FUZZY_LABEL;
        $plausible = strlen($value) >= 8 && strlen($value) <= 12;

        return new Candidate(
            PersianValue::toPersianDigits($value),
            $value,
            $base + ($plausible ? 30.0 : 5.0),
            'digits',
        );
    }

    // ------------------------------------------------------------------
    // نام‌ها
    // ------------------------------------------------------------------

    /** @param  array{nid: ?int, date: ?int}  $anchors */
    private function readName(OcrText $text, string $typeKey, string $fieldKey, array $anchors): Candidate
    {
        $layout = self::NAME_LAYOUT[$typeKey][$fieldKey] ?? null;
        $maxTokens = $layout['tokens'] ?? 3;
        $below = (bool) ($layout['below'] ?? false);

        /** @var list<array{line: int, base: float}> $sources */
        $sources = [];

        // سطرِ زیرِ هر منبع، درست بعد از خودش امتحان می‌شود و نه دیرتر: ترتیب
        // sources ترتیب اولویت است و اولین سطری که نام قابل‌قبول بدهد برنده
        // است. پایهٔ اطمینانش عمداً پایین‌تر می‌ماند، چون «سطر بعدِ برچسب»
        // شاهد ضعیف‌تری از «خودِ سطر برچسب» است.
        $add = function (?int $line, float $base) use (&$sources, $text, $below): void {
            if ($line === null) {
                return;
            }

            $sources[] = ['line' => $line, 'base' => $base];

            if ($below && $this->looksLikeNameLine($text, $line + 1)) {
                $sources[] = ['line' => $line + 1, 'base' => min($base, self::FROM_LAYOUT)];
            }
        };

        if ($layout !== null) {
            $anchor = $anchors[$layout['anchor']] ?? null;

            if ($anchor !== null) {
                $line = $anchor + $layout['offset'];

                if ($this->looksLikeNameLine($text, $line)) {
                    $add($line, self::FROM_LAYOUT);
                }
            }
        }

        $hit = LabelLocator::find($text, LabelBook::labels($typeKey, $fieldKey));

        if ($hit !== null) {
            $add(
                $hit['line'],
                $hit['exact'] ? self::FROM_EXACT_LABEL : self::FROM_FUZZY_LABEL,
            );
        }

        foreach ($sources as $source) {
            $line = $text->line($source['line']);

            if ($line === null) {
                continue;
            }

            $read = NameReader::fromLine($line, $fieldKey, $maxTokens);

            if ($read === null) {
                continue;
            }

            $valid = PersianValue::validate('text', $read['value'], 'نام') === null;

            $confidence = $source['base']
                + ($read['labelSeen'] ? 25.0 : 8.0)
                + ($valid ? 10.0 : -10.0);

            return new Candidate($read['value'], $read['value'], $confidence, 'name');
        }

        return Candidate::none('name');
    }

    /**
     * سطری که می‌تواند نام باشد.
     *
     * دو ردکننده: تاریخ داشتن، و داشتن رشتهٔ رقمِ بلند. رقم بلند یعنی این سطر
     * جای عدد است نه نام — روی کارت ملی وقتی سطرِ «نام پدر» اصلاً خوانده نشده،
     * سطر بعدیِ چیدمان همان «پایان اعتبار» است و متنش «ابان اعسا ۱۴۱۵۹/۵۲۳/۲۱»
     * است که تاریخش آن‌قدر خراب است که به‌عنوان تاریخ هم شناخته نمی‌شود.
     */
    private function looksLikeNameLine(OcrText $text, int $index): bool
    {
        $line = $text->line($index);

        if ($line === null) {
            return false;
        }

        if (OcrText::dateMatches((string) $text->latin($index)) !== []) {
            return false;
        }

        if (OcrText::digitRuns((string) $text->latin($index), 4) !== []) {
            return false;
        }

        foreach ($text->tokens($index) as $token) {
            if (OcrText::isPersianWord($token, 3)) {
                return true;
            }
        }

        return false;
    }
}
