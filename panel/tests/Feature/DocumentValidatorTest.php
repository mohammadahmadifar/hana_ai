<?php

namespace Tests\Feature;

use App\Models\CaseDocument;
use App\Models\DocumentType;
use App\Models\DocumentTypeField;
use App\Models\ExtractedField;
use App\Models\PermitCase;
use App\Models\Setting;
use App\Models\ValidationResult;
use App\Services\Cases\DocumentValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsCases;
use Tests\TestCase;

/**
 * تسک ۶۳۳ — اعتبارسنجی اسناد و تطابق بین مدارک.
 *
 * قلب این تست، بررسی ضدجعل است: پرونده‌ای که کد ملی گواهینامه‌اش با کارت ملی
 * یکی نیست باید با دلیل روشن فارسی علامت بخورد. کنارش سه حالت دیگر: مدرک
 * منقضی، فیلد اجباری خالی، و پروندهٔ سالم که هیچ ایرادی نمی‌گیرد.
 *
 * زمان با Carbon::setTestNow قفل شده تا «امروز» ثابت بماند؛ وگرنه تاریخ‌های
 * انقضای این تست سال آینده خودبه‌خود منقضی می‌شدند و تست بی‌دلیل قرمز می‌شد.
 *
 * هیچ دادهٔ هویتی واقعی این‌جا نیست — نام‌ها و کدها آشکارا ساختگی‌اند.
 */
class DocumentValidatorTest extends TestCase
{
    use BuildsCases;
    use RefreshDatabase;

    /** ۱۴۰۵/۰۵/۳۰ شمسی — «امروزِ» ثابت این تست. */
    private const TODAY_GREGORIAN = '2026-08-21 12:00:00';

    private const TODAY_JALALI = '۱۴۰۵/۰۵/۳۰';

    /** مقادیر پایه: یک متقاضی ساختگی که همه‌چیزش با هم می‌خواند. */
    private const BASE = [
        'national_card' => [
            'national_id' => '۰۰۱۲۳۴۵۶۷۸',
            'first_name' => 'سمیرا',
            'last_name' => 'کاظمی',
            'birth_date' => '۱۳۷۰/۰۴/۲۱',
            'father_name' => 'محمود',
            'national_card_expire' => '۱۴۰۸/۰۳/۱۲',
        ],
        'driving_license' => [
            'national_id' => '۰۰۱۲۳۴۵۶۷۸',
            'full_name' => 'سمیرا کاظمی',
            'birth_date' => '۱۳۷۰/۰۴/۲۱',
            'license_issue_date' => '۱۳۹۸/۰۹/۰۵',
            'license_expire_date' => '۱۴۰۷/۰۹/۰۵',
            'license_number' => '۱۲۳۴۵۶۷۸',
        ],
        'vehicle_card' => [
            'full_name' => 'سمیرا کاظمی',
            'national_id' => '۰۰۱۲۳۴۵۶۷۸',
            'father_name' => 'محمود',
            'vin' => 'NAS123456M7654321',
            'plate_number' => '۸۸ ۵۱۱ و ۳۵',
        ],
        'previous_permit' => [
            'permit_number' => '۹۹۸۸۷۷',
            'national_id' => '۰۰۱۲۳۴۵۶۷۸',
            'permit_issue_date' => '۱۴۰۲/۰۶/۰۱',
            'permit_expire_date' => '۱۴۰۶/۰۶/۰۱',
            'plate_number' => '۸۸ ۵۱۱ و ۳۵',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::TODAY_GREGORIAN, 'Asia/Tehran'));
        $this->seedReferenceData();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // ۳) تطابق بین مدارک — مهم‌ترین بررسی ضدجعل (تعریف done)
    // ------------------------------------------------------------------

    public function test_national_id_mismatch_between_license_and_national_card_is_failed(): void
    {
        $case = $this->makeCaseWithDocuments('issue');

        $this->extract($case, $this->data([
            'driving_license' => ['national_id' => '۰۰۱۲۳۴۵۶۷۹'],
        ]));

        (new DocumentValidator)->validate($case);

        $row = $this->ruleRow($case, 'cross.national_id');

        $this->assertNotNull($row, 'اختلاف کد ملی باید ردیف نتیجه بسازد.');
        $this->assertSame('cross', $row->scope);
        $this->assertSame('failed', $row->status);
        $this->assertNull($row->case_document_id, 'بررسی بین‌مدرکی به یک مدرک خاص بسته نیست.');

        // دلیل باید بگوید چه دیدیم: هر دو مقدار و نام هر دو مدرک داخل پیام است
        $this->assertStringContainsString('کد ملی', $row->message_fa);
        $this->assertStringContainsString('۰۰۱۲۳۴۵۶۷۹', $row->message_fa);
        $this->assertStringContainsString('۰۰۱۲۳۴۵۶۷۸', $row->message_fa);
        $this->assertStringContainsString('گواهینامه', $row->message_fa);
        $this->assertStringContainsString('کارت ملی', $row->message_fa);
        $this->assertStringContainsString('یکی نیست', $row->message_fa);

        // details همان مقادیر مقایسه‌شده را نگه می‌دارد تا صفحهٔ نتیجه نشانشان دهد
        $this->assertSame('national_card', $row->details['reference']['document']);
        $this->assertSame('۰۰۱۲۳۴۵۶۷۸', $row->details['reference']['value']);
        $this->assertSame('exact', $row->details['match_mode']);

        $license = collect($row->details['compared'])->firstWhere('document', 'driving_license');
        $this->assertSame('mismatch', $license['verdict']);
        $this->assertSame('۰۰۱۲۳۴۵۶۷۹', $license['value']);

        // کارت خودرو با کارت ملی می‌خواند، پس نباید متهم شود
        $vehicle = collect($row->details['compared'])->firstWhere('document', 'vehicle_card');
        $this->assertSame('match', $vehicle['verdict']);
    }

    public function test_name_is_compared_across_two_different_shapes(): void
    {
        // کارت ملی «نام + نام خانوادگی» دارد و گواهینامه «نام و نام خانوادگی»؛
        // اگر فقط کلید هم‌نام مقایسه شود، این جعل هرگز دیده نمی‌شود.
        $case = $this->makeCaseWithDocuments('issue');

        $this->extract($case, $this->data([
            'driving_license' => ['full_name' => 'رضا محمدی'],
        ]));

        (new DocumentValidator)->validate($case);

        $row = $this->ruleRow($case, 'cross.full_name');

        $this->assertNotNull($row);
        $this->assertSame('failed', $row->status);
        $this->assertSame('fuzzy', $row->details['match_mode']);
        $this->assertStringContainsString('رضا محمدی', $row->message_fa);
        $this->assertStringContainsString('سمیرا کاظمی', $row->message_fa);
        // مرجع مقایسه، نامِ چسبیده‌شدهٔ کارت ملی است و برچسبش هم از دو جزء ساخته شده
        $this->assertSame('national_card', $row->details['reference']['document']);
        $this->assertSame('full_name', $row->details['reference']['field']);
        $this->assertSame('سمیرا کاظمی', $row->details['reference']['value']);
        $this->assertSame('نام و نام خانوادگی', $row->details['label']);

        // هر سه مدرک در مقایسه شرکت کرده‌اند، با اینکه full_name خودش
        // is_cross_checked ندارد — پل شکل‌ها کار کرده است
        $this->assertSame(
            ['driving_license', 'vehicle_card'],
            array_column($row->details['compared'], 'document'),
        );
    }

    public function test_ocr_noise_in_a_name_is_not_reported_as_forgery(): void
    {
        // «ي» و «ك» عربی و یک حرف جابه‌جا شده — همان چیزی که OCR فارسی مدام
        // تولید می‌کند. مقایسهٔ خام این را «جعل» می‌دید.
        $case = $this->makeCaseWithDocuments('issue');

        $this->extract($case, $this->data([
            'driving_license' => ['full_name' => 'سميرا كاظمى'],
        ]));

        (new DocumentValidator)->validate($case);

        $row = $this->ruleRow($case, 'cross.full_name');

        $this->assertNotNull($row);
        $this->assertSame('passed', $row->status, 'نویز نگارشی OCR نباید ایراد تطابق نام بسازد.');
        $this->assertStringContainsString('یکی است', $row->message_fa);
    }

    public function test_partially_garbled_name_is_a_warning_not_a_rejection(): void
    {
        // دو حرف از ده حرف خراب شده (شباهت ۸۰٪): نه آن‌قدر یکی که پاس شود،
        // نه آن‌قدر دور که بشود «رد قطعی». این همان بازهٔ «کارشناس ببیند» است.
        $case = $this->makeCaseWithDocuments('issue');

        $this->extract($case, $this->data([
            'driving_license' => ['full_name' => 'سمیرا کاطمو'],
        ]));

        (new DocumentValidator)->validate($case);

        $row = $this->ruleRow($case, 'cross.full_name');

        $this->assertNotNull($row);
        $this->assertSame('warning', $row->status);
        $this->assertStringContainsString('شباهت', $row->message_fa);
    }

    public function test_low_confidence_mismatch_is_a_warning_not_a_rejection(): void
    {
        $case = $this->makeCaseWithDocuments('issue');

        $this->extract($case, $this->data([
            'driving_license' => [
                'national_id' => ['value' => '۰۰۱۲۳۴۵۶۷۹', 'confidence' => 31.0],
            ],
        ]));

        (new DocumentValidator)->validate($case);

        $row = $this->ruleRow($case, 'cross.national_id');

        $this->assertNotNull($row);
        $this->assertSame('warning', $row->status, 'خوانده‌شدهٔ کم‌اطمینان نباید رد قطعی بسازد.');
        $this->assertStringContainsString('اطمینان خواندن پایین', $row->message_fa);
    }

    public function test_field_only_one_document_has_is_skipped(): void
    {
        // تاریخ تولد فقط روی کارت ملی و گواهینامه است؛ اگر گواهینامه آن را نخواند
        // مقایسه بی‌معنی است و باید «قضاوت‌نشده» گزارش شود، نه پاس.
        $case = $this->makeCaseWithDocuments('issue');

        $this->extract($case, $this->data(omit: ['driving_license' => ['birth_date']]));

        (new DocumentValidator)->validate($case);

        $row = $this->ruleRow($case, 'cross.birth_date');

        $this->assertNotNull($row);
        $this->assertSame('skipped', $row->status);
        $this->assertStringContainsString('دست‌کم دو مدرک', $row->message_fa);
    }

    // ------------------------------------------------------------------
    // ۱) اعتبار تاریخ‌ها
    // ------------------------------------------------------------------

    public function test_expired_national_card_is_failed_with_both_dates_in_the_reason(): void
    {
        $case = $this->makeCaseWithDocuments('issue');

        $this->extract($case, $this->data([
            'national_card' => ['national_card_expire' => '۱۴۰۴/۰۲/۱۰'],
        ]));

        (new DocumentValidator)->validate($case);

        $row = $this->ruleRow($case, 'document.expired.national_card');

        $this->assertNotNull($row);
        $this->assertSame('document', $row->scope);
        $this->assertSame('failed', $row->status);
        $this->assertNotNull($row->case_document_id, 'ایراد تک‌مدرکی باید به همان مدرک بچسبد.');
        $this->assertStringContainsString('منقضی', $row->message_fa);
        $this->assertStringContainsString('۱۴۰۴/۰۲/۱۰', $row->message_fa);
        $this->assertStringContainsString(self::TODAY_JALALI, $row->message_fa);

        // گواهینامه هنوز معتبر است و همین را هم باید با جملهٔ خودش بگوید
        $license = $this->ruleRow($case, 'document.expired.driving_license');

        $this->assertNotNull($license);
        $this->assertSame('passed', $license->status);
        $this->assertStringContainsString('تا ۱۴۰۷/۰۹/۰۵ معتبر است', $license->message_fa);
    }

    public function test_renew_case_also_checks_the_previous_permit_expiry(): void
    {
        $case = $this->makeCaseWithDocuments('renew');

        $this->extract($case, $this->data([
            'previous_permit' => ['permit_expire_date' => '۱۴۰۴/۱۲/۰۱'],
        ]));

        (new DocumentValidator)->validate($case);

        $row = $this->ruleRow($case, 'document.expired.previous_permit');

        $this->assertNotNull($row, 'برای خدمت تمدید، تاریخ مجوز قبلی هم باید بررسی شود.');
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('مجوز قبلی', $row->message_fa);
        $this->assertStringContainsString('۱۴۰۴/۱۲/۰۱', $row->message_fa);
    }

    public function test_unreadable_date_is_a_warning_with_a_human_reason(): void
    {
        $case = $this->makeCaseWithDocuments('issue');

        $this->extract($case, $this->data([
            'national_card' => ['birth_date' => '۱۳۹۹/۱۳/۴۵'],
        ]));

        (new DocumentValidator)->validate($case);

        $row = $this->ruleRow($case, 'document.invalid_date.national_card');

        $this->assertNotNull($row);
        $this->assertSame('warning', $row->status);
        $this->assertStringContainsString('تاریخ تولد', $row->message_fa);
        $this->assertStringContainsString('خوانا نیست', $row->message_fa);
    }

    // ------------------------------------------------------------------
    // ۲) کامل بودن اطلاعات
    // ------------------------------------------------------------------

    public function test_empty_required_field_is_failed_and_names_the_field(): void
    {
        $case = $this->makeCaseWithDocuments('issue');

        $this->extract($case, $this->data(omit: [
            'national_card' => ['birth_date', 'father_name'],
        ]));

        (new DocumentValidator)->validate($case);

        $row = $this->ruleRow($case, 'document.missing_required.national_card');

        $this->assertNotNull($row);
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('تاریخ تولد', $row->message_fa);
        $this->assertStringContainsString('نام پدر', $row->message_fa);
        $this->assertSame(
            ['birth_date', 'father_name'],
            array_column($row->details['missing'], 'key'),
        );

        // فیلد غیراجباریِ خالی ایراد نیست
        $this->extract($case, $this->data(omit: [
            'driving_license' => ['license_expire_date'],
        ]));

        (new DocumentValidator)->validate($case);

        $license = $this->ruleRow($case, 'document.missing_required.driving_license');

        $this->assertNotNull($license);
        $this->assertSame('passed', $license->status);
    }

    public function test_missing_required_field_on_a_badly_read_document_is_only_a_warning(): void
    {
        $case = $this->makeCaseWithDocuments('issue');

        $this->extract($case, $this->data(omit: ['national_card' => ['birth_date']]));

        // همان پرونده، ولی خواندن کارت ملی ضعیف بوده
        ExtractedField::query()
            ->where('case_id', $case->id)
            ->whereIn('case_document_id', [$this->documentIdOf($case, 'national_card')])
            ->update(['confidence' => 22.0]);

        (new DocumentValidator)->validate($case);

        $row = $this->ruleRow($case, 'document.missing_required.national_card');

        $this->assertNotNull($row);
        $this->assertSame('warning', $row->status);
        $this->assertStringContainsString('کیفیت تصویر', $row->message_fa);
    }

    public function test_document_that_was_never_uploaded_is_skipped(): void
    {
        $case = PermitCase::factory()->ofService('renew')->for($this->expertUser(), 'user')->create();

        // فقط کارت ملی بارگذاری شده
        CaseDocument::factory()->prechecked()->create([
            'case_id' => $case->id,
            'document_type_id' => $this->documentTypeId('national_card'),
        ]);

        $this->extract($case->fresh(), $this->data());

        (new DocumentValidator)->validate($case);

        foreach (['driving_license', 'vehicle_card', 'previous_permit'] as $key) {
            $row = $this->ruleRow($case, 'document.missing_required.'.$key);

            $this->assertNotNull($row, "مدرک نیامدهٔ {$key} باید در نتیجه دیده شود.");
            $this->assertSame('skipped', $row->status);
            $this->assertStringContainsString('بارگذاری نشده', $row->message_fa);
        }
    }

    public function test_document_without_any_extracted_field_is_skipped(): void
    {
        $case = $this->makeCaseWithDocuments('issue');

        $this->extract($case, $this->data(omit: [
            'vehicle_card' => ['full_name', 'national_id', 'father_name', 'vin', 'plate_number'],
        ]));

        (new DocumentValidator)->validate($case);

        $row = $this->ruleRow($case, 'document.missing_required.vehicle_card');

        $this->assertNotNull($row);
        $this->assertSame('skipped', $row->status, 'وقتی OCR چیزی نخوانده نمی‌شود گفت مدرک ناقص است.');
        $this->assertStringContainsString('استخراج نشده', $row->message_fa);
    }

    // ------------------------------------------------------------------
    // پروندهٔ سالم، تکرارپذیری، و مرزهای مالکیت
    // ------------------------------------------------------------------

    public function test_healthy_case_gets_no_problem_rows_but_does_get_passed_rows(): void
    {
        $case = $this->makeCaseWithDocuments('renew');

        $this->extract($case, $this->data());

        (new DocumentValidator)->validate($case);

        $rows = ValidationResult::query()->where('case_id', $case->id)->get();

        $this->assertSame(
            0,
            $rows->whereIn('status', ['failed', 'warning', 'skipped'])->count(),
            'پروندهٔ سالم نباید هیچ ایرادی بگیرد: '
            .$rows->whereIn('status', ['failed', 'warning', 'skipped'])->pluck('message_fa')->implode(' | '),
        );

        // صفحهٔ نتیجه (تسک ۶۳۵) باید بتواند بگوید «کدام بررسی پاس شد»، پس نتیجهٔ
        // پاس هم ردیف دارد — وگرنه آن صفحه ناچار است کاتالوگ قواعد را هاردکد کند.
        $this->assertGreaterThan(0, $rows->count());
        $this->assertSame($rows->count(), $rows->where('status', 'passed')->count());

        // هر سه بررسی روی پروندهٔ «تمدید» دیده می‌شوند
        $keys = $rows->pluck('rule_key')->all();

        foreach ([
            'document.missing_required.national_card',
            'document.missing_required.previous_permit',
            'document.expired.national_card',
            'document.expired.previous_permit',
            'document.invalid_date.driving_license',
            'cross.national_id',
            'cross.full_name',
            'cross.birth_date',
        ] as $expected) {
            $this->assertContains($expected, $keys);
        }

        // جمله‌های اطمینان‌بخش، همان چیزی که کارشناس روی صفحه می‌بیند
        $this->assertSame(
            'کد ملی روی هر ۴ مدرک یکی است: ۰۰۱۲۳۴۵۶۷۸.',
            $this->ruleRow($case, 'cross.national_id')->message_fa,
        );
        $this->assertStringContainsString(
            'تا ۱۴۰۸/۰۳/۱۲ معتبر است',
            $this->ruleRow($case, 'document.expired.national_card')->message_fa,
        );

        // details هم مثل ایرادها پر است تا صفحه بتواند «چه دیدیم» را باز کند
        $details = $this->ruleRow($case, 'cross.national_id')->details;
        $this->assertSame('۰۰۱۲۳۴۵۶۷۸', $details['reference']['value']);
        $this->assertCount(3, $details['compared']);

        // و این کلاس هرگز ردیف scope=file نمی‌سازد؛ آن حوزه فقط ایراد می‌نویسد
        $this->assertSame(0, $rows->where('scope', 'file')->count());
    }

    public function test_thresholds_come_from_settings_not_from_code(): void
    {
        $case = $this->makeCaseWithDocuments('issue');

        // دو حرف از ده حرف خراب: با آستانهٔ پیش‌فرض ۸۵٪ «مشکوک» است
        $this->extract($case, $this->data([
            'driving_license' => ['full_name' => 'سمیرا کاطمو'],
        ]));

        (new DocumentValidator)->validate($case);
        $this->assertSame('warning', $this->ruleRow($case, 'cross.full_name')->status);

        // آستانه را شل می‌کنیم؛ همان داده باید پاس شود، بدون تغییر یک خط کد
        Setting::put('validation.limits', [
            'min_confidence' => 60,
            'name_match_min' => 70,
            'name_suspect_min' => 50,
        ]);

        (new DocumentValidator)->validate($case);
        $this->assertSame('passed', $this->ruleRow($case, 'cross.full_name')->status);
    }

    public function test_running_twice_does_not_duplicate_or_leave_stale_rows(): void
    {
        $case = $this->makeCaseWithDocuments('issue');

        $this->extract($case, $this->data([
            'driving_license' => ['national_id' => '۰۰۱۲۳۴۵۶۷۹'],
        ]));

        $validator = new DocumentValidator;
        $validator->validate($case);
        $validator->validate($case);

        $this->assertSame(
            1,
            ValidationResult::query()->where('case_id', $case->id)->where('rule_key', 'cross.national_id')->count(),
            'اجرای دوباره نباید ردیف تکراری بسازد.',
        );

        // کارشناس کد ملی را اصلاح می‌کند؛ ایراد باید ناپدید شود، نه اینکه بماند
        ExtractedField::query()
            ->where('case_id', $case->id)
            ->where('case_document_id', $this->documentIdOf($case, 'driving_license'))
            ->where('field_key', 'national_id')
            ->update(['normalized_value' => '۰۰۱۲۳۴۵۶۷۸', 'source' => 'manual']);

        $validator->validate($case);

        $fixed = $this->ruleRow($case, 'cross.national_id');

        $this->assertNotNull($fixed);
        $this->assertSame('passed', $fixed->status, 'نتیجهٔ بیات باید جای خود را به نتیجهٔ تازه بدهد.');
        $this->assertSame(
            1,
            ValidationResult::query()->where('case_id', $case->id)->where('rule_key', 'cross.national_id')->count(),
        );

        // قاعده‌ای که این اجرا اصلاً تولید نشده (مثلاً بازماندهٔ نسخهٔ قبلی کد)
        // نباید روی صفحهٔ نتیجه جا بماند
        ValidationResult::create([
            'case_id' => $case->id,
            'rule_key' => 'document.expired.a_rule_we_no_longer_emit',
            'scope' => 'document',
            'status' => 'failed',
            'message_fa' => 'قاعدهٔ بازمانده از اجرای قبلی.',
            'details' => [],
        ]);

        $validator->validate($case);

        $this->assertNull(
            $this->ruleRow($case, 'document.expired.a_rule_we_no_longer_emit'),
            'ردیف بیات باید پاک شود.',
        );
    }

    public function test_file_scope_rows_of_another_stage_are_left_alone(): void
    {
        $case = $this->makeCaseWithDocuments('issue');

        ValidationResult::create([
            'case_id' => $case->id,
            'case_document_id' => $this->documentIdOf($case, 'national_card'),
            'rule_key' => 'file.blurry',
            'scope' => 'file',
            'status' => 'warning',
            'message_fa' => 'تصویر تار است.',
            'details' => ['blur_score' => 12],
        ]);

        $this->extract($case, $this->data());

        (new DocumentValidator)->validate($case);

        $this->assertNotNull(
            ValidationResult::query()->where('case_id', $case->id)->where('scope', 'file')->first(),
            'اعتبارسنجی اسناد نباید نتیجهٔ اعتبارسنجی فایل را پاک کند.',
        );
    }

    // ------------------------------------------------------------------
    // طول کلید قاعده — ستون rule_key فقط ۶۰ نویسه جا دارد
    // ------------------------------------------------------------------

    /**
     * نوع مدرکی با کلید بلند نباید insert را بترکاند.
     *
     * پیشوند «document.missing_required.» ۲۶ نویسه است و document_types.key
     * تا ۴۰ نویسه مجاز است، یعنی کلید تا ۶۶ نویسه می‌رسد در حالی که ستون
     * validation_results.rule_key فقط varchar(60) است. روی MySQL این یعنی
     * «Data too long for column 'rule_key'» و کل مرحلهٔ اعتبارسنجی نیمه‌کاره
     * می‌ماند؛ روی sqlite تست‌ها طول varchar نادیده گرفته می‌شود، برای همین
     * این‌جا خود طول را ادعا می‌کنیم نه صرفاً «کرش نکردن» را.
     */
    public function test_long_document_type_key_does_not_overflow_the_rule_key_column(): void
    {
        $case = $this->makeCaseWithDocuments('issue');
        $this->addLongKeyDocument($case, 'commercial_transport_permit_renewal_a');
        $this->extract($case, $this->data());

        (new DocumentValidator)->validate($case);

        $rows = ValidationResult::query()->where('case_id', $case->id)->get();

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertLessThanOrEqual(
                60,
                mb_strlen((string) $row->rule_key),
                'کلید قاعده «'.$row->rule_key.'» از ستون varchar(60) بیرون می‌زند.',
            );
        }
    }

    /**
     * برشِ کلید نباید دو بررسی متفاوت را روی هم بیندازد.
     *
     * دو نوع مدرک که ۳۴ نویسهٔ اولِ کلیدشان یکی است، با برش سادهٔ ۶۰ نویسه‌ای
     * به یک rule_key می‌رسیدند؛ آن‌وقت updateOrCreate دومی را روی اولی
     * می‌نوشت و کارشناس فقط یکی از دو مدرک را در نتیجه می‌دید.
     */
    public function test_two_long_keys_with_the_same_prefix_stay_two_separate_rules(): void
    {
        $case = $this->makeCaseWithDocuments('issue');
        $first = $this->addLongKeyDocument($case, 'commercial_transport_permit_renewal_a');
        $second = $this->addLongKeyDocument($case, 'commercial_transport_permit_renewal_b');
        $this->extract($case, $this->data());

        $validator = new DocumentValidator;
        $validator->validate($case);

        $keyOf = fn (int $documentId): ?string => ValidationResult::query()
            ->where('case_id', $case->id)
            ->where('case_document_id', $documentId)
            ->where('scope', 'document')
            ->where('rule_key', 'like', 'document.missing_required.%')
            ->value('rule_key');

        $firstKey = $keyOf($first);
        $secondKey = $keyOf($second);

        $this->assertNotNull($firstKey, 'بررسی مدرک اول گم شده — احتمالاً روی کلید مدرک دوم نوشته شده است.');
        $this->assertNotNull($secondKey, 'بررسی مدرک دوم گم شده — احتمالاً روی کلید مدرک اول نوشته شده است.');
        $this->assertNotSame($secondKey, $firstKey, 'دو نوع مدرک متفاوت نباید یک کلید قاعده بگیرند.');

        // و اجرای دوباره نه ردیف تکراری بسازد نه یکی از این دو را «بیات» ببیند
        $validator->validate($case);

        $this->assertSame($firstKey, $keyOf($first), 'کلید کوتاه‌شده باید بین اجراها پایدار بماند.');
        $this->assertSame($secondKey, $keyOf($second));
        $this->assertSame(
            2,
            ValidationResult::query()
                ->where('case_id', $case->id)
                ->where('scope', 'document')
                ->where('rule_key', 'like', 'document.missing_required.%')
                ->whereIn('case_document_id', [$first, $second])
                ->count(),
        );
    }

    // ------------------------------------------------------------------
    // ابزار تست
    // ------------------------------------------------------------------

    /**
     * یک نوع مدرکِ تازه با کلید بلند، وصل‌شده به خدمت پرونده و بارگذاری‌شده روی آن.
     *
     * خروجی: شناسهٔ case_documents همان مدرک.
     */
    private function addLongKeyDocument(PermitCase $case, string $key): int
    {
        $type = DocumentType::create([
            'key' => $key,                       // ۳۷ نویسه — مجاز است، چون ستون ۴۰ نویسه دارد
            'label_fa' => 'مجوز حمل بار برون‌شهری',
            'is_generatable' => false,
            'is_active' => true,
            'sort' => 90,
        ]);

        DocumentTypeField::create([
            'document_type_id' => $type->id,
            'key' => 'permit_number',
            'label_fa' => 'شماره مجوز',
            'value_type' => 'digits',
            'is_required' => true,
            'is_cross_checked' => false,
            'sort' => 1,
        ]);

        $case->serviceType->documentTypes()->attach($type->id, ['is_required' => true, 'sort' => 90]);

        $document = CaseDocument::factory()->prechecked()->create([
            'case_id' => $case->id,
            'document_type_id' => $type->id,
        ]);

        $case->load(['documents.documentType.fields', 'serviceType.documentTypes.fields']);

        return (int) $document->id;
    }

    /**
     * مقادیر پایه با تغییرات دلخواه.
     *
     * @param  array<string, array<string, mixed>>  $overrides
     * @param  array<string, list<string>>  $omit
     * @return array<string, array<string, mixed>>
     */
    private function data(array $overrides = [], array $omit = []): array
    {
        $data = self::BASE;

        foreach ($overrides as $documentKey => $fields) {
            $data[$documentKey] = array_merge($data[$documentKey] ?? [], $fields);
        }

        foreach ($omit as $documentKey => $keys) {
            foreach ($keys as $key) {
                unset($data[$documentKey][$key]);
            }
        }

        return $data;
    }

    /**
     * ردیف‌های extracted_fields را مستقیم می‌سازد — FieldExtractor (تسک ۶۳۲) صدا زده نمی‌شود.
     *
     * @param  array<string, array<string, mixed>>  $data
     */
    private function extract(PermitCase $case, array $data, float $confidence = 95.0): void
    {
        $case->load('documents.documentType');

        ExtractedField::query()->where('case_id', $case->id)->delete();

        foreach ($case->documents as $document) {
            foreach ($data[$document->documentType->key] ?? [] as $fieldKey => $value) {
                $spec = is_array($value) ? $value : ['value' => $value];

                ExtractedField::create([
                    'case_id' => $case->id,
                    'case_document_id' => $document->id,
                    'field_key' => $fieldKey,
                    'raw_value' => $spec['value'],
                    'normalized_value' => $spec['value'],
                    'confidence' => $spec['confidence'] ?? $confidence,
                    'source' => $spec['source'] ?? 'ocr',
                ]);
            }

            $document->update(['ocr_status' => 'done']);
        }
    }

    private function documentIdOf(PermitCase $case, string $documentKey): int
    {
        return (int) $case->documents()
            ->where('document_type_id', $this->documentTypeId($documentKey))
            ->value('id');
    }

    private function ruleRow(PermitCase $case, string $ruleKey): ?ValidationResult
    {
        return ValidationResult::query()
            ->where('case_id', $case->id)
            ->where('rule_key', $ruleKey)
            ->first();
    }
}
