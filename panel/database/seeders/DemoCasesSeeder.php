<?php

namespace Database\Seeders;

use App\Models\CaseDocument;
use App\Models\DocumentType;
use App\Models\DocumentTypeField;
use App\Models\ExtractedField;
use App\Models\PermitCase;
use App\Models\ScoreComponent;
use App\Models\ServiceType;
use App\Models\Setting;
use App\Models\User;
use App\Models\ValidationResult;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * بیست پروندهٔ نمایشی برای داشبورد و صفحهٔ گزارش‌ها.
 *
 *     php artisan db:seed --class=DemoCasesSeeder
 *
 * چرا جداست: `DatabaseSeeder` فقط دادهٔ مرجع و کاربران اولیه را می‌سازد و
 * نباید با دادهٔ نمایشی قاطی شود. این seeder دست به آن نمی‌زند و خودش
 * idempotent است — هر بار اجرا، پرونده‌های نمایشی قبلی (کد `HA-DEMO-…`)
 * را پاک و از نو می‌سازد و به هیچ پروندهٔ واقعی کار ندارد.
 *
 * ⚠️ قانون پروژه: هیچ مدرک هویتی واقعی. نام‌ها «متقاضی نمونه شمارهٔ N»،
 * کدهای ملی از الگوی آشکارِ 000000NNNN، شماره شاسی با پیشوند DEMOVIN و
 * هیچ فایل تصویری واقعی‌ای ساخته نمی‌شود (فقط مسیر روی دیسک خصوصی ثبت
 * می‌شود؛ داشبورد و گزارش‌ها به فایل نیاز ندارند).
 *
 * اعداد عمداً واقع‌نما هستند و ضعف‌های شناخته‌شدهٔ موتور را بازتاب می‌دهند
 * (پلاک و «نام» گواهینامه بدترین‌اند، کد ملی بهترین) تا صفحهٔ گزارش‌ها
 * همان چیزی را نشان بدهد که سند وضعیت موتور می‌گوید.
 */
class DemoCasesSeeder extends Seeder
{
    /** پیشوند کد پرونده‌های نمایشی — کلید پاک‌سازی و تشخیص. */
    public const CODE_PREFIX = 'HA-DEMO-';

    /** چند پرونده ساخته شود. */
    public const CASE_COUNT = 20;

    /**
     * وضعیت هر پرونده به ترتیب. جمع = CASE_COUNT و همهٔ وضعیت‌های
     * PermitCase::STATUSES دست‌کم یک نماینده دارند تا نمودار توزیع خالی نماند.
     *
     * @var list<string>
     */
    private const STATUS_PLAN = [
        'approved', 'needs_review', 'rejected', 'approved', 'needs_review',
        'approved', 'submitted', 'needs_review', 'rejected', 'approved',
        'needs_review', 'processing', 'approved', 'rejected', 'needs_review',
        'approved', 'draft', 'rejected', 'needs_review', 'approved',
    ];

    /**
     * میانگین اطمینان پایه هر فیلد (۰..۱۰۰).
     *
     * از «وضعیت شناخته‌شدهٔ موتور» در CLAUDE.md می‌آید: پلاک عملاً خوانده
     * نمی‌شود، نام روی گواهینامه محو می‌شود، VIN اغلب درست ولی با فاصلهٔ
     * اضافه است، و کد ملی بهترین وضع را دارد.
     *
     * @var array<string, float>
     */
    private const FIELD_BASE_CONFIDENCE = [
        'plate_number' => 21.0,
        'full_name' => 37.0,
        'vin' => 54.0,
        'father_name' => 61.0,
        'license_expire_date' => 65.0,
        'license_issue_date' => 67.0,
        'birth_date' => 70.0,
        'national_card_expire' => 71.0,
        'license_number' => 72.0,
        'permit_expire_date' => 72.0,
        'permit_issue_date' => 73.0,
        'permit_number' => 74.0,
        'first_name' => 78.0,
        'last_name' => 79.0,
        'national_id' => 84.0,
    ];

    /** میانگین پایه برای فیلدی که در فهرست بالا نیست. */
    private const FIELD_BASE_FALLBACK = 65.0;

    /** کش فیلدهای «تطابق بین مدارک» — یک کوئری برای کل اجرا.
     *
     * @var array<string, string>|null
     */
    private ?array $crossFields = null;

    public function run(): void
    {
        // دادهٔ مرجع لازم است (انواع مدرک و خدمت). idempotent است، پس اگر
        // از قبل اجرا شده باشد چیزی خراب نمی‌شود.
        if (! ServiceType::query()->exists() || ! DocumentType::query()->exists()) {
            $this->call(ReferenceDataSeeder::class);
        }

        $services = ServiceType::query()
            ->with(['documentTypes.fields'])
            ->whereIn('key', ['issue', 'renew'])
            ->get()
            ->keyBy('key');

        if ($services->isEmpty()) {
            $this->command?->warn('DemoCasesSeeder: نوع خدمتی در دیتابیس نیست؛ چیزی ساخته نشد.');

            return;
        }

        $this->purgePreviousDemo();

        $owner = $this->demoOwner();
        $reviewer = $this->demoReviewer($owner);
        $weights = $this->scoringWeights();

        // ترتیب اعداد باید بین اجراها یکی باشد تا تست و اسکرین‌شات نلرزد.
        mt_srand(14050636);

        $now = Carbon::now();

        foreach (self::STATUS_PLAN as $index => $status) {
            $number = $index + 1;
            $serviceKey = $number % 3 === 0 ? 'renew' : 'issue';
            $service = $services[$serviceKey] ?? $services->first();

            $this->makeCase($number, $status, $service, $owner, $reviewer, $weights, $now);
        }

        mt_srand();

        $this->command?->info(
            'DemoCasesSeeder: '.self::CASE_COUNT.' پروندهٔ نمایشی ساخته شد (کد '.self::CODE_PREFIX.'0001 به بعد).'
        );
    }

    /** پرونده‌های نمایشی اجرای قبلی و همهٔ ردیف‌های وابسته‌شان. */
    private function purgePreviousDemo(): void
    {
        $ids = PermitCase::query()
            ->where('code', 'like', self::CODE_PREFIX.'%')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        // صریح پاک می‌کنیم و به cascade دیتابیس تکیه نمی‌کنیم: در sqlite
        // اجبار کلید خارجی می‌تواند خاموش باشد و ردیف‌های یتیم بمانند.
        ExtractedField::query()->whereIn('case_id', $ids)->delete();
        ValidationResult::query()->whereIn('case_id', $ids)->delete();
        ScoreComponent::query()->whereIn('case_id', $ids)->delete();

        $documentIds = CaseDocument::query()->whereIn('case_id', $ids)->pluck('id');

        if ($documentIds->isNotEmpty()) {
            DB::table('ocr_runs')
                ->where('subject_type', CaseDocument::class)
                ->whereIn('subject_id', $documentIds)
                ->delete();
        }

        CaseDocument::query()->whereIn('case_id', $ids)->delete();
        PermitCase::query()->whereIn('id', $ids)->delete();
    }

    /** صاحب پرونده‌های نمایشی: کارشناس موجود، وگرنه یک کاربر نمایشی. */
    private function demoOwner(): User
    {
        $user = User::query()->whereIn('role', ['expert', 'admin'])->orderBy('id')->first();

        if ($user) {
            return $user;
        }

        $user = new User;
        $user->forceFill([
            'name' => 'کارشناس نمونه',
            'email' => 'demo.expert@hana.local',
            'password' => Hash::make(env('SEED_PASSWORD', 'hana@1405')),
            'role' => 'expert',
            'is_active' => true,
        ])->save();

        return $user;
    }

    /** کسی که تصمیم دستی به نامش ثبت می‌شود (اگر مدیری هست، او). */
    private function demoReviewer(User $fallback): User
    {
        return User::query()->where('role', 'admin')->orderBy('id')->first() ?? $fallback;
    }

    /**
     * وزن مؤلفه‌های امتیاز — از settings، با پیش‌فرض امن اگر کلید نبود.
     *
     * @return array<string, float>
     */
    private function scoringWeights(): array
    {
        $weights = Setting::get('scoring.weights');
        $weights = is_array($weights) ? $weights : [];

        return [
            'ocr_quality' => (float) ($weights['ocr_quality'] ?? 40),
            'validation' => (float) ($weights['validation'] ?? 40),
            'completeness' => (float) ($weights['completeness'] ?? 20),
        ];
    }

    /**
     * یک پروندهٔ نمایشی کامل: مدارک، فیلدهای استخراج‌شده، ایرادها و مؤلفه‌های امتیاز.
     *
     * @param  array<string, float>  $weights
     */
    private function makeCase(
        int $number,
        string $status,
        ServiceType $service,
        User $owner,
        User $reviewer,
        array $weights,
        Carbon $now,
    ): void {
        $code = self::CODE_PREFIX.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
        $nationalId = '000000'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);

        // پرونده‌ها روی ۲۰ روز گذشته پخش می‌شوند؛ قدیمی‌ترین شماره ۱.
        $createdAt = $now->copy()->subDays(self::CASE_COUNT - $number)->subHours(($number * 7) % 24);
        $submittedAt = in_array($status, ['draft'], true) ? null : $createdAt->copy()->addMinutes(12);

        $isDecided = in_array($status, ['approved', 'rejected', 'needs_review'], true);

        $confidence = match ($status) {
            'approved' => $this->between(81.0, 96.0),
            'rejected' => $this->between(18.0, 43.0),
            'needs_review' => $this->between(47.0, 78.0),
            default => null,
        };

        $processingMs = $isDecided ? mt_rand(3_800, 15_400) : null;
        $processedAt = $isDecided ? $submittedAt?->copy()->addMilliseconds($processingMs) : null;

        // یک تصمیم از هر سه دسته دستی است تا ستون «تصمیم کارشناس» هم داده داشته باشد.
        $isManual = $isDecided && $number % 7 === 0;

        $case = new PermitCase;
        $case->forceFill([
            'code' => $code,
            'user_id' => $owner->id,
            'service_type_id' => $service->id,
            'applicant_name' => 'متقاضی نمونه شمارهٔ '.$number,
            'applicant_national_id' => $nationalId,
            'status' => $status,
            'confidence_score' => $confidence === null ? null : round($confidence, 2),
            'decision' => $isDecided ? $status : null,
            'decision_reason' => $isDecided ? $this->decisionReason($status) : null,
            'decision_is_manual' => $isManual,
            'decided_by' => $isManual ? $reviewer->id : null,
            'decided_at' => $isDecided ? $processedAt : null,
            'submitted_at' => $submittedAt,
            'processed_at' => $processedAt,
            'processing_ms' => $processingMs,
            'created_at' => $createdAt,
            'updated_at' => $processedAt ?? $submittedAt ?? $createdAt,
        ])->save();

        $documents = $this->makeDocuments($case, $service, $status, $createdAt);

        if ($status === 'draft' || $status === 'submitted') {
            // هنوز OCR نشده — نه فیلدی، نه ایرادی، نه امتیازی.
            return;
        }

        $this->makeExtractedFields($case, $documents, $status, $owner, $createdAt);

        if ($status === 'processing') {
            return;
        }

        $this->makeValidationResults($case, $documents, $status, $createdAt);
        $this->makeScoreComponents($case, $status, (float) $confidence, $weights, $createdAt);
    }

    /**
     * مدارک لازم همان خدمت. هیچ فایل واقعی ساخته نمی‌شود؛ فقط مسیر روی دیسک
     * خصوصی ثبت می‌شود، چون داشبورد و گزارش‌ها فقط به رکورد نیاز دارند.
     *
     * @return array<int, CaseDocument> کلید = document_type_id
     */
    private function makeDocuments(PermitCase $case, ServiceType $service, string $status, Carbon $createdAt): array
    {
        $ocrStatus = match ($status) {
            'draft' => 'pending',
            'submitted' => 'queued',
            'processing' => 'running',
            default => 'done',
        };

        $documents = [];
        $index = 0;

        foreach ($service->documentTypes as $type) {
            $index++;

            // در پرونده‌های ردشده یک مدرک عمداً از اعتبارسنجی اولیه رد می‌شود.
            $failsPrecheck = $status === 'rejected' && $index === 2;

            $document = new CaseDocument;
            $document->forceFill([
                'case_id' => $case->id,
                'document_type_id' => $type->id,
                'disk' => 'documents',
                'path' => 'demo/'.$case->code.'/'.$type->key.'.png',
                'original_name' => $type->key.'-نمونه.png',
                'mime' => 'image/png',
                'size_bytes' => mt_rand(180_000, 900_000),
                'width' => $type->key === 'driving_license' ? 1537 : 960,
                'height' => $type->key === 'driving_license' ? 1023 : 540,
                'checksum' => hash('sha256', $case->code.'|'.$type->key),
                'precheck_status' => $failsPrecheck ? 'failed' : 'passed',
                'precheck_issues' => $failsPrecheck
                    ? [[
                        'code' => 'file.blurry',
                        'message_fa' => 'تصویر مدرک تار است؛ با نور کافی و بدون لرزش دوباره عکس بگیرید.',
                        'details' => ['blur_score' => 31.4],
                        'severity' => 'error',
                    ]]
                    : null,
                'blur_score' => $failsPrecheck ? 31.4 : $this->between(72.0, 180.0),
                'brightness_score' => $this->between(96.0, 178.0),
                'ocr_status' => $failsPrecheck ? 'failed' : $ocrStatus,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();

            // رابطه را دستی می‌بندیم تا مرحله‌های بعد به‌ازای هر مدرک دوباره
            // کوئری نزنند (نوع مدرک و فیلدهایش از قبل eager load شده‌اند).
            $document->setRelation('documentType', $type);

            $documents[$type->id] = $document;
        }

        return $documents;
    }

    /**
     * فیلدهای استخراج‌شده — قلب گزارش «ضعیف‌ترین فیلدها».
     *
     * @param  array<int, CaseDocument>  $documents
     */
    private function makeExtractedFields(
        PermitCase $case,
        array $documents,
        string $status,
        User $owner,
        Carbon $createdAt,
    ): void {
        // پرونده‌های تاییدشده خواندن بهتری داشته‌اند و ردشده‌ها بدتر؛ همین
        // همبستگی است که امتیاز اطمینان را معنادار می‌کند.
        $shift = match ($status) {
            'approved' => 11.0,
            'rejected' => -13.0,
            default => 0.0,
        };

        $rows = [];
        $stamp = $createdAt->copy()->addMinutes(14);

        foreach ($documents as $document) {
            if ($document->ocr_status === 'failed') {
                continue;
            }

            $type = $document->documentType;

            if ($type === null) {
                continue;
            }

            foreach ($type->fields as $field) {
                $base = self::FIELD_BASE_CONFIDENCE[$field->key] ?? self::FIELD_BASE_FALLBACK;
                $confidence = max(4.0, min(99.0, $base + $shift + $this->between(-7.0, 7.0)));

                // فیلدی که ضعیف خوانده شده، مقدار خامش هم به‌هم‌ریخته است.
                $clean = $this->sampleValue($field->key, $field->value_type, $case);
                $raw = $confidence < 50 ? $this->garble($clean) : $clean;

                // چند فیلدِ خیلی ضعیف را کارشناس دستی اصلاح کرده است.
                $corrected = $confidence < 32 && $case->id % 3 === 0;

                $rows[] = [
                    'case_id' => $case->id,
                    'case_document_id' => $document->id,
                    'field_key' => $field->key,
                    'raw_value' => $raw,
                    'normalized_value' => $corrected ? $clean : ($confidence < 50 ? null : $clean),
                    'confidence' => round($confidence, 2),
                    'source' => $corrected ? 'manual' : 'ocr',
                    'corrected_by' => $corrected ? $owner->id : null,
                    'corrected_at' => $corrected ? $stamp : null,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ];
            }
        }

        if ($rows !== []) {
            ExtractedField::query()->insert($rows);
        }
    }

    /**
     * نتیجهٔ بررسی‌های اعتبارسنجی.
     *
     * قرارداد سامانه (به‌روزرسانی HA-S3): حوزه‌های `document` و `cross` نتیجهٔ
     * *همهٔ* بررسی‌ها را ذخیره می‌کنند — `passed` هم — چون صفحهٔ نتیجهٔ پرونده
     * باید بگوید کدام بررسی پاس شد و کدام رد. فقط حوزهٔ `file` همچنان تنها
     * برای ایراد ردیف می‌نویسد.
     *
     * پس دادهٔ نمایشی هم باید ردیف `passed` داشته باشد، وگرنه گزارش «دلایل رد»
     * روی دادهٔ واقعی رفتار دیگری نشان می‌دهد: اول همهٔ بررسی‌های ممکن با
     * وضعیت `passed` ساخته می‌شوند و بعد ایرادها رویشان می‌نشینند.
     *
     * @param  array<int, CaseDocument>  $documents
     */
    private function makeValidationResults(
        PermitCase $case,
        array $documents,
        string $status,
        Carbon $createdAt,
    ): void {
        $stamp = $createdAt->copy()->addMinutes(15);
        $documentKey = fn (CaseDocument $d) => $d->documentType?->key ?? 'document';

        // کلید یکتا (case_id, rule_key): ردیف بعدی روی قبلی می‌نشیند، پس
        // اول همهٔ بررسی‌ها «پاس» می‌شوند و بعد ایرادها جایشان را می‌گیرند.
        $rows = [];

        $push = function (string $ruleKey, string $scope, string $state, string $message, ?CaseDocument $document = null) use (&$rows, $case, $stamp): void {
            $rows[mb_substr($ruleKey, 0, 60)] = [
                'case_id' => $case->id,
                'case_document_id' => $document?->id,
                'rule_key' => mb_substr($ruleKey, 0, 60),
                'scope' => $scope,
                'status' => $state,
                'message_fa' => mb_substr($message, 0, 255),
                'details' => null,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        };

        // ۱) پایه: همهٔ بررسی‌های تک‌مدرکی و تطابقی، پاس‌شده.
        foreach ($documents as $document) {
            $key = $documentKey($document);
            $label = $document->documentType?->label_fa ?? 'مدرک';

            $push('document.expired.'.$key, 'document', 'passed', 'تاریخ اعتبار «'.$label.'» معتبر است.', $document);
            $push('document.missing_required.'.$key, 'document', 'passed', 'همهٔ فیلدهای الزامی «'.$label.'» خوانده شد.', $document);
        }

        foreach ($this->crossCheckedFieldKeys() as $fieldKey => $fieldLabel) {
            $push('cross.'.$fieldKey, 'cross', 'passed', '«'.$fieldLabel.'» بین مدارک یکسان خوانده شد.');
        }

        $failedPrecheck = collect($documents)->first(fn (CaseDocument $d) => $d->precheck_status === 'failed');

        if ($failedPrecheck) {
            $push(
                'file.blurry',
                'file',
                'failed',
                'تصویر «'.($failedPrecheck->documentType?->label_fa ?? 'مدرک').'» تار است؛ با نور کافی و بدون لرزش دوباره عکس بگیرید.',
                $failedPrecheck,
            );
        }

        if ($status === 'rejected') {
            // ناسازگاری کد ملی بین مدارک، شایع‌ترین دلیل رد سامانه است.
            $push(
                'cross.national_id',
                'cross',
                'failed',
                'کد ملی خوانده‌شده از مدارک با هم یکی نیست؛ مدرک درست را دوباره بارگذاری کنید.',
            );

            $license = collect($documents)->first(fn (CaseDocument $d) => $documentKey($d) === 'driving_license');

            if ($license) {
                $push(
                    'document.expired.driving_license',
                    'document',
                    'failed',
                    'تاریخ اعتبار گواهینامه گذشته است؛ گواهینامه معتبر بارگذاری کنید.',
                    $license,
                );
            }
        }

        if ($status === 'needs_review') {
            // نیمی از پرونده‌های بررسی‌طلب سرِ پلاک گیر می‌کنند (ضعف شناخته‌شدهٔ موتور).
            if ($case->id % 2 === 0) {
                $vehicle = collect($documents)->first(fn (CaseDocument $d) => $documentKey($d) === 'vehicle_card');

                $push(
                    'document.missing_required.vehicle_card',
                    'document',
                    'failed',
                    'شماره پلاک از کارت خودرو خوانده نشد؛ مقدار را دستی وارد یا تصویر واضح‌تری بارگذاری کنید.',
                    $vehicle,
                );
            } else {
                $push(
                    'cross.national_id',
                    'cross',
                    'failed',
                    'کد ملی مدارک با اطمینان کافی یکی خوانده نشد؛ کارشناس باید تطابق را تایید کند.',
                );
            }

            $push(
                'cross.full_name',
                'cross',
                'warning',
                'نام و نام خانوادگی بین مدارک کمی متفاوت خوانده شد؛ احتمالاً خطای خواندن است نه ناسازگاری.',
            );
        }

        if ($status === 'approved' && $case->id % 4 === 0) {
            $push(
                'file.quality_unavailable',
                'file',
                'warning',
                'سنجش کیفیت تصویر انجام نشد؛ پرونده با بقیه بررسی‌ها ادامه پیدا کرد.',
            );
        }

        if ($rows !== []) {
            ValidationResult::query()->insert(array_values($rows));
        }
    }

    /**
     * فیلدهایی که بین مدارک تطابقشان بررسی می‌شود → برچسب فارسی.
     *
     * از جدول مرجع می‌آید نه هاردکد، تا اگر `is_cross_checked` فیلدی عوض شد
     * دادهٔ نمایشی هم همان را بازتاب بدهد.
     *
     * @return array<string, string>
     */
    private function crossCheckedFieldKeys(): array
    {
        return $this->crossFields ??= DocumentTypeField::query()
            ->where('is_cross_checked', true)
            ->orderBy('id')
            ->pluck('label_fa', 'key')
            ->all();
    }

    /**
     * سه مؤلفهٔ امتیاز اطمینان. مقدارها طوری انتخاب می‌شوند که مجموع
     * وزنی‌شان نزدیک confidence_score پرونده در بیاید — همان چیزی که
     * CaseScorer در عمل می‌سازد.
     *
     * @param  array<string, float>  $weights
     */
    private function makeScoreComponents(
        PermitCase $case,
        string $status,
        float $confidence,
        array $weights,
        Carbon $createdAt,
    ): void {
        $stamp = $createdAt->copy()->addMinutes(16);

        $values = [
            // کیفیت OCR همیشه ضعیف‌ترین حلقه است (میانگین دقت موتور ۵۹٪).
            'ocr_quality' => max(0.0, min(100.0, $confidence - $this->between(4.0, 12.0))),
            'validation' => match ($status) {
                'approved' => $this->between(88.0, 100.0),
                'rejected' => $this->between(10.0, 40.0),
                default => $this->between(50.0, 80.0),
            },
            'completeness' => $status === 'rejected' ? $this->between(50.0, 80.0) : 100.0,
        ];

        $labels = [
            'ocr_quality' => 'کیفیت تشخیص متن (OCR)',
            'validation' => 'نتیجهٔ بررسی‌های اعتبارسنجی',
            'completeness' => 'کامل بودن مدارک',
        ];

        $notes = [
            'ocr_quality' => 'میانگین اطمینان فیلدهای خوانده‌شده از مدارک این پرونده.',
            'validation' => 'نسبت بررسی‌های بدون ایراد به کل بررسی‌های انجام‌شده.',
            'completeness' => 'چند مدرکِ لازمِ این خدمت بارگذاری و قابل استفاده بوده است.',
        ];

        $rows = [];

        foreach ($values as $key => $value) {
            $weight = $weights[$key] ?? 0.0;

            $rows[] = [
                'case_id' => $case->id,
                'component_key' => $key,
                'label_fa' => $labels[$key],
                'weight' => round($weight, 2),
                'value' => round($value, 2),
                'contribution' => round($weight * $value / 100, 2),
                'note_fa' => $notes[$key],
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        ScoreComponent::query()->insert($rows);
    }

    /** دلیل تصمیم، به زبان کاربر. */
    private function decisionReason(string $status): string
    {
        return match ($status) {
            'approved' => 'امتیاز اطمینان بالای آستانهٔ تایید بود و هیچ بررسی‌ای ایراد نگرفت.',
            'rejected' => 'امتیاز اطمینان زیر آستانهٔ رد بود؛ مدارک با هم نمی‌خوانند یا اعتبارشان گذشته است.',
            default => 'امتیاز اطمینان بین دو آستانه ماند؛ تصمیم نهایی با کارشناس است.',
        };
    }

    /**
     * مقدار نمونهٔ یک فیلد — آشکارا ساختگی، هیچ دادهٔ هویتی واقعی.
     */
    private function sampleValue(string $fieldKey, string $valueType, PermitCase $case): string
    {
        $number = (int) mb_substr($case->code, mb_strlen(self::CODE_PREFIX));

        return match ($fieldKey) {
            'national_id' => $this->faDigits((string) $case->applicant_national_id),
            'first_name' => 'نام‌نمونه',
            'last_name' => 'خانوادگی‌نمونه‌'.$this->faDigits((string) $number),
            'full_name' => 'متقاضی نمونه شمارهٔ '.$this->faDigits((string) $number),
            'father_name' => 'پدرنمونه',
            'vin' => 'DEMOVIN'.str_pad((string) $number, 10, '0', STR_PAD_LEFT),
            'plate_number' => $this->faDigits('12').' الف '.$this->faDigits(str_pad((string) (100 + $number), 3, '0', STR_PAD_LEFT)).' ایران '.$this->faDigits((string) (10 + $number % 80)),
            'license_number' => $this->faDigits(str_pad((string) (9000000 + $number), 8, '0', STR_PAD_LEFT)),
            'permit_number' => $this->faDigits(str_pad((string) (4400000 + $number), 8, '0', STR_PAD_LEFT)),
            default => match ($valueType) {
                'jalali_date' => $this->faDigits(sprintf('%04d/%02d/%02d', 1370 + ($number % 30), 1 + ($number % 12), 1 + ($number % 28))),
                'digits' => $this->faDigits(str_pad((string) (1000 + $number), 6, '0', STR_PAD_LEFT)),
                default => 'مقدار نمونه '.$this->faDigits((string) $number),
            },
        };
    }

    /**
     * نسخهٔ «بد خوانده‌شده» یک مقدار — همان چیزی که موتور روی تصویر ضعیف
     * بیرون می‌دهد: فاصلهٔ اضافه، رقم جابه‌جا، حرف گم‌شده.
     */
    private function garble(string $value): string
    {
        $length = mb_strlen($value);

        if ($length < 3) {
            return $value.' ';
        }

        $cut = (int) floor($length / 2);

        return mb_substr($value, 0, $cut).' '.mb_substr($value, $cut + 1);
    }

    /** ارقام لاتین → فارسی (موتور ارقام فارسی چاپ می‌کند). */
    private function faDigits(string $value): string
    {
        return strtr($value, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }

    /** عدد تصادفیِ تکرارپذیر در یک بازه. */
    private function between(float $min, float $max): float
    {
        return $min + (mt_rand(0, 10_000) / 10_000) * ($max - $min);
    }
}
