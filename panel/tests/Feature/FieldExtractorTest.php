<?php

namespace Tests\Feature;

use App\Models\CaseDocument;
use App\Models\DocumentType;
use App\Models\ExtractedField;
use App\Models\OcrRun;
use App\Services\Cases\Extraction\NameReader;
use App\Services\Cases\Extraction\OcrText;
use App\Services\Cases\Extraction\VehicleReader;
use App\Services\Cases\FieldExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCases;
use Tests\TestCase;

/**
 * استخراج فیلد از متن OCR — تسک ۶۳۲.
 *
 * متن‌های این تست **کپی واقعی** از dataset/ocr_results هستند، نه متن دست‌ساز
 * تمیز. دلیلش این است که تنها چیزی که این مرحله را می‌شکند خرابیِ واقعیِ
 * OCR است: برچسبِ آب‌رفته، رقم اضافه، سطرِ غایب. با متن تمیز هر پیاده‌سازی
 * ساده‌ای هم سبز می‌شود و تست هیچ‌چیز را تضمین نمی‌کند.
 *
 * دادهٔ این متن‌ها مصنوعی است (خروجی ژنراتور موتور)، پس هیچ مدرک هویتی
 * واقعی وارد مخزن نمی‌شود.
 */
class FieldExtractorTest extends TestCase
{
    use BuildsCases;
    use RefreshDatabase;

    /** کارت ملی ۰۰۴ — یکی از تمیزترین‌ها؛ همهٔ لایه‌ها باید جواب بدهند. */
    private const NATIONAL_CARD_CLEAN = <<<'TXT'
شماره صلی ۸۵۳۹۴۲۵۷۳۴۰
نام ایلیا
هاشمی
سوت ۱۳۶۵/۰۳/۲۳
محمد
ماع ۱۴۱۱/۰۹/۰۶ 4
TXT;

    /** کارت ملی ۰۰۵ — برچسب‌ها نابود شده‌اند («موی طلوعی» یعنی «نام خانوادگی طلوعی»). */
    private const NATIONAL_CARD_NO_LABELS = <<<'TXT'
شماره صلی ۰ ‎٩۳۱۷۳۹۹۵۶۸۵‏
‏مبین
‏موی طلوعی
سح وت ۱۳۸۵/۰۲/۲۳
طاها
با سار ۱۴۱۳/۰۶/۲۲ ۰۰۶
TXT;

    /** کارت ملی ۰۰۷ — کد ملی یازده‌رقمی خوانده شده (یک صفر اضافه به دُم عدد). */
    private const NATIONAL_CARD_LONG_ID = <<<'TXT'
۱ س
0
رک انیبان 5
شماره ملی ۰۳۵۰۶۱۷۴۶۵۰
آریا
نام خانوادگی ظفری
تاریخ نود ۱۳۶۰/۰۸/۱۶
نام پدر علی اصغر
پاان اعنبار ۱۴۱۰/۱۱/۳۶ ۰:۶
TXT;

    /** کارت ملی ۰۱۵ — روز تاریخ تولد سه‌رقمی خوانده شده: «۱۳۸۳/۰۳/۵۰۴». */
    private const NATIONAL_CARD_BROKEN_DAY = <<<'TXT'
۱ ِ ها
0 کت یل تت
ور یا لزان
شماره ملی ۱۷۱۷۲۴۳۴۸۱۱۱۰

تام علي
۲ خانوادگی لاهوتی
رح نود ۱۳۸۳/۰۳/۵۰۴
نام بدر علیرضا
بابان اعتسار ۰ ۱۴۱۱/۱۳/۱۵ رای
.و
TXT;

    /** گواهینامه ۰۰۱ — برچسب‌هایش سالم می‌مانند، پس لایهٔ برچسب باید کار کند. */
    private const DRIVING_LICENSE = <<<'TXT'
۳ گراهینامه رانند گ ( 1

ک پایه اول
شماره‌ملی ۸۱۴۳۰۳۷۳۸۱ نت۳
نام نام خانوادگی ح 9 6

سا ۲

تاریخ تولد ۱۳۶۵/۱۱/۰۶

۱ ۹ تاریخ صدور ۱۳۸۵/۰۳/۲۶ مدت اعتبار ۱۰ سال
شماره گواهینامه ۳۲۲۷۰۲۸۹۲۱
TXT;

    /** کارت خودرو ۰۱۳ — خط VIN آشغال چسبیده دارد: «VIN : NI: NAS…». */
    private const VEHICLE_CARD = <<<'TXT'
7 ژ +مم 1
مشخصات مالک :(حتیتی "نی علي علیجانی ۳
شماره ملی / کد شناسایی: «عووووریروس

۱ نام پدر | نماینده سازمان: علی
6 ۱ :۱/۱۷۰
شماره کف
۰ ۳
۴0۷ الف ‎٩۱‏ ۱۳
۱۳۸۵۸۷

VIN : NI: NAS675287M6771656
PLATE : ۱۱ 2 ۱لف ۱۳
TXT;

    // ------------------------------------------------------------------
    // هستهٔ بدون دیتابیس
    // ------------------------------------------------------------------

    public function test_clean_national_card_gives_every_field(): void
    {
        $fields = $this->read('national_card', self::NATIONAL_CARD_CLEAN);

        $this->assertSame('۸۵۳۹۴۲۵۷۳۴', $fields['national_id']['normalized']);
        $this->assertSame('ایلیا', $fields['first_name']['normalized']);
        $this->assertSame('هاشمی', $fields['last_name']['normalized']);
        $this->assertSame('۱۳۶۵/۰۳/۲۳', $fields['birth_date']['normalized']);
        $this->assertSame('محمد', $fields['father_name']['normalized']);
        $this->assertSame('۱۴۱۱/۰۹/۰۶', $fields['national_card_expire']['normalized']);
    }

    public function test_names_survive_when_the_label_is_destroyed(): void
    {
        $fields = $this->read('national_card', self::NATIONAL_CARD_NO_LABELS);

        // هیچ‌کدام از این سه سطر برچسب خوانا ندارند؛ فقط ترتیب چاپ مانده است.
        $this->assertSame('مبین', $fields['first_name']['normalized']);
        $this->assertSame('طلوعی', $fields['last_name']['normalized']);
        $this->assertSame('طاها', $fields['father_name']['normalized']);
    }

    public function test_extra_digit_is_dropped_using_the_check_digit(): void
    {
        $fields = $this->read('national_card', self::NATIONAL_CARD_LONG_ID);

        // OCR «۰۳۵۰۶۱۷۴۶۵۰» خوانده؛ رقم کنترل می‌گوید کدام ده رقم درست است.
        $this->assertSame('۰۳۵۰۶۱۷۴۶۵', $fields['national_id']['normalized']);
        $this->assertGreaterThan(70, $fields['national_id']['confidence']);
    }

    public function test_a_ten_digit_number_with_a_bad_check_digit_is_not_trusted(): void
    {
        $fields = $this->read('national_card', "شماره ملی ۱۲۳۴۵۶۷۸۹۰\nنام سعید\n");

        $this->assertSame('۱۲۳۴۵۶۷۸۹۰', $fields['national_id']['normalized']);
        $this->assertLessThan(40, $fields['national_id']['confidence']);
    }

    public function test_three_digit_day_is_repaired_but_costs_confidence(): void
    {
        $repaired = $this->read('national_card', self::NATIONAL_CARD_BROKEN_DAY);
        $clean = $this->read('national_card', self::NATIONAL_CARD_CLEAN);

        $this->assertSame('۱۳۸۳/۰۳/۰۴', $repaired['birth_date']['normalized']);
        $this->assertLessThan(
            $clean['birth_date']['confidence'],
            $repaired['birth_date']['confidence'],
        );
    }

    public function test_impossible_date_is_reported_with_low_confidence(): void
    {
        // ماه ۱۳ وجود ندارد؛ مقدار برمی‌گردد تا کارشناس ببیند، ولی بی‌اعتبار است.
        $fields = $this->read('driving_license', "تاریخ تولد ۱۳۶۰/۱۳/۰۱\n");

        $this->assertSame('۱۳۶۰/۱۳/۰۱', $fields['birth_date']['normalized']);
        $this->assertLessThan(40, $fields['birth_date']['confidence']);
    }

    public function test_driving_license_reads_its_labels(): void
    {
        $fields = $this->read('driving_license', self::DRIVING_LICENSE);

        $this->assertSame('۸۱۴۳۰۳۷۳۸۱', $fields['national_id']['normalized']);
        $this->assertSame('۱۳۶۵/۱۱/۰۶', $fields['birth_date']['normalized']);
        $this->assertSame('۱۳۸۵/۰۳/۲۶', $fields['license_issue_date']['normalized']);
        $this->assertSame('۳۲۲۷۰۲۸۹۲۱', $fields['license_number']['normalized']);

        // «مدت اعتبار ۱۰ سال» تاریخ نیست، پس این فیلد باید خالی بماند نه غلط.
        $this->assertNull($fields['license_expire_date']['normalized']);
        $this->assertSame(0.0, $fields['license_expire_date']['confidence']);
    }

    public function test_vin_is_cleaned_from_the_junk_glued_to_its_line(): void
    {
        $fields = $this->read('vehicle_card', self::VEHICLE_CARD);

        $this->assertSame('NAS675287M6771656', $fields['vin']['normalized']);
        $this->assertSame('علی علیجانی', $fields['full_name']['normalized']);
        $this->assertSame('علی', $fields['father_name']['normalized']);
    }

    public function test_engine_extra_beats_the_raw_text(): void
    {
        $fields = $this->read('vehicle_card', self::VEHICLE_CARD, [
            'vin' => 'nas 675287 m 6771656',
            'plate' => '۴۶ الف ۴۵۷ ایران ۹۱',
        ]);

        $this->assertSame('NAS675287M6771656', $fields['vin']['normalized']);
        $this->assertSame('۴۶ الف ۴۵۷ ایران ۹۱', $fields['plate_number']['normalized']);
        $this->assertGreaterThan(90, $fields['plate_number']['confidence']);
    }

    public function test_keys_match_the_document_type_and_confidence_stays_in_range(): void
    {
        $type = $this->documentType('national_card');
        $fields = app(FieldExtractor::class)->fieldsFromText($type, self::NATIONAL_CARD_CLEAN);

        $this->assertSame(
            $type->fields->pluck('key')->sort()->values()->all(),
            collect(array_keys($fields))->sort()->values()->all(),
        );

        foreach ($fields as $key => $field) {
            $this->assertGreaterThanOrEqual(0, $field['confidence'], $key);
            $this->assertLessThanOrEqual(100, $field['confidence'], $key);
        }
    }

    // ------------------------------------------------------------------
    // رگرسیون‌های بازبینی تسک ۶۳۲
    // ------------------------------------------------------------------

    /**
     * یک عدد هرگز نباید «نام» شمرده شود.
     *
     * بلوک یونیکد `\p{Arabic}` ارقام فارسی و عربی را هم در بر می‌گیرد، پس
     * `isPersianWord('۱۲۳۴۵')` صادق بود و «۱۲۳۴۵» با اطمینان ۸۵ به‌عنوان نام
     * ذخیره می‌شد. آن اطمینان از آستانهٔ اعتماد بالاتر است، پس تطابق نام بین
     * مدارک mismatch می‌شد و پروندهٔ سالم خودکار رد می‌شد.
     */
    public function test_a_number_is_never_accepted_as_a_name(): void
    {
        $fields = $this->read(
            'national_card',
            "شماره ملی ۰۰۱۲۳۴۵۶۷۸\nنام ۱۲۳۴۵\nنام خانوادگی تحسینی\nنام پدر ۵۹۲\n",
        );

        $this->assertNull($fields['first_name']['normalized']);
        $this->assertSame(0.0, $fields['first_name']['confidence']);
        $this->assertNull($fields['father_name']['normalized']);
        $this->assertSame(0.0, $fields['father_name']['confidence']);

        // سطر سالمِ همان مدرک نباید قربانی سخت‌گیری شود
        $this->assertSame('تحسینی', $fields['last_name']['normalized']);
    }

    /** همان قاعده در سطح خودِ تابع — هر دو جهت، تا سخت‌گیری از حد نگذرد. */
    public function test_persian_word_needs_a_letter_but_keeps_zwnj_and_marks(): void
    {
        foreach (['۱۲۳۴۵', '۵۹۲', '٠١٢٣٤', '۱۲ب', 'ali', '12345'] as $token) {
            $this->assertFalse(OcrText::isPersianWord($token), $token);
        }

        foreach (['حسام', 'علی', "بهره\u{200C}برداری", 'اسماء', 'مُحَمَّد'] as $token) {
            $this->assertTrue(OcrText::isPersianWord($token), $token);
        }
    }

    /**
     * وقتی برچسب روی جداکننده بریده شد، نام کوچک نباید ته‌ماندهٔ برچسب حساب شود.
     *
     * distance('سام', 'نام') = ۱ است و با بودجهٔ فازی دور ریخته می‌شد؛ نتیجه
     * «رضایی» بود که شباهتش با نام واقعی ۵۵٪ است — زیر آستانهٔ «مشکوک»، یعنی
     * mismatch و رد خودکار.
     */
    public function test_a_first_name_close_to_the_label_survives_the_separator(): void
    {
        $this->assertSame(
            'سام رضایی',
            NameReader::fromLine('نام و نام خانوادگی: سام رضایی', 'full_name', 3)['value'],
        );

        $this->assertSame(
            'سام رضایی',
            NameReader::fromLine('مشخصات مالک:(حقیقی / حقوقی) سام رضایی', 'full_name', 2)['value'],
        );
    }

    /** و برچسب‌هایی که واقعاً ته‌ماندهٔ برچسب‌اند هنوز باید پاک شوند. */
    public function test_real_label_remnants_are_still_stripped(): void
    {
        // تطابق دقیق، حتی بعد از برشِ جداکننده
        $this->assertSame(
            'سام رضایی',
            NameReader::fromLine('مشخصات مالک : حقیقی حقوقی سام رضایی', 'full_name', 2)['value'],
        );

        // تطابق فازی وقتی جداکننده‌ای در کار نیست: «بحر» همان «پدر» است
        $this->assertSame(
            'محمد مهدی',
            NameReader::fromLine('بحر محمد مهدی', 'father_name', 2)['value'],
        );

        // برچسبِ نابودشده: هیچ نشانه‌ای نمانده، پس فقط یک توکن آخر برداشته می‌شود
        $this->assertSame('طلوعی', NameReader::fromLine('موی طلوعی', 'last_name', 2)['value']);

        // و اگر بعد از دور ریختن آشغال فقط خودِ برچسب مانده باشد، مقداری نیست
        $this->assertNull(NameReader::fromLine('نام خانوادگی ۱۲۳۴۵', 'last_name', 2));
    }

    /**
     * برچسبِ بعد از مقدار نباید سطر را از گذر دوم بیرون بیندازد.
     *
     * پیش‌تر سطری که کلیدواژهٔ «پلاک» داشت ولی الگو در دنبالهٔ بعد از کلیدواژه
     * نبود با continue کنار گذاشته می‌شد، یعنی وجود برچسب نتیجه را از «پیدا شد»
     * به «پیدا نشد» می‌برد و plate_number خالی می‌ماند.
     */
    public function test_plate_is_found_when_its_label_comes_after_the_value(): void
    {
        $this->assertSame(
            '۱۲ ب ۳۴۵ ایران ۶۷',
            VehicleReader::plateFromText(new OcrText('۱۲ ب ۳۴۵ ایران ۶۷ شماره پلاک'))['value'],
        );

        // برچسب پیش از مقدار هنوز کار می‌کند
        $this->assertSame(
            '۱۲ ب ۳۴۵ ایران ۶۷',
            VehicleReader::plateFromText(new OcrText('PLATE : ۱۲ ب ۳۴۵ ایران ۶۷'))['value'],
        );

        $fields = $this->read(
            'vehicle_card',
            "مشخصات مالک :(حقیقی / حقوقی) سام رضایی\n۱۲ ب ۳۴۵ ایران ۶۷ شماره پلاک\n",
        );

        $this->assertSame('۱۲ ب ۳۴۵ ایران ۶۷', $fields['plate_number']['normalized']);
        $this->assertSame('سام رضایی', $fields['full_name']['normalized']);
    }

    public function test_a_blank_page_produces_no_value_and_no_confidence(): void
    {
        $fields = $this->read('national_card', "\n\n   \n");

        foreach ($fields as $key => $field) {
            $this->assertNull($field['normalized'], $key);
            $this->assertSame(0.0, $field['confidence'], $key);
        }
    }

    // ------------------------------------------------------------------
    // نوشتن در دیتابیس
    // ------------------------------------------------------------------

    public function test_extract_writes_rows_and_running_twice_changes_nothing(): void
    {
        [$document, $run] = $this->documentWithOcr('national_card', self::NATIONAL_CARD_CLEAN);
        $extractor = app(FieldExtractor::class);

        $first = $extractor->extract($document, $run);
        $this->assertSame(6, $first);

        $rows = ExtractedField::query()->where('case_document_id', $document->id)->count();
        $this->assertSame(6, $rows);

        $extractor->extract($document, $run);

        $this->assertSame(
            $rows,
            ExtractedField::query()->where('case_document_id', $document->id)->count(),
        );
    }

    public function test_manual_correction_is_never_overwritten(): void
    {
        [$document, $run] = $this->documentWithOcr('national_card', self::NATIONAL_CARD_CLEAN);
        $extractor = app(FieldExtractor::class);

        $extractor->extract($document, $run);

        ExtractedField::query()
            ->where('case_document_id', $document->id)
            ->where('field_key', 'first_name')
            ->update(['normalized_value' => 'ایلیا رضا', 'source' => 'manual', 'confidence' => 100]);

        $extractor->extract($document, $run);

        $field = ExtractedField::query()
            ->where('case_document_id', $document->id)
            ->where('field_key', 'first_name')
            ->firstOrFail();

        $this->assertSame('manual', $field->source);
        $this->assertSame('ایلیا رضا', $field->normalized_value);
    }

    public function test_stale_ocr_rows_are_cleared_when_a_field_disappears(): void
    {
        [$document, $run] = $this->documentWithOcr('national_card', self::NATIONAL_CARD_CLEAN);
        $extractor = app(FieldExtractor::class);

        $extractor->extract($document, $run);
        $this->assertDatabaseHas('extracted_fields', [
            'case_document_id' => $document->id,
            'field_key' => 'father_name',
        ]);

        // OCR دوباره اجرا شده و این بار متن به‌مراتب کم‌مایه‌تر است
        $run->update(['raw_text' => "شماره ملی ۸۵۳۹۴۲۵۷۳۴۰\n"]);

        $extractor->extract($document, $run->refresh());

        $this->assertDatabaseMissing('extracted_fields', [
            'case_document_id' => $document->id,
            'field_key' => 'father_name',
        ]);
        $this->assertDatabaseHas('extracted_fields', [
            'case_document_id' => $document->id,
            'field_key' => 'national_id',
        ]);
    }

    /**
     * یک اجرای ناموفق OCR نباید دادهٔ سالم اجرای قبلی را نابود کند.
     *
     * وقتی این اجرا هیچ فیلدی پیدا نمی‌کرد، شرط whereNotIn غیرفعال می‌شد و
     * delete بی‌قید همهٔ ردیف‌های source=ocr آن مدرک را می‌برد: ocr_quality صفر
     * می‌شد و document.missing_required از passed به failed می‌رفت.
     */
    public function test_an_empty_ocr_rerun_keeps_the_fields_of_the_previous_run(): void
    {
        [$document, $run] = $this->documentWithOcr('national_card', self::NATIONAL_CARD_CLEAN);
        $extractor = app(FieldExtractor::class);

        $this->assertSame(6, $extractor->extract($document, $run));

        // OCR دوباره اجرا شده ولی این بار متنی درنیامده (تصویر خراب، صفحهٔ سفید)
        $run->update(['raw_text' => '']);

        $this->assertSame(0, $extractor->extract($document, $run->refresh()));

        $this->assertSame(
            6,
            ExtractedField::query()->where('case_document_id', $document->id)->count(),
        );
        $this->assertDatabaseHas('extracted_fields', [
            'case_document_id' => $document->id,
            'field_key' => 'first_name',
            'normalized_value' => 'ایلیا',
        ]);
    }

    // ------------------------------------------------------------------
    // چندمقیاسی — تسک ۶۶۲
    // ------------------------------------------------------------------

    /**
     * هر فیلد از نسخه‌ای می‌آید که خودش را بهتر خوانده، نه از یک نسخهٔ برنده.
     *
     * دو متن زیر سطرهای واقعیِ همان کارت ملی‌اند که موتور در دو بزرگ‌نمایی
     * خوانده: در نسخهٔ اول کد ملی درست و تاریخ تولد خراب است، در نسخهٔ دوم
     * برعکس. اگر «بهترین متن» انتخاب می‌شد، هر کدام را که می‌بردیم یکی از
     * دو فیلد را می‌باختیم.
     */
    public function test_each_field_comes_from_the_variant_that_read_it_best(): void
    {
        $goodIdBadDate = <<<'TXT'
شماره ملی ۷۰۹۱۵۷۴۶۶۴۰
۲ محمدپارسا
تام خانو ادگ, : سعیدی
تاریخ توند ۰ ۱۳۶۱/۱۳/۱۳
نم بر : علی
TXT;

        $badIdGoodDate = <<<'TXT'
شماره فلی ۷۰۹۱۵۷۳۶۶۴۰
۲ : محمدپارسا
تام خانوادگ, : سعیدی
ریخ تون ۰ ۱۳۶۱/۱۲/۱۳
نام پذر : علی
بایان ۱۷۸۶۶۱ ۰ ۱۴۱۰/۰۷/۲۳ رن
TXT;

        $type = $this->documentType('national_card');
        $extractor = app(FieldExtractor::class);

        $first = $extractor->fieldsFromText($type, $goodIdBadDate);
        $second = $extractor->fieldsFromText($type, $badIdGoodDate);

        // فرضِ خودِ تست: واقعاً هیچ‌کدام به‌تنهایی هر دو فیلد را ندارند
        $this->assertSame('۷۰۹۱۵۷۴۶۶۴', $first['national_id']['normalized']);
        $this->assertNotSame('۱۳۶۱/۱۲/۱۳', $first['birth_date']['normalized']);
        $this->assertNotSame('۷۰۹۱۵۷۴۶۶۴', $second['national_id']['normalized']);
        $this->assertSame('۱۳۶۱/۱۲/۱۳', $second['birth_date']['normalized']);

        $merged = $extractor->fieldsFromVariants($type, [
            ['raw_text' => $goodIdBadDate],
            ['raw_text' => $badIdGoodDate],
        ]);

        $this->assertSame('۷۰۹۱۵۷۴۶۶۴', $merged['national_id']['normalized'], 'کد ملی از نسخهٔ اول');
        $this->assertSame('۱۳۶۱/۱۲/۱۳', $merged['birth_date']['normalized'], 'تاریخ تولد از نسخهٔ دوم');

        // فیلدی که فقط یک نسخه دارد هم نباید گم شود
        $this->assertSame('۱۴۱۰/۰۷/۲۳', $merged['national_card_expire']['normalized']);
    }

    /** فیلدی که هیچ نسخه‌ای پیدایش نکرده باید با مقدار null در خروجی بماند. */
    public function test_a_field_no_variant_found_stays_in_the_result_as_null(): void
    {
        $merged = app(FieldExtractor::class)->fieldsFromVariants(
            $this->documentType('national_card'),
            [['raw_text' => 'شماره ملی ۸۵۳۹۴۲۵۷۳۴۰'], ['raw_text' => 'شماره ملی ۸۵۳۹۴۲۵۷۳۴']],
        );

        $this->assertArrayHasKey('father_name', $merged);
        $this->assertNull($merged['father_name']['normalized']);
        $this->assertSame(0.0, $merged['father_name']['confidence']);
    }

    /** اجرای قدیمیِ بدون `extra.variants` باید دقیقاً مثل قبل کار کند. */
    public function test_a_run_without_variants_still_reads_its_raw_text(): void
    {
        [$document, $run] = $this->documentWithOcr('national_card', self::NATIONAL_CARD_CLEAN);

        $variants = FieldExtractor::variantsOf($run);

        $this->assertCount(1, $variants);
        $this->assertSame(self::NATIONAL_CARD_CLEAN, $variants[0]['raw_text']);
        $this->assertSame(6, app(FieldExtractor::class)->extract($document, $run));
    }

    /**
     * موتور نسخهٔ اول را هم در `raw_text` می‌گذارد و هم در `variants`؛
     * دوباره خواندنش فقط وقت می‌برد.
     */
    public function test_the_primary_text_is_not_read_twice(): void
    {
        [, $run] = $this->documentWithOcr('national_card', self::NATIONAL_CARD_CLEAN);

        $run->update(['extra' => ['variants' => [
            ['scale' => 1.0, 'raw_text' => self::NATIONAL_CARD_CLEAN],
            ['scale' => 1.25, 'raw_text' => self::NATIONAL_CARD_NO_LABELS],
            ['scale' => 1.5, 'raw_text' => '   '],
        ]]]);

        $variants = FieldExtractor::variantsOf($run->refresh());

        $this->assertCount(2, $variants, 'نسخهٔ تکراری و نسخهٔ خالی نباید دوباره خوانده شوند');
        $this->assertSame(self::NATIONAL_CARD_CLEAN, $variants[0]['raw_text']);
        $this->assertSame(self::NATIONAL_CARD_NO_LABELS, $variants[1]['raw_text']);
    }

    /** `extract` باید نسخه‌های ذخیره‌شدهٔ موتور را ببیند، نه فقط raw_text را. */
    public function test_extract_uses_the_stored_variants(): void
    {
        [$document, $run] = $this->documentWithOcr(
            'national_card',
            "شماره فلی ۷۰۹۱۵۷۳۶۶۴۰
تاریخ توند ۰ ۱۳۶۱/۱۳/۱۳
",
        );

        $run->update(['extra' => ['variants' => [
            ['scale' => 1.25, 'raw_text' => "شماره ملی ۷۰۹۱۵۷۴۶۶۴۰
ریخ تون ۰ ۱۳۶۱/۱۲/۱۳
"],
        ]]]);

        app(FieldExtractor::class)->extract($document, $run->refresh());

        $this->assertDatabaseHas('extracted_fields', [
            'case_document_id' => $document->id,
            'field_key' => 'national_id',
            'normalized_value' => '۷۰۹۱۵۷۴۶۶۴',
        ]);
        $this->assertDatabaseHas('extracted_fields', [
            'case_document_id' => $document->id,
            'field_key' => 'birth_date',
            'normalized_value' => '۱۳۶۱/۱۲/۱۳',
        ]);
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, array{raw: ?string, normalized: ?string, confidence: float}>
     */
    private function read(string $typeKey, string $text, array $extra = []): array
    {
        return app(FieldExtractor::class)->fieldsFromText($this->documentType($typeKey), $text, $extra);
    }

    private function documentType(string $key): DocumentType
    {
        $this->seedReferenceDataOnce();

        return DocumentType::query()->with('fields')->where('key', $key)->firstOrFail();
    }

    /** @return array{0: CaseDocument, 1: OcrRun} */
    private function documentWithOcr(string $typeKey, string $text): array
    {
        $this->seedReferenceDataOnce();

        $case = $this->makeCaseWithDocuments('issue');

        /** @var CaseDocument $document */
        $document = $case->documents->firstWhere('document_type_id', $this->documentTypeId($typeKey));

        $run = $document->ocrRuns()->create([
            'engine_version' => 'test',
            'raw_text' => $text,
            'status' => 'done',
        ]);

        return [$document->fresh(['documentType']), $run];
    }

    private function seedReferenceDataOnce(): void
    {
        if (DocumentType::query()->exists()) {
            return;
        }

        $this->seedReferenceData();
    }
}
