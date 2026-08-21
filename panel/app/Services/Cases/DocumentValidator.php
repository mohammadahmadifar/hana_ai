<?php

namespace App\Services\Cases;

use App\Models\DocumentType;
use App\Models\PermitCase;
use App\Models\Setting;
use App\Models\ValidationResult;
use App\Services\Cases\Validation\DocumentFields;
use App\Services\Cases\Validation\FieldValue;
use App\Support\Jalali;
use App\Support\PersianValue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * مرحلهٔ چهارم فرایند مجوز: اعتبارسنجی اسناد.
 *
 * سه بررسی روی کل پرونده انجام می‌شود و هرکدام یک ردیف ValidationResult با
 * دلیل فارسی می‌سازد تا صفحهٔ نتیجهٔ پرونده بتواند «چه دیدیم و چرا» را نشان دهد:
 *
 *   ۱) اعتبار تاریخ‌ها  → document.expired.<doc> و document.invalid_date.<doc>
 *   ۲) کامل بودن اطلاعات → document.missing_required.<doc>
 *   ۳) تطابق بین مدارک   → cross.<field>   ← مهم‌ترین بررسی ضدجعل سامانه
 *
 * ورودی فقط ردیف‌های extracted_fields است؛ این کلاس نه به فایل دست می‌زند نه
 * به موتور. پس اجرایش ارزان است و می‌شود بعد از هر اصلاح کارشناس دوباره زد.
 *
 * ── معنی چهار وضعیت (تسک ۶۳۴ روی همین تفکیک امتیاز می‌دهد) ──────────────
 *
 *   failed  «رد قطعی»    — داده‌ای که خوانده‌ایم با قاعده در تضاد است و خواندنش
 *                          هم قابل اتکاست (اطمینان ≥ آستانه یا دست‌نویس کارشناس).
 *   warning «مشکوک»      — همان تضاد، ولی دست‌کم یک طرفش با اطمینان پایین خوانده
 *                          شده، یا تاریخ ناخواناست، یا شباهت نام در بازهٔ خاکستری
 *                          است. یعنی «شاید خطای OCR باشد، انسان ببیند».
 *   skipped «قضاوت‌نشده» — مدرک بارگذاری نشده، یا OCR چیزی از آن نخوانده، یا فقط
 *                          یک مدرک آن فیلد را دارد و مقایسه بی‌معنی است.
 *   passed  «سالم»       — بررسی انجام شد و مشکلی نبود. ردیفش هم ذخیره می‌شود تا
 *                          صفحهٔ نتیجه بتواند جملهٔ اطمینان‌بخش را نشان دهد.
 *
 * قاعدهٔ کلیدی: هیچ «رد قطعی» روی خوانده‌شدهٔ کم‌اطمینان صادر نمی‌شود. موتور این
 * پروژه روی نام گواهینامه و پلاک ضعیف است (CLAUDE.md)؛ بدون این قاعده، هر
 * خطای OCR به «جعل» ترجمه می‌شد و سامانه پر از مثبت کاذب می‌شد.
 */
final class DocumentValidator
{
    /** فقط این دو حوزه مال این کلاس است؛ scope=file مال تسک ۶۳۰ است و دست نمی‌خورد. */
    private const OWNED_SCOPES = ['document', 'cross'];

    /**
     * کدام وضعیت‌ها در هر حوزه ردیف می‌گیرند.
     *
     * برای دو حوزهٔ این کلاس نتیجهٔ «passed» هم ذخیره می‌شود. دلیلش صفحهٔ
     * نتیجهٔ پرونده (تسک ۶۳۵) است: باید بگوید «کدام بررسی پاس شد و کدام رد»،
     * و اگر ردیف پاس نباشد آن صفحه ناچار است کاتالوگ قواعد را هاردکد کند تا
     * بفهمد چه چیزی بی‌ایراد بوده — یعنی هر قاعدهٔ تازه دو جا باید اضافه شود.
     * امتیازدهی هم مدل جریمه‌ای دارد (CaseScorer فقط failed/warning را
     * می‌شمارد)، پس ردیف پاس امتیاز پرونده را باد نمی‌کند.
     *
     * scope=file (تسک ۶۳۰) عمداً این‌جا نیست و همان‌طور که هست فقط ایراد
     * می‌نویسد: پاس‌بودن فایل از قبل روی case_documents.precheck_status و
     * اعداد blur/brightness ثبت است و بیست ردیف سبزِ «فرمت درست است» فقط
     * صفحهٔ نتیجه را شلوغ می‌کند.
     *
     * @var array<string, list<string>>
     */
    private const PERSISTED_STATUSES = [
        'document' => ['failed', 'warning', 'skipped', 'passed'],
        'cross' => ['failed', 'warning', 'skipped', 'passed'],
    ];

    /**
     * پل بین «شکل‌های مختلفِ یک دادهٔ واحد» روی مدارک مختلف.
     *
     * نام روی کارت ملی دو تکه است (نام + نام خانوادگی) و روی گواهینامه و کارت
     * خودرو یک‌تکه (نام و نام خانوادگی). اگر فقط کلیدهای هم‌نام مقایسه شوند،
     * مهم‌ترین بررسی ضدجعل هرگز اجرا نمی‌شود. این نگاشت شکل‌ها را به هم می‌رساند؛
     * تصمیم «کدام فیلد اصلاً تطابقی است» همچنان از is_cross_checked می‌آید.
     *
     * هر شکل به ترتیب اولویت آزموده می‌شود و اولین شکلی که همهٔ اجزایش روی آن
     * مدرک مقدار دارند برنده است.
     *
     * @var array<string, list<list<string>>>
     */
    private const COMPOSITE_SHAPES = [
        'full_name' => [
            ['full_name'],
            ['first_name', 'last_name'],
        ],
    ];

    /** تشخیص «فیلد تاریخ انقضا» از روی نام‌گذاری فیلدهای مرجع. */
    private const EXPIRY_KEY_PATTERN = '/(^|_)(expire|expiry|expiration|valid_until)(_|$)/';

    public function validate(PermitCase $case): void
    {
        // load و نه loadMissing: مرحلهٔ قبلِ پایپ‌لاین (استخراج فیلد) همین الان روی
        // همین نمونه ردیف نوشته است و رابطهٔ از پیش بارگذاری‌شده کهنه است.
        $case->load([
            'serviceType.documentTypes.fields',
            'documents.documentType.fields',
            'extractedFields',
        ]);

        $limits = $this->limits();
        $today = $this->today();
        $documents = $this->documents($case);

        $rows = [];

        foreach ($documents as $document) {
            foreach ($this->checkDocument($document, $limits, $today) as $row) {
                $rows[] = $row;
            }
        }

        foreach ($this->checkCrossDocuments($documents, $limits) as $row) {
            $rows[] = $row;
        }

        $this->persist($case, $rows);
    }

    // ------------------------------------------------------------------
    // تنظیمات و «امروز»
    // ------------------------------------------------------------------

    /**
     * آستانه‌ها از تنظیمات می‌آیند، نه از کد.
     *
     * کلید validation.limits ممکن است هنوز در ReferenceDataSeeder نباشد؛ طبق
     * قرارداد پایپ‌لاین در آن حالت مقدار پیش‌فرض امن می‌گذاریم و کرش نمی‌کنیم.
     *
     * name_match_min / name_suspect_min بر حسب درصد شباهت‌اند (۰ تا ۱۰۰) تا با
     * مقیاس confidence یکی باشند و در صفحهٔ تنظیمات گیج‌کننده نشوند.
     *
     * @return array{min_confidence: float, name_match_min: float, name_suspect_min: float}
     */
    private function limits(): array
    {
        $defaults = [
            'min_confidence' => 60.0,
            'name_match_min' => 85.0,
            'name_suspect_min' => 60.0,
        ];

        $stored = Setting::get('validation.limits');

        if (is_array($stored)) {
            foreach ($defaults as $key => $default) {
                if (isset($stored[$key]) && is_numeric($stored[$key])) {
                    $defaults[$key] = (float) $stored[$key];
                }
            }
        }

        return $defaults;
    }

    /**
     * امروز به تاریخ شمسی: [سال، ماه، روز].
     *
     * از Carbon خوانده می‌شود تا تست بتواند با Carbon::setTestNow() زمان را
     * قفل کند و سال دیگر هم سبز بماند. منطقه‌زمانی همان چیزی است که کاربر
     * ایرانی می‌بیند، وگرنه بین ساعت ۲۰:۳۰ تا ۲۴ یک روز اختلاف پیدا می‌کنیم.
     *
     * @return array{0:int,1:int,2:int}
     */
    private function today(): array
    {
        $timezone = 'Asia/Tehran';

        try {
            $configured = config('panel_menu.timezone') ?: config('app.timezone');

            if (is_string($configured) && $configured !== '') {
                $timezone = $configured;
            }
        } catch (\Throwable) {
            // بیرون از بستر لاراول — روی تهران می‌مانیم
        }

        $now = Carbon::now($timezone);

        return Jalali::fromGregorian((int) $now->year, (int) $now->month, (int) $now->day);
    }

    // ------------------------------------------------------------------
    // گردآوری مدارک
    // ------------------------------------------------------------------

    /**
     * مدارکِ در جریانِ پرونده — به ترتیب مدارک لازمِ همان خدمت.
     *
     * مدرکی که خدمت لازم دارد ولی بارگذاری نشده هم می‌آید (با document = null)
     * تا در نتیجه دیده شود، و مدرکی که اضافه بارگذاری شده هم بی‌بررسی نمی‌ماند.
     *
     * @return list<DocumentFields>
     */
    private function documents(PermitCase $case): array
    {
        /** @var Collection<int, DocumentType> $types */
        $types = $case->serviceType?->documentTypes ?? collect();
        $types = $types->keyBy('id');

        foreach ($case->documents as $document) {
            $type = $document->documentType;

            if ($type !== null && ! $types->has($type->id)) {
                $types->put($type->id, $type);
            }
        }

        $byType = $case->documents->groupBy('document_type_id');
        $fields = $case->extractedFields->groupBy('case_document_id');

        $out = [];

        foreach ($types as $type) {
            $document = $byType->get($type->id)?->sortByDesc('id')->first();

            $out[] = DocumentFields::make(
                $type,
                $document,
                $document ? ($fields->get($document->id) ?? collect()) : collect(),
            );
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // بررسی‌های تک‌مدرکی — scope=document
    // ------------------------------------------------------------------

    /**
     * @param  array{min_confidence: float, name_match_min: float, name_suspect_min: float}  $limits
     * @param  array{0:int,1:int,2:int}  $today
     * @return list<array<string, mixed>>
     */
    private function checkDocument(DocumentFields $document, array $limits, array $today): array
    {
        if (! $document->isUploaded()) {
            return [$this->row(
                'document',
                'document.missing_required.'.$document->key(),
                'skipped',
                'مدرک «'.$document->label().'» هنوز بارگذاری نشده است؛ کامل بودن اطلاعات آن بررسی نشد.',
                ['document' => $document->key(), 'label' => $document->label(), 'uploaded' => false],
            )];
        }

        return array_merge(
            [$this->checkCompleteness($document, $limits)],
            $this->checkDates($document, $limits, $today),
        );
    }

    /**
     * بررسی ۲: هیچ فیلد اجباری خالی نمانده باشد.
     *
     * «اجباری» از document_type_fields.is_required می‌آید، نه از کد.
     *
     * @param  array{min_confidence: float, name_match_min: float, name_suspect_min: float}  $limits
     * @return array<string, mixed>
     */
    private function checkCompleteness(DocumentFields $document, array $limits): array
    {
        $ruleKey = 'document.missing_required.'.$document->key();
        $required = array_filter(
            $document->allFields(),
            static fn ($field): bool => (bool) $field->is_required,
        );

        $details = [
            'document' => $document->key(),
            'label' => $document->label(),
            'required_count' => count($required),
            'extracted_rows' => $document->extractedRowCount(),
            'average_confidence' => round($document->averageConfidence(), 2),
        ];

        if ($required === []) {
            return $this->row('document', $ruleKey, 'passed',
                'برای «'.$document->label().'» فیلد اجباری تعریف نشده است.',
                $details, $document);
        }

        if (! $document->hasExtraction()) {
            return $this->row('document', $ruleKey, 'skipped',
                'هنوز هیچ فیلدی از «'.$document->label().'» استخراج نشده است'
                .' (وضعیت OCR: '.$this->ocrStatusLabel($document).')؛ کامل بودن اطلاعات بررسی نشد.',
                $details, $document);
        }

        $missing = $document->missingRequired();

        if ($missing === []) {
            return $this->row('document', $ruleKey, 'passed',
                'همهٔ '.$this->num(count($required)).' فیلد اجباری «'.$document->label().'» پر شده‌اند.',
                $details + ['missing' => []], $document);
        }

        $labels = array_map(static fn ($field): string => '«'.$field->label_fa.'»', $missing);
        $details['missing'] = array_map(static fn ($field): array => [
            'key' => $field->key,
            'label' => $field->label_fa,
        ], $missing);

        // اگر خواندنِ کل مدرک ضعیف بوده، نبودِ یک فیلد به‌احتمال زیاد خطای OCR است
        // نه نقص واقعی مدارک — پس «مشکوک»، نه «رد قطعی».
        $reliable = $document->isReliable($limits['min_confidence']);

        $message = $this->num(count($missing)).' فیلد اجباری «'.$document->label().'» خالی است: '
            .implode('، ', $labels).'.';

        if (! $reliable) {
            $message .= ' اطمینان خواندن این مدرک پایین است ('
                .$this->percent($document->averageConfidence()).')؛ ممکن است مشکل از کیفیت تصویر باشد.';
        }

        return $this->row('document', $ruleKey, $reliable ? 'failed' : 'warning', $message, $details, $document);
    }

    /**
     * بررسی ۱: اعتبار تاریخ‌ها.
     *
     * دو ردیف جدا می‌سازد چون دو حرف متفاوت می‌زنند:
     *   document.expired.<doc>      — تاریخ درست خوانده شده ولی گذشته است
     *   document.invalid_date.<doc> — تاریخ اصلاً معتبر نیست یا در آینده است
     *
     * «مجوز قبلی» جدا هاردکد نشده: چون فقط خدمت «تمدید» آن را لازم دارد، همین
     * که بین مدارک پرونده باشد تاریخش هم بررسی می‌شود.
     *
     * @param  array{min_confidence: float, name_match_min: float, name_suspect_min: float}  $limits
     * @param  array{0:int,1:int,2:int}  $today
     * @return list<array<string, mixed>>
     */
    private function checkDates(DocumentFields $document, array $limits, array $today): array
    {
        $dateFields = $document->dateFields();

        if ($dateFields === []) {
            return [];
        }

        $todayText = $this->jalaliText($today);
        $expiry = [];   // بررسی انقضا
        $shape = [];    // بررسی شکل/منطق تاریخ

        foreach ($dateFields as $field) {
            $isExpiry = preg_match(self::EXPIRY_KEY_PATTERN, $field->key) === 1;
            $value = $document->value($field->key);

            $entry = [
                'key' => $field->key,
                'label' => $field->label_fa,
                'is_expiry' => $isExpiry,
                'value' => $value?->display(),
                'confidence' => $value ? round($value->confidence, 2) : null,
            ];

            if ($value === null) {
                $entry['verdict'] = 'unread';
                $isExpiry ? $expiry[] = $entry : $shape[] = $entry;

                continue;
            }

            $problem = PersianValue::validate('jalali_date', $value->canonical, $field->label_fa);
            $parsed = $problem === null ? $this->parseJalali($value->canonical) : null;

            if ($parsed === null) {
                $entry['verdict'] = 'invalid';
                $entry['reason'] = $problem ?? 'شکل تاریخ خوانا نیست.';
                $shape[] = $entry;

                continue;
            }

            $entry['trusted'] = $value->isTrusted($limits['min_confidence']);

            if ($isExpiry) {
                $entry['verdict'] = $this->compareJalali($parsed, $today) < 0 ? 'expired' : 'valid';
                $expiry[] = $entry;

                continue;
            }

            // تاریخ تولد یا تاریخ صدور نمی‌تواند در آینده باشد
            $entry['verdict'] = $this->compareJalali($parsed, $today) > 0 ? 'future' : 'ok';
            $shape[] = $entry;
        }

        $rows = [];

        if ($expiry !== []) {
            $rows[] = $this->expiryRow($document, $expiry, $todayText);
        }

        $rows[] = $this->dateShapeRow($document, $shape, $expiry, $todayText);

        return array_values(array_filter($rows));
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function expiryRow(DocumentFields $document, array $entries, string $todayText): array
    {
        $ruleKey = 'document.expired.'.$document->key();
        $details = ['document' => $document->key(), 'label' => $document->label(), 'today' => $todayText, 'fields' => $entries];

        $expired = array_values(array_filter($entries, static fn (array $e): bool => $e['verdict'] === 'expired'));
        $valid = array_values(array_filter($entries, static fn (array $e): bool => $e['verdict'] === 'valid'));

        if ($expired !== []) {
            $trusted = array_values(array_filter($expired, static fn (array $e): bool => ($e['trusted'] ?? false) === true));
            $first = $trusted[0] ?? $expired[0];

            if ($trusted !== []) {
                return $this->row('document', $ruleKey, 'failed',
                    'مدرک «'.$document->label().'» منقضی شده است: «'.$first['label'].'» برابر '
                    .$first['value'].' است و امروز '.$todayText.' است.',
                    $details, $document);
            }

            return $this->row('document', $ruleKey, 'warning',
                'به‌نظر می‌رسد «'.$document->label().'» منقضی شده باشد («'.$first['label'].'»: '.$first['value']
                .' در برابر امروز '.$todayText.')، ولی این تاریخ با اطمینان پایین خوانده شده؛ کارشناس تاریخ را ببیند.',
                $details, $document);
        }

        if ($valid !== []) {
            return $this->row('document', $ruleKey, 'passed',
                'مدرک «'.$document->label().'» تا '.$valid[0]['value'].' معتبر است ('
                .$valid[0]['label'].'؛ امروز '.$todayText.').',
                $details, $document);
        }

        return $this->row('document', $ruleKey, 'skipped',
            'تاریخ انقضای «'.$document->label().'» خوانده نشد؛ اعتبار زمانی این مدرک بررسی نشد.',
            $details, $document);
    }

    /**
     * @param  list<array<string, mixed>>  $entries  تاریخ‌های غیرانقضا
     * @param  list<array<string, mixed>>  $expiryEntries  برای گزارش تاریخِ بدشکلِ انقضا هم لازم است
     * @return array<string, mixed>|null
     */
    private function dateShapeRow(DocumentFields $document, array $entries, array $expiryEntries, string $todayText): ?array
    {
        // تاریخ انقضایی که اصلاً خوانا نبوده در همین ردیف گزارش می‌شود، نه در ردیف انقضا
        foreach ($expiryEntries as $entry) {
            if ($entry['verdict'] === 'invalid') {
                $entries[] = $entry;
            }
        }

        if ($entries === []) {
            return null;
        }

        $ruleKey = 'document.invalid_date.'.$document->key();
        $details = ['document' => $document->key(), 'label' => $document->label(), 'today' => $todayText, 'fields' => $entries];

        $invalid = array_values(array_filter($entries, static fn (array $e): bool => $e['verdict'] === 'invalid'));
        $future = array_values(array_filter($entries, static fn (array $e): bool => $e['verdict'] === 'future'));
        $ok = array_values(array_filter($entries, static fn (array $e): bool => $e['verdict'] === 'ok'));

        if ($future !== []) {
            $first = $future[0];
            $trusted = ($first['trusted'] ?? false) === true;

            return $this->row('document', $ruleKey, $trusted ? 'failed' : 'warning',
                'تاریخ «'.$first['label'].'» روی «'.$document->label().'» برابر '.$first['value']
                .' است که از امروز ('.$todayText.') جلوتر است و ممکن نیست.'
                .($trusted ? '' : ' اطمینان خواندن پایین است؛ کارشناس بررسی کند.'),
                $details, $document);
        }

        if ($invalid !== []) {
            $first = $invalid[0];

            return $this->row('document', $ruleKey, 'warning',
                'تاریخ «'.$first['label'].'» روی «'.$document->label().'» خوانا نیست'
                .($first['value'] !== null ? ' (خوانده‌شده: '.$first['value'].')' : '').'. '
                .(string) ($first['reason'] ?? '').' این تاریخ باید دستی بررسی شود.',
                $details, $document);
        }

        if ($ok !== []) {
            return $this->row('document', $ruleKey, 'passed',
                (count($ok) === 1 ? 'تاریخِ خوانده‌شدهٔ «' : 'همهٔ '.$this->num(count($ok)).' تاریخِ خوانده‌شدهٔ «')
                .$document->label().'» شکل درستی '.(count($ok) === 1 ? 'دارد.' : 'دارند.'),
                $details, $document);
        }

        return $this->row('document', $ruleKey, 'skipped',
            'هیچ تاریخی از «'.$document->label().'» خوانده نشد؛ درستی تاریخ‌ها بررسی نشد.',
            $details, $document);
    }

    // ------------------------------------------------------------------
    // بررسی ۳: تطابق بین مدارک — scope=cross
    // ------------------------------------------------------------------

    /**
     * مهم‌ترین بررسی ضدجعل: کد ملی و نام روی همهٔ مدارک باید یکی باشد.
     *
     * فهرست فیلدهای تطابقی از document_type_fields.is_cross_checked می‌آید؛
     * این‌جا هیچ کلید فیلدی هاردکد نشده. تنها چیزی که کد می‌داند «شکل‌های
     * مختلف یک دادهٔ واحد» است (COMPOSITE_SHAPES) که مسئلهٔ نگارش است نه سیاست.
     *
     * @param  list<DocumentFields>  $documents
     * @param  array{min_confidence: float, name_match_min: float, name_suspect_min: float}  $limits
     * @return list<array<string, mixed>>
     */
    private function checkCrossDocuments(array $documents, array $limits): array
    {
        $rows = [];

        foreach ($this->activeCrossKeys($documents) as $logicalKey) {
            $values = [];

            foreach ($documents as $document) {
                $value = $this->resolveLogicalValue($document, $logicalKey);

                if ($value !== null) {
                    $values[] = $value;
                }
            }

            $rows[] = $this->crossRow($logicalKey, $values, $limits);
        }

        return $rows;
    }

    /**
     * کلیدهای منطقی‌ای که باید تطابق داشته باشند.
     *
     * یک کلید وقتی فعال است که دست‌کم روی یکی از انواع مدرکِ این پرونده
     * is_cross_checked داشته باشد. اگر کلید جزئی از یک دادهٔ مرکب باشد
     * (نام / نام خانوادگی)، همان دادهٔ مرکب فعال می‌شود.
     *
     * @param  list<DocumentFields>  $documents
     * @return list<string>
     */
    private function activeCrossKeys(array $documents): array
    {
        $keys = [];

        foreach ($documents as $document) {
            foreach ($document->allFields() as $field) {
                if (! $field->is_cross_checked) {
                    continue;
                }

                $logical = $this->logicalKey($field->key);

                if (! in_array($logical, $keys, true)) {
                    $keys[] = $logical;
                }
            }
        }

        return $keys;
    }

    /** کلید فیلد → کلید منطقی (نام و نام خانوادگی → نام کامل). */
    private function logicalKey(string $fieldKey): string
    {
        foreach (self::COMPOSITE_SHAPES as $logical => $shapes) {
            foreach ($shapes as $shape) {
                if (in_array($fieldKey, $shape, true)) {
                    return $logical;
                }
            }
        }

        return $fieldKey;
    }

    /**
     * مقدار یک کلید منطقی روی یک مدرک — با هر شکلی که آن مدرک دارد.
     *
     * توجه: اگر کلید منطقی فعال باشد، هر مدرکی که بتواند مقدارش را بدهد در
     * مقایسه شرکت می‌کند، حتی اگر پرچم is_cross_checked خودِ آن فیلد خاموش
     * باشد. دلیلش این است که full_name گواهینامه همان دادهٔ first_name +
     * last_name کارت ملی است؛ اگر شرکت نکند، بررسی نام هرگز اجرا نمی‌شود.
     */
    private function resolveLogicalValue(DocumentFields $document, string $logicalKey): ?FieldValue
    {
        $shapes = self::COMPOSITE_SHAPES[$logicalKey] ?? [[$logicalKey]];

        foreach ($shapes as $shape) {
            $parts = [];

            foreach ($shape as $fieldKey) {
                $value = $document->value($fieldKey);

                if ($value === null) {
                    continue 2; // این شکل روی این مدرک کامل نیست؛ شکل بعدی
                }

                $parts[] = $value;
            }

            if ($parts === []) {
                continue;
            }

            return count($parts) === 1 ? $parts[0] : FieldValue::composite($parts, $logicalKey);
        }

        return null;
    }

    /**
     * @param  list<FieldValue>  $values
     * @param  array{min_confidence: float, name_match_min: float, name_suspect_min: float}  $limits
     * @return array<string, mixed>
     */
    private function crossRow(string $logicalKey, array $values, array $limits): array
    {
        $ruleKey = 'cross.'.$logicalKey;
        $label = $this->crossLabel($logicalKey, $values);

        if (count($values) === 0) {
            return $this->row('cross', $ruleKey, 'skipped',
                'هیچ مدرکی مقدار «'.$label.'» را نداده است؛ تطابق بین مدارک بررسی نشد.',
                ['field' => $logicalKey, 'label' => $label, 'values' => []]);
        }

        if (count($values) === 1) {
            return $this->row('cross', $ruleKey, 'skipped',
                'فقط «'.$values[0]->documentLabel.'» مقدار «'.$label.'» را دارد ('.$values[0]->display()
                .')؛ برای مقایسه دست‌کم دو مدرک لازم است.',
                ['field' => $logicalKey, 'label' => $label, 'values' => [$values[0]->toArray()]]);
        }

        // مرجع مقایسه: اولین مدرک به ترتیب مدارک لازم خدمت (کارت ملی، سند هویتی پایه)
        $reference = $values[0];
        $fuzzy = $reference->valueType === 'text';

        $comparisons = [];
        $mismatch = null;
        $mismatchTrusted = false;
        $mismatchCount = 0;
        $suspect = null;

        foreach (array_slice($values, 1) as $value) {
            $similarity = $fuzzy
                ? $this->similarity($reference->compareKey(), $value->compareKey())
                : ($reference->compareKey() === $value->compareKey() ? 100.0 : 0.0);

            $verdict = match (true) {
                ! $fuzzy => $similarity >= 100.0 ? 'match' : 'mismatch',
                $similarity >= $limits['name_match_min'] => 'match',
                $similarity >= $limits['name_suspect_min'] => 'suspect',
                default => 'mismatch',
            };

            $comparisons[] = $value->toArray() + ['verdict' => $verdict, 'similarity' => round($similarity, 1)];

            if ($verdict === 'mismatch') {
                $mismatchCount++;
                $trusted = $reference->isTrusted($limits['min_confidence'])
                    && $value->isTrusted($limits['min_confidence']);

                if ($mismatch === null || ($trusted && ! $mismatchTrusted)) {
                    $mismatch = [$value, $similarity];
                    $mismatchTrusted = $trusted;
                }
            } elseif ($verdict === 'suspect' && $suspect === null) {
                $suspect = [$value, $similarity];
            }
        }

        $details = [
            'field' => $logicalKey,
            'label' => $label,
            'match_mode' => $fuzzy ? 'fuzzy' : 'exact',
            'reference' => $reference->toArray(),
            'compared' => $comparisons,
            'thresholds' => $limits,
        ];

        if ($mismatch !== null) {
            [$value, $similarity] = $mismatch;

            $message = $label.' روی '.$value->documentLabel.' ('.$value->display().') با '
                .$label.' روی '.$reference->documentLabel.' ('.$reference->display().') یکی نیست.';

            if ($fuzzy) {
                $message .= ' شباهت دو نام فقط '.$this->percent($similarity).' است.';
            }

            if ($mismatchCount > 1) {
                $message .= ' '.$this->num($mismatchCount).' مدرک با هم اختلاف دارند.';
            }

            if (! $mismatchTrusted) {
                $message .= ' اطمینان خواندن پایین است، پس ممکن است خطای OCR باشد؛ کارشناس ببیند.';
            }

            return $this->row('cross', $ruleKey, $mismatchTrusted ? 'failed' : 'warning', $message, $details);
        }

        if ($suspect !== null) {
            [$value, $similarity] = $suspect;

            return $this->row('cross', $ruleKey, 'warning',
                $label.' روی '.$value->documentLabel.' ('.$value->display().') با '
                .$reference->documentLabel.' ('.$reference->display().') کاملاً یکی نیست؛ شباهت '
                .$this->percent($similarity).' است. احتمال خطای خواندن هست، ولی باید کارشناس تأیید کند.',
                $details);
        }

        return $this->row('cross', $ruleKey, 'passed',
            $label.' روی هر '.$this->num(count($values)).' مدرک یکی است: '.$reference->display().'.',
            $details);
    }

    /**
     * برچسب فارسی یک کلید منطقی — از برچسب همان فیلد روی مدارک می‌آید، نه از کد.
     * برای دادهٔ مرکب، برچسب اجزا با «و» به هم وصل می‌شود («نام» + «نام خانوادگی»).
     *
     * @param  list<FieldValue>  $values
     */
    private function crossLabel(string $logicalKey, array $values): string
    {
        foreach ($values as $value) {
            if (count($value->partKeys) === 1) {
                return $value->fieldLabel;
            }
        }

        return $values[0]->fieldLabel ?? $logicalKey;
    }

    // ------------------------------------------------------------------
    // ابزار
    // ------------------------------------------------------------------

    /**
     * درصد شباهت دو رشتهٔ ساده‌شده (خروجی PersianValue::compareKey).
     *
     * فاصلهٔ ویرایشی روی «نقطه‌کد»ها حساب می‌شود نه بایت‌ها؛ levenshtein توکار
     * PHP بایتی است و برای فارسی هر حرف را سه برابر می‌شمارد.
     */
    private function similarity(string $a, string $b): float
    {
        if ($a === '' && $b === '') {
            return 100.0;
        }

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 100.0;
        }

        $first = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $second = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // نام‌ها کوتاه‌اند؛ سقف می‌گذاریم تا یک متن پرت، مقایسه را گران نکند
        $first = array_slice($first, 0, 120);
        $second = array_slice($second, 0, 120);

        $distance = $this->editDistance($first, $second);
        $longest = max(count($first), count($second));

        return max(0.0, (1 - ($distance / $longest)) * 100);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function editDistance(array $a, array $b): int
    {
        $rows = count($a);
        $cols = count($b);

        $previous = range(0, $cols);

        for ($i = 1; $i <= $rows; $i++) {
            $current = [$i];

            for ($j = 1; $j <= $cols; $j++) {
                $current[$j] = min(
                    $previous[$j] + 1,                                        // حذف
                    $current[$j - 1] + 1,                                     // درج
                    $previous[$j - 1] + ($a[$i - 1] === $b[$j - 1] ? 0 : 1),  // جایگزینی
                );
            }

            $previous = $current;
        }

        return $previous[$cols];
    }

    /** @return array{0:int,1:int,2:int}|null */
    private function parseJalali(string $canonical): ?array
    {
        $plain = PersianValue::toEnglishDigits($canonical);

        if (preg_match('/^([0-9]{4})\/([0-9]{1,2})\/([0-9]{1,2})$/', $plain, $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    /**
     * @param  array{0:int,1:int,2:int}  $a
     * @param  array{0:int,1:int,2:int}  $b
     */
    private function compareJalali(array $a, array $b): int
    {
        return $a <=> $b; // هر دو شمسی‌اند؛ مقایسهٔ سه‌تایی سال/ماه/روز کافی است
    }

    /** @param  array{0:int,1:int,2:int}  $date */
    private function jalaliText(array $date): string
    {
        return Jalali::digits(sprintf('%04d/%02d/%02d', $date[0], $date[1], $date[2]));
    }

    private function num(int|float $value): string
    {
        return PersianValue::toPersianDigits((string) $value);
    }

    private function percent(float $value): string
    {
        return PersianValue::toPersianDigits((string) (int) round($value)).'٪';
    }

    private function ocrStatusLabel(DocumentFields $document): string
    {
        return match ($document->document?->ocr_status) {
            'pending' => 'در نوبت',
            'queued' => 'در صف',
            'running' => 'در حال اجرا',
            'done' => 'انجام‌شده',
            'failed' => 'ناموفق',
            default => 'نامشخص',
        };
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function row(
        string $scope,
        string $ruleKey,
        string $status,
        string $message,
        array $details,
        ?DocumentFields $document = null,
    ): array {
        return [
            'scope' => $scope,
            'rule_key' => $ruleKey,
            'status' => $status,
            'message_fa' => $this->clip(PersianValue::normalize($message)),
            'details' => $details,
            'case_document_id' => $document?->document?->id,
        ];
    }

    /** ستون message_fa حداکثر ۲۵۵ نویسه است. */
    private function clip(string $message): string
    {
        return mb_strlen($message) <= 255 ? $message : mb_substr($message, 0, 254).'…';
    }

    /**
     * ذخیرهٔ idempotent.
     *
     * کلید یکتا: (case_id, rule_key) — پس اجرای دوباره ردیف تکراری نمی‌سازد و
     * فقط همان ردیف را به‌روز می‌کند (وضعیت پاس→رد و برعکس هم درست جابه‌جا
     * می‌شود). هر ردیفِ قدیمیِ همین دو حوزه که این بار اصلاً ساخته نشده — مثلاً
     * قاعده‌ای که دیگر موضوعیت ندارد چون مدرکش حذف شده — پاک می‌شود تا نتیجهٔ
     * بیات روی صفحه نماند. ردیف‌های scope=file (مال تسک ۶۳۰) دست‌نخورده می‌مانند.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function persist(PermitCase $case, array $rows): void
    {
        $keys = [];

        foreach ($rows as $row) {
            if (! in_array($row['status'], self::PERSISTED_STATUSES[$row['scope']] ?? [], true)) {
                continue; // این حوزه این وضعیت را ذخیره نمی‌کند؛ ردیف قدیمی‌اش پایین‌تر پاک می‌شود
            }

            ValidationResult::updateOrCreate(
                ['case_id' => $case->id, 'rule_key' => $row['rule_key']],
                [
                    'case_document_id' => $row['case_document_id'],
                    'scope' => $row['scope'],
                    'status' => $row['status'],
                    'message_fa' => $row['message_fa'],
                    'details' => $row['details'],
                ],
            );

            $keys[] = $row['rule_key'];
        }

        $stale = ValidationResult::query()
            ->where('case_id', $case->id)
            ->whereIn('scope', self::OWNED_SCOPES);

        if ($keys !== []) {
            $stale->whereNotIn('rule_key', $keys);
        }

        $stale->delete();

        $case->unsetRelation('validationResults');
    }
}
