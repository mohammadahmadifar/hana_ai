<?php

namespace Database\Seeders;

use App\Models\CaseDocument;
use App\Models\DocumentType;
use App\Models\ExtractedField;
use App\Models\PermitCase;
use App\Models\ScoreComponent;
use App\Models\ServiceType;
use App\Models\Setting;
use App\Models\User;
use App\Models\ValidationResult;
use App\Services\Cases\CaseScorer;
use App\Services\Cases\DocumentPrecheck;
use App\Services\Cases\DocumentValidator;
use App\Services\Cases\PrecheckIssue;
use App\Support\Jalali;
use App\Support\PersianValue;
use FilesystemIterator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * بیست پروندهٔ نمایشی برای داشبورد، گزارش‌ها و صفحهٔ نتیجهٔ پرونده.
 *
 *     php artisan db:seed --class=DemoCasesSeeder
 *
 * چرا جداست: `DatabaseSeeder` فقط دادهٔ مرجع و کاربران اولیه را می‌سازد و
 * نباید با دادهٔ نمایشی قاطی شود. این seeder دست به آن نمی‌زند و خودش
 * idempotent است — هر بار اجرا، پرونده‌های نمایشی قبلی (کد `HA-DEMO-…`) و
 * فایل‌هایشان (زیرپوشهٔ `demo/` دیسک documents) را پاک و از نو می‌سازد و به
 * هیچ پروندهٔ واقعی کار ندارد.
 *
 * ## دو قاعده‌ای که این فایل روی آن‌ها بنا شده
 *
 * **۱) هیچ مدرک هویتی واقعی (قانون ۱۲۹).** هم تصویرها و هم مقدارها از
 * خروجی *ژنراتور خودِ موتور* می‌آیند: `dataset/generated/<نوع>/NNN.png` و
 * برچسبِ همان تصویر در `dataset/labels/<نوع>/NNN.json`. این‌ها آدم واقعی
 * نیستند؛ ساختهٔ `app/person/person_generator.py` هستند. سودِ جانبی‌اش این
 * است که مقدارِ نمایش‌داده‌شده در پنل **دقیقاً همان چیزی است که روی تصویر
 * چاپ شده**، پس صفحهٔ نتیجه واقعاً قابل بررسی می‌شود: کارشناس فیلد را با
 * تصویر می‌سنجد. اگر پوشهٔ ژنراتور نبود (نصب تازه بدون دیتاست)، به دادهٔ
 * آشکارا ساختگی و تصویر جانشین GD برمی‌گردیم و کار متوقف نمی‌شود.
 *
 * **۲) دادهٔ نمایشی را همان موتورِ واقعی می‌سازد.** پیش‌تر `status` و
 * `confidence_score` و ردیف‌های `validation_results` دستی نوشته می‌شدند و
 * با خروجی واقعی نمی‌خواندند — یک بار «پردازش دوباره» کافی بود تا وضعیت
 * پرونده‌ها بپرد و داشبورد عوض شود. حالا seeder فقط **ورودی** می‌سازد
 * (مدرک، تصویر، فیلدهای خوانده‌شده) و بعد خودِ `DocumentValidator::validate()`
 * و `CaseScorer::score()` را صدا می‌زند. پس داده *تعریفاً* با موتور سازگار
 * است و اجرای دوبارهٔ پایپ‌لاین هیچ وضعیتی را عوض نمی‌کند.
 *
 * برای اینکه توزیع وضعیت‌ها باز هم قابل کنترل بماند، هر پرونده یک «پروفایل
 * عیب» دارد (`profileFor`) که ورودی را طوری می‌چیند که خروجی موتور همان
 * وضعیت هدف در `STATUS_PLAN` بشود؛ در پایان اجرا هدف با نتیجه مقایسه و هر
 * واگرایی روی کنسول گزارش می‌شود.
 *
 * ### قاعدهٔ عیب‌ها: هر عیب باید روی تصویر هم دیده شود
 * عیب‌ها فقط از سه جنس‌اند و هر سه با تصویر می‌خوانند:
 *   - `blurry`  → خودِ تصویر واقعاً تار ذخیره می‌شود
 *   - `drop`    → فیلد اصلاً خوانده نشده (تصویر سالم است، OCR کم آورده)
 *   - `foreign` → مدرک واقعاً مال شخص دیگری است (تصویر شخص دیگری را نشان می‌دهد)
 * عمداً هیچ‌جا «تاریخ منقضی» جعل نمی‌شود، چون تاریخ روی تصویرِ ژنراتور معتبر
 * است و مقدارِ دستکاری‌شده کارشناس را گمراه می‌کرد.
 */
class DemoCasesSeeder extends Seeder
{
    /** پیشوند کد پرونده‌های نمایشی — کلید پاک‌سازی و تشخیص. */
    public const CODE_PREFIX = 'HA-DEMO-';

    /** چند پرونده ساخته شود. */
    public const CASE_COUNT = 20;

    /** دیسک خصوصی مدارک و زیرپوشهٔ اختصاصی دادهٔ نمایشی. */
    private const DISK = 'documents';

    private const DEMO_DIR = 'demo';

    /** بیشترین عرض تصویر ذخیره‌شده — اصلِ ۱۵۳۷ پیکسلی برای نمایش لازم نیست. */
    private const IMAGE_WIDTH = 900;

    private const IMAGE_QUALITY = 82;

    /**
     * وضعیت هدف هر پرونده به ترتیب. جمع = CASE_COUNT و همهٔ وضعیت‌های
     * PermitCase::STATUSES دست‌کم یک نماینده دارند تا نمودار توزیع خالی نماند.
     *
     * این «هدف» است نه واقعیت: وضعیت واقعی را CaseScorer می‌نویسد و اگر با
     * هدف نخواند، پایان اجرا هشدار می‌دهد.
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
     * تصمیم دستی کارشناس روی چند پرونده — تا ستون «تصمیم کارشناس» و پرچم
     * `decision_is_manual` هم دادهٔ واقعی داشته باشند.
     *
     * شمارهٔ پرونده => [تصمیم، متن کارشناس]. تصمیم دستی روی وضعیتِ ماشین
     * می‌نشیند و CaseScorer دیگر آن را بازنویسی نمی‌کند (همان کاری که
     * CaseReviewController می‌کند).
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const MANUAL_DECISIONS = [
        // کارشناس پروندهٔ «نیاز به بررسی» را بعد از مقایسهٔ فیلدها با تصویر تایید کرده
        11 => ['approved', 'نام پدر روی کارت ملی خوانا بود و دستی وارد شد؛ بقیهٔ فیلدها با تصویر مدارک خواند.'],
        // و روی یکی از پرونده‌های ردشده، رد ماشین را تایید کرده است
        14 => ['rejected', 'هر سه تصویر تارند و هیچ فیلدی خوانده نشد؛ متقاضی باید مدارک را دوباره و واضح بفرستد.'],
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

    /** چند نمونه در پوشهٔ ژنراتور هست (dataset/generated/<نوع>/NNN.png). */
    private const SAMPLE_COUNT = 25;

    /** دادهٔ برچسب ژنراتور، یک بار خوانده و کش می‌شود. @var array<int, array<string, array<string, string>>> */
    private array $people = [];

    /** خروجیِ آمادهٔ هر تصویر، تا یک تصویر دو بار decode نشود. @var array<string, array<string, mixed>> */
    private array $images = [];

    /** پرونده‌هایی که خروجی موتور با هدفشان نخواند. @var list<string> */
    private array $drifted = [];

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

        // ترتیب اعداد باید بین اجراها یکی باشد تا تست و اسکرین‌شات نلرزد.
        mt_srand(14050636);

        $now = Carbon::now();

        foreach (self::STATUS_PLAN as $index => $target) {
            $number = $index + 1;
            $serviceKey = $number % 3 === 0 ? 'renew' : 'issue';
            $service = $services[$serviceKey] ?? $services->first();

            $this->makeCase($number, $target, $service, $owner, $reviewer, $now);
        }

        mt_srand();

        $this->alignDemoOwnership();

        $this->report();
    }

    // ==================================================================
    // پاک‌سازی اجرای قبلی
    // ==================================================================

    /** پرونده‌های نمایشی اجرای قبلی، ردیف‌های وابسته و فایل‌هایشان. */
    private function purgePreviousDemo(): void
    {
        // فایل‌ها جدا از دیتابیس پاک می‌شوند: اگر ردیف‌ها قبلاً دستی حذف شده
        // باشند، تصویرهای یتیم نباید روی دیسک بمانند.
        Storage::disk(self::DISK)->deleteDirectory(self::DEMO_DIR);

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

        // کد ملی از تسک ۷۴۰ نام کاربری ورود است؛ حسابِ بی‌کدملی حسابی است که
        // هرگز نمی‌تواند وارد شود، و چون ستون nullable است هیچ‌جا هم صدا نمی‌دهد.
        $user = new User;
        $user->forceFill([
            'name' => 'کارشناس نمونه',
            'email' => 'demo.expert@hana.local',
            'national_id' => '0055555551',
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

    // ==================================================================
    // یک پروندهٔ نمایشی
    // ==================================================================

    /**
     * ورودی پرونده را می‌سازد و بعد موتور را روی آن اجرا می‌کند.
     */
    private function makeCase(
        int $number,
        string $target,
        ServiceType $service,
        User $owner,
        User $reviewer,
        Carbon $now,
    ): void {
        $code = self::CODE_PREFIX.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
        $sample = (($number - 1) % self::SAMPLE_COUNT) + 1;
        $person = $this->person($sample);
        $profile = $this->profileFor($number, $target);

        // پرونده‌ها روی ۲۰ روز گذشته پخش می‌شوند؛ قدیمی‌ترین شماره ۱.
        $createdAt = $now->copy()->subDays(self::CASE_COUNT - $number)->subHours(($number * 7) % 24);
        $submittedAt = $target === 'draft' ? null : $createdAt->copy()->addMinutes(12);

        $case = new PermitCase;
        $case->forceFill([
            'code' => $code,
            'user_id' => $owner->id,
            'service_type_id' => $service->id,
            'applicant_name' => $person['national_card']['first_name'].' '.$person['national_card']['last_name'],
            'applicant_national_id' => $person['national_card']['national_id'],
            'status' => $target,
            'submitted_at' => $submittedAt,
            'created_at' => $createdAt,
            'updated_at' => $submittedAt ?? $createdAt,
        ])->save();

        $documents = $this->makeDocuments($case, $service, $target, $profile, $sample, $createdAt);

        if ($target === 'draft' || $target === 'submitted') {
            // هنوز OCR نشده — نه فیلدی، نه ایرادی، نه امتیازی.
            $this->restamp($case, $createdAt, $submittedAt, null, null);

            return;
        }

        $this->makeExtractedFields($case, $documents, $profile, $sample, $owner, $createdAt);

        if ($target === 'processing') {
            $this->restamp($case, $createdAt, $submittedAt, null, null);

            return;
        }

        // ------------------------------------------------------------------
        // از این‌جا به بعد دستِ ما نیست: خروجی همان چیزی است که موتور می‌سازد.
        // ------------------------------------------------------------------
        app(DocumentValidator::class)->validate($case);
        app(CaseScorer::class)->score($case);

        $processingMs = mt_rand(3_800, 15_400);
        $processedAt = ($submittedAt ?? $createdAt)->copy()->addMilliseconds($processingMs);

        $this->restamp($case, $createdAt, $submittedAt, $processedAt, $processingMs);

        if ($case->status !== $target) {
            $this->drifted[] = $code.': هدف «'.$target.'» ولی موتور «'.$case->status.'» داد '
                .'(امتیاز '.$case->confidence_score.').';
        }

        $manual = self::MANUAL_DECISIONS[$number] ?? null;

        if ($manual !== null) {
            $this->applyManualDecision($case, $reviewer, $manual[0], $manual[1], $processedAt);
        }
    }

    /**
     * پروفایل عیب یک پرونده — ورودی را طوری می‌چیند که موتور همان وضعیت هدف را بدهد.
     *
     * چرا این‌طوری و نه هاردکد کردن نتیجه: هاردکد یعنی دادهٔ نمایشی و موتور
     * دو حقیقتِ متفاوت داشته باشند. این‌جا فقط ورودی چیده می‌شود؛ حساب و
     * تصمیم با CaseScorer است.
     *
     * اعداد پشت هر پروفایل (با وزن‌های پیش‌فرض ۴۰/۴۰/۲۰ و جریمهٔ ۲۵/۸):
     *   approved      : بی‌عیب        → امتیاز ≈ ۹۰
     *   needs_review A: یک فیلد اجباری خوانده نشد     → ≈ ۷۴
     *   needs_review B: دو فیلد اجباری خوانده نشد     → ≈ ۷۰
     *   rejected R1   : کارت خودرو مال شخص دیگری است  → وتوی «ناهمخوانی بین مدارک»
     *   rejected R2   : همهٔ تصویرها تار              → ≈ ۱۵ (زیر آستانهٔ رد)
     *
     * @return array{
     *     shift: float,
     *     blurry: list<string>,
     *     drop: array<string, list<string>>,
     *     foreign: list<string>,
     *     corrected: array<string, list<string>>
     * }
     */
    private function profileFor(int $number, string $target): array
    {
        $defect = match (true) {
            // تصمیم‌نگرفته‌ها عیب لازم ندارند
            in_array($target, ['draft', 'submitted', 'processing'], true) => [],

            $target === 'approved' => [
                'shift' => 11.0,
                // روی یک پروندهٔ سالم هم پلاکِ بدخوانده‌شده را کارشناس دستی اصلاح کرده است
                'corrected' => $number === 10 ? ['vehicle_card' => ['plate_number']] : [],
            ],

            $target === 'needs_review' && $number % 2 === 1 => [
                'drop' => ['national_card' => ['father_name']],
            ],

            $target === 'needs_review' => [
                'drop' => [
                    'driving_license' => ['birth_date'],
                    'vehicle_card' => ['plate_number'],
                ],
            ],

            $target === 'rejected' && $number % 2 === 1 => [
                'shift' => -13.0,
                'foreign' => ['vehicle_card'],
            ],

            default => [
                'shift' => -13.0,
                'blurry' => ['national_card', 'driving_license', 'vehicle_card', 'previous_permit'],
            ],
        };

        return $defect + [
            'shift' => 0.0,
            'blurry' => [],
            'drop' => [],
            'foreign' => [],
            'corrected' => [],
        ];
    }

    // ==================================================================
    // مدارک و فایل تصویر
    // ==================================================================

    /**
     * مدارک لازم همان خدمت، هرکدام با یک فایل تصویر واقعی روی دیسک خصوصی.
     *
     * @param  array<string, mixed>  $profile
     * @return array<int, CaseDocument> کلید = document_type_id
     */
    private function makeDocuments(
        PermitCase $case,
        ServiceType $service,
        string $target,
        array $profile,
        int $sample,
        Carbon $createdAt,
    ): array {
        $ocrStatus = match ($target) {
            'draft' => 'pending',
            'submitted' => 'queued',
            'processing' => 'running',
            default => 'done',
        };

        $documents = [];

        foreach ($service->documentTypes as $type) {
            $blurry = in_array($type->key, $profile['blurry'], true);
            $foreign = in_array($type->key, $profile['foreign'], true);

            // مدرکِ «مال شخص دیگر» تصویر شخص دیگری را هم نشان می‌دهد، وگرنه
            // کارشناس روی صفحهٔ نتیجه ناهمخوانی را نمی‌دید.
            $imageSample = $foreign ? $this->foreignSample($sample) : $sample;

            $image = $this->image($type->key, $imageSample, $blurry);
            $path = self::DEMO_DIR.'/'.$case->code.'/'.$type->key.'.jpg';

            $this->putDemoFile($path, $image['contents']);

            $issues = $blurry
                ? [PrecheckIssue::error(
                    'file.blurry',
                    'تصویر خیلی تار است و نوشته‌های مدرک خوانده نمی‌شود.',
                    'دوربین را ثابت نگه دارید، صبر کنید تا روی مدرک فوکوس کند و در نور کافی دوباره عکس بگیرید.',
                    ['blur_score' => $image['blur_score'], 'min_blur_score' => 60.0],
                )]
                : [];

            $document = new CaseDocument;
            $document->forceFill([
                'case_id' => $case->id,
                'document_type_id' => $type->id,
                'disk' => self::DISK,
                'path' => $path,
                'original_name' => $type->key.'-نمونه.jpg',
                'mime' => 'image/jpeg',
                'size_bytes' => strlen($image['contents']),
                'width' => $image['width'],
                'height' => $image['height'],
                'checksum' => hash('sha256', $image['contents']),
                'precheck_status' => $blurry ? 'failed' : 'passed',
                'precheck_issues' => array_map(
                    static fn (PrecheckIssue $issue): array => $issue->toArray(),
                    $issues,
                ),
                'blur_score' => $image['blur_score'],
                'brightness_score' => $this->between(96.0, 178.0),
                'ocr_status' => $blurry ? 'failed' : $ocrStatus,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();

            // ردیف scope=file دقیقاً همان چیزی است که DocumentPrecheck می‌نویسد.
            // زمانش را restamp() به عقب می‌برد، مثل بقیهٔ ردیف‌های پرونده.
            foreach ($issues as $issue) {
                ValidationResult::query()->create([
                    'case_id' => $case->id,
                    'case_document_id' => $document->id,
                    'rule_key' => mb_substr($issue->code, 0, 60),
                    'scope' => 'file',
                    'status' => $issue->validationStatus(),
                    'message_fa' => mb_substr($issue->messageFa, 0, 255),
                    'details' => $issue->details + ['hint_fa' => $issue->hintFa],
                ]);
            }

            // رابطه را دستی می‌بندیم تا مرحله‌های بعد به‌ازای هر مدرک دوباره
            // کوئری نزنند (نوع مدرک و فیلدهایش از قبل eager load شده‌اند).
            $document->setRelation('documentType', $type);

            $documents[$type->id] = $document;
        }

        return $documents;
    }

    /** شمارهٔ نمونهٔ «شخص دیگر» — همیشه با نمونهٔ اصلی فرق دارد. */
    private function foreignSample(int $sample): int
    {
        return (($sample + 6) % self::SAMPLE_COUNT) + 1;
    }

    /**
     * فایل تصویر یک مدرک: خروجی ژنراتور، کوچک‌شده و JPEG.
     *
     * @return array{contents: string, width: int, height: int, blur_score: float}
     */
    private function image(string $typeKey, int $sample, bool $blurry): array
    {
        $cacheKey = $typeKey.'/'.$sample.'/'.($blurry ? 'blur' : 'sharp');

        if (isset($this->images[$cacheKey])) {
            return $this->images[$cacheKey];
        }

        $source = $this->sourceImagePath($typeKey, $sample);

        $canvas = $source === null
            ? $this->placeholderImage($typeKey, $sample)
            : @imagecreatefrompng($source);

        if ($canvas === false || $canvas === null) {
            $canvas = $this->placeholderImage($typeKey, $sample);
        }

        if (imagesx($canvas) > self::IMAGE_WIDTH) {
            $scaled = imagescale($canvas, self::IMAGE_WIDTH);

            if ($scaled !== false) {
                imagedestroy($canvas);
                $canvas = $scaled;
            }
        }

        if ($blurry) {
            // تصویرِ «تار» باید واقعاً ناخوانا باشد، وگرنه پیام
            // «نوشته‌های مدرک خوانده نمی‌شود» با چیزی که کارشناس می‌بیند نمی‌خواند.
            // فیلتر گاوسیِ GD روی تصویر بزرگ کم‌اثر است، پس اول کوچک و
            // دوباره بزرگ می‌کنیم — همان چیزی که عکس بی‌فوکوس شبیهش است.
            $canvas = $this->defocus($canvas);
        }

        $contents = $this->encode($canvas);

        $result = [
            'contents' => $contents,
            'width' => imagesx($canvas),
            'height' => imagesy($canvas),
            // واریانس لاپلاسین را این‌جا حساب نمی‌کنیم (کار موتور است)؛ عددی
            // می‌گذاریم که با precheck.limits همان معنایی را بدهد که تصویر دارد.
            'blur_score' => $blurry ? $this->between(14.0, 38.0) : $this->between(140.0, 320.0),
        ];

        imagedestroy($canvas);

        return $this->images[$cacheKey] = $result;
    }

    /**
     * JPEG گرفتن از بوم، با تضمین اینکه از حداقل حجمِ خودِ سامانه بزرگ‌تر باشد.
     *
     * تصویرِ تار (و تصویر جانشینِ تخت) گاهی زیر ۲۰ کیلوبایت فشرده می‌شود و
     * آن‌وقت دادهٔ نمایشی چیزی می‌سازد که `DocumentPrecheck` خودش با
     * «file.too_small» ردش می‌کرد — یعنی دوباره ناسازگاری با موتور. حد را هم
     * هاردکد نمی‌کنیم و از `precheck.limits` می‌خوانیم (قانون ۴ پروژه).
     *
     * @param  \GdImage  $canvas
     */
    private function encode($canvas): string
    {
        $limits = Setting::get('precheck.limits');
        $minBytes = is_array($limits) ? ($limits['min_bytes'] ?? null) : null;
        $minBytes = (int) ($minBytes ?: DocumentPrecheck::DEFAULT_LIMITS['min_bytes']);

        // کمی حاشیه، تا فایل درست روی مرز ننشیند
        $target = (int) round($minBytes * 1.15);
        $bytes = '';

        foreach ([self::IMAGE_QUALITY, 92, 96, 100] as $quality) {
            ob_start();
            imagejpeg($canvas, null, $quality);
            $bytes = (string) ob_get_clean();

            if (strlen($bytes) >= $target) {
                return $bytes;
            }
        }

        return $bytes;
    }

    /**
     * تصویر بی‌فوکوس: کوچک‌کردن شدید و بزرگ‌کردن دوباره، بعد کمی گاوسی.
     *
     * @param  \GdImage  $canvas
     * @return \GdImage
     */
    private function defocus($canvas)
    {
        $width = imagesx($canvas);
        $height = imagesy($canvas);

        $small = imagescale($canvas, max(24, (int) round($width / 22)));

        if ($small === false) {
            return $canvas;
        }

        $back = imagescale($small, $width, $height, IMG_BILINEAR_FIXED);

        imagedestroy($small);

        if ($back === false) {
            return $canvas;
        }

        imagedestroy($canvas);

        for ($i = 0; $i < 3; $i++) {
            imagefilter($back, IMG_FILTER_GAUSSIAN_BLUR);
        }

        return $back;
    }

    /** مسیر تصویر ژنراتور، یا null اگر دیتاست کنار پنل نباشد. */
    private function sourceImagePath(string $typeKey, int $sample): ?string
    {
        $path = rtrim((string) config('hana.root'), '/')
            .'/dataset/generated/'.$typeKey.'/'.str_pad((string) $sample, 3, '0', STR_PAD_LEFT).'.png';

        return is_file($path) && is_readable($path) ? $path : null;
    }

    /**
     * تصویر جانشین — برای «مجوز قبلی» که ژنراتور قالبی برایش ندارد، و برای
     * نصبی که پوشهٔ dataset را ندارد.
     *
     * متن فارسی روی GD بدون reshaper وارونه چاپ می‌شود، پس برچسب‌ها لاتین‌اند
     * و فقط *مقدارها* (که رقم‌اند) با همان ارقام فارسیِ پنل نوشته می‌شوند تا
     * کارشناس بتواند مقایسه کند.
     *
     * @return \GdImage
     */
    private function placeholderImage(string $typeKey, int $sample)
    {
        $width = 900;
        $height = 560;

        $canvas = imagecreatetruecolor($width, $height);

        $paper = imagecolorallocate($canvas, 242, 244, 247);
        $ink = imagecolorallocate($canvas, 32, 38, 48);
        $accent = imagecolorallocate($canvas, 120, 132, 150);

        imagefill($canvas, 0, 0, $paper);
        imagefilledrectangle($canvas, 0, 0, $width, 78, $accent);
        imagerectangle($canvas, 6, 6, $width - 7, $height - 7, $accent);

        $lines = $this->placeholderLines($typeKey, $sample);
        $font = rtrim((string) config('hana.root'), '/').'/fonts/Vazir-Medium.ttf';
        $usable = is_file($font) && function_exists('imagettftext');

        $y = 130;

        foreach ($lines as $line) {
            if ($usable) {
                imagettftext($canvas, 22, 0, 56, $y, $ink, $font, $line);
            } else {
                imagestring($canvas, 5, 56, $y - 16, $line, $ink);
            }

            $y += 56;
        }

        // چند نوار پرکنتراست تا تصویر مثل کاغذِ خالی به نظر نرسد
        for ($i = 0; $i < 4; $i++) {
            imagefilledrectangle($canvas, 56, $y + $i * 26, $width - 120 - $i * 40, $y + 8 + $i * 26, $accent);
        }

        return $canvas;
    }

    /**
     * سطرهای تصویر جانشین.
     *
     * @return list<string>
     */
    private function placeholderLines(string $typeKey, int $sample): array
    {
        $person = $this->person($sample);
        $permit = $person['previous_permit'] ?? [];

        if ($typeKey !== 'previous_permit') {
            return [
                'DEMO SAMPLE - '.mb_strtoupper(str_replace('_', ' ', $typeKey)),
                'NO GENERATOR IMAGE ON THIS INSTALL',
            ];
        }

        return [
            'DEMO SAMPLE - PREVIOUS PERMIT',
            'PERMIT NO: '.($permit['permit_number'] ?? '—'),
            'NATIONAL ID: '.($permit['national_id'] ?? '—'),
            'ISSUED: '.($permit['permit_issue_date'] ?? '—'),
            'EXPIRES: '.($permit['permit_expire_date'] ?? '—'),
        ];
    }

    /**
     * نوشتن فایل روی دیسک خصوصی مدارک.
     *
     * مالکیت و مجوز عمداً بعداً یک‌جا اصلاح می‌شود (`alignDemoOwnership`):
     * seeder معمولاً با کاربر root اجرا می‌شود ولی php-fpm با www-data، و
     * پیش‌فرض «خصوصی» لاراول یعنی فایل ۰۶۰۰ که آن وقت اصلاً سرو نمی‌شود.
     */
    private function putDemoFile(string $path, string $contents): void
    {
        Storage::disk(self::DISK)->put($path, $contents);
    }

    /**
     * مالکیت و مجوز پوشهٔ نمایشی را با ریشهٔ دیسک یکی می‌کند.
     *
     * بدون این، فایلی که seederِ root نوشته با مجوز ۰۶۰۰ می‌ماند و php-fpm
     * (www-data) موقع سرو کردن ۴۰۴/۵۰۰ می‌دهد — همان چیزی که در بازبینی
     * دیده شد.
     */
    private function alignDemoOwnership(): void
    {
        $disk = Storage::disk(self::DISK);
        $base = $disk->path(self::DEMO_DIR);

        if (! is_dir($base)) {
            return;
        }

        $root = $disk->path('');
        $owner = @fileowner($root);
        $group = @filegroup($root);
        $isRoot = function_exists('posix_geteuid') ? posix_geteuid() === 0 : false;

        $paths = [$base];

        $walker = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($walker as $item) {
            $paths[] = $item->getPathname();
        }

        foreach ($paths as $path) {
            @chmod($path, is_dir($path) ? 0775 : 0664);

            if ($isRoot && $owner !== false) {
                @chown($path, $owner);
            }

            if ($isRoot && $group !== false) {
                @chgrp($path, $group);
            }
        }
    }

    // ==================================================================
    // فیلدهای استخراج‌شده
    // ==================================================================

    /**
     * همان چیزی که OCR از روی این تصویرها بیرون می‌داد.
     *
     * مقدارها از برچسب ژنراتور می‌آیند، پس با نوشتهٔ روی تصویر یکی‌اند —
     * جز جاهایی که عمداً «بد خوانده شده‌اند» (پلاک و VIN، دو ضعف شناخته‌شدهٔ
     * موتور در CLAUDE.md).
     *
     * @param  array<int, CaseDocument>  $documents
     * @param  array<string, mixed>  $profile
     */
    private function makeExtractedFields(
        PermitCase $case,
        array $documents,
        array $profile,
        int $sample,
        User $owner,
        Carbon $createdAt,
    ): void {
        $rows = [];
        $stamp = $createdAt->copy()->addMinutes(14);
        $shift = (float) $profile['shift'];

        foreach ($documents as $document) {
            if ($document->ocr_status === 'failed') {
                continue; // تصویر تار اصلاً به OCR نرفت
            }

            $type = $document->documentType;

            if ($type === null) {
                continue;
            }

            // مدرکِ «مال شخص دیگر» مقدارهای همان شخص دیگر را می‌دهد — همان
            // چیزی که روی تصویرش هم چاپ شده.
            $owning = in_array($type->key, $profile['foreign'], true)
                ? $this->foreignSample($sample)
                : $sample;

            $values = $this->person($owning)[$type->key] ?? [];

            $dropped = $profile['drop'][$type->key] ?? [];
            $corrected = $profile['corrected'][$type->key] ?? [];

            foreach ($type->fields as $field) {
                $value = $values[$field->key] ?? null;

                if ($value === null || in_array($field->key, $dropped, true)) {
                    continue; // این فیلد اصلاً خوانده نشد
                }

                $base = self::FIELD_BASE_CONFIDENCE[$field->key] ?? self::FIELD_BASE_FALLBACK;
                $confidence = max(4.0, min(99.0, $base + $shift + $this->between(-7.0, 7.0)));

                [$raw, $normalized] = $this->readingOf($field->key, $value);

                $isCorrected = in_array($field->key, $corrected, true);

                $rows[] = [
                    'case_id' => $case->id,
                    'case_document_id' => $document->id,
                    'field_key' => $field->key,
                    'raw_value' => $raw,
                    'normalized_value' => $isCorrected ? $value : $normalized,
                    'confidence' => round($confidence, 2),
                    'source' => $isCorrected ? 'manual' : 'ocr',
                    'corrected_by' => $isCorrected ? $owner->id : null,
                    'corrected_at' => $isCorrected ? $stamp : null,
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
     * «خام» و «نرمال‌شده»ی یک فیلد.
     *
     * دو ضعف شناخته‌شدهٔ موتور این‌جا بازسازی می‌شوند تا صفحهٔ نتیجه واقعی
     * به نظر برسد و کارشناس چیزی برای اصلاح داشته باشد:
     *   - پلاک: اشتباه خوانده می‌شود (دقت ۰٪) و نرمال‌شده‌ای ندارد
     *   - VIN: درست خوانده می‌شود ولی یک فاصلهٔ اضافه دارد که نرمال‌سازی برمی‌دارد
     *
     * @return array{0: string, 1: string|null}
     */
    private function readingOf(string $fieldKey, string $value): array
    {
        if ($fieldKey === 'plate_number') {
            return [$this->garble($value), null];
        }

        if ($fieldKey === 'vin' && mb_strlen($value) > 6) {
            return [mb_substr($value, 0, 5).' '.mb_substr($value, 5), $value];
        }

        return [$value, $value];
    }

    /**
     * نسخهٔ «بد خوانده‌شده» یک مقدار — همان چیزی که موتور روی فیلد ضعیف
     * بیرون می‌دهد: یک نویسه گم و یک فاصلهٔ اضافه.
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

    // ==================================================================
    // زمان‌ها و تصمیم دستی
    // ==================================================================

    /**
     * زمان‌های پرونده را به گذشته برمی‌گرداند.
     *
     * لازم است چون CaseScorer درست مثل اجرای واقعی `processed_at = now()`
     * می‌گذارد و ما می‌خواهیم پرونده‌های نمایشی روی بیست روز گذشته پخش باشند.
     * فقط زمان عوض می‌شود؛ به وضعیت و امتیاز دست نمی‌زنیم.
     */
    private function restamp(
        PermitCase $case,
        Carbon $createdAt,
        ?Carbon $submittedAt,
        ?Carbon $processedAt,
        ?int $processingMs,
    ): void {
        $stamp = $processedAt ?? $submittedAt ?? $createdAt;

        $case->timestamps = false;
        $case->forceFill([
            'submitted_at' => $submittedAt,
            'processed_at' => $processedAt,
            'processing_ms' => $processingMs,
            'decided_at' => $case->decision === null ? null : $processedAt,
            'created_at' => $createdAt,
            'updated_at' => $stamp,
        ])->save();

        ValidationResult::query()
            ->where('case_id', $case->id)
            ->update(['created_at' => $stamp, 'updated_at' => $stamp]);

        ScoreComponent::query()
            ->where('case_id', $case->id)
            ->update(['created_at' => $stamp, 'updated_at' => $stamp]);
    }

    /**
     * تصمیم دستی کارشناس — همان ستون‌هایی که CaseReviewController می‌نویسد.
     *
     * `decision_is_manual` باعث می‌شود اجرای دوبارهٔ CaseScorer امتیاز را
     * به‌روز کند ولی تصمیم انسانی را دست نزند.
     */
    private function applyManualDecision(
        PermitCase $case,
        User $reviewer,
        string $decision,
        string $reason,
        Carbon $decidedAt,
    ): void {
        $score = $case->confidence_score === null
            ? 'محاسبه‌نشده'
            : PersianValue::decimal((float) $case->confidence_score, 1).' از ۱۰۰';

        $case->timestamps = false;
        $case->forceFill([
            'decision' => $decision,
            'decision_reason' => 'تصمیم دستی کارشناس '.$reviewer->name.' — '.$reason
                .' (امتیاز اطمینان ماشین در لحظهٔ تصمیم: '.$score.'.)',
            'decision_is_manual' => true,
            'decided_by' => $reviewer->id,
            'decided_at' => $decidedAt,
            'status' => $decision,
            'updated_at' => $decidedAt,
        ])->save();
    }

    // ==================================================================
    // دادهٔ شخص (برچسب ژنراتور)
    // ==================================================================

    /**
     * مقدار همهٔ فیلدهای یک «شخص نمونه» به تفکیک نوع مدرک.
     *
     * منبع: `dataset/labels/<نوع>/NNN.json` — همان برچسبی که ژنراتور هنگام
     * چاپ تصویر ذخیره کرده. یعنی این مقدارها *واقعاً* روی تصویر چاپ شده‌اند.
     *
     * @return array<string, array<string, string>>
     */
    private function person(int $sample): array
    {
        if (isset($this->people[$sample])) {
            return $this->people[$sample];
        }

        $data = [];

        foreach (['national_card', 'driving_license', 'vehicle_card'] as $typeKey) {
            $data[$typeKey] = $this->labelFile($typeKey, $sample) ?? $this->syntheticFields($typeKey, $sample);
        }

        // «مجوز قبلی» قالب ژنراتور ندارد؛ از همان شخص ساخته می‌شود.
        $data['previous_permit'] = [
            'permit_number' => $this->faDigits(str_pad((string) (4_400_000 + $sample), 8, '0', STR_PAD_LEFT)),
            'national_id' => $data['national_card']['national_id'] ?? $this->fakeNationalId($sample),
            'permit_issue_date' => $this->jalaliOffset(-3 * 365 - $sample),
            'permit_expire_date' => $this->jalaliOffset(4 * 365 - $sample),
            'plate_number' => $data['vehicle_card']['plate_number'] ?? '',
        ];

        return $this->people[$sample] = $data;
    }

    /**
     * برچسب یک تصویر ژنراتور.
     *
     * @return array<string, string>|null
     */
    private function labelFile(string $typeKey, int $sample): ?array
    {
        $path = rtrim((string) config('hana.root'), '/')
            .'/dataset/labels/'.$typeKey.'/'.str_pad((string) $sample, 3, '0', STR_PAD_LEFT).'.json';

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        return array_map(static fn ($value): string => (string) $value, $decoded);
    }

    /**
     * دادهٔ آشکارا ساختگی — وقتی پوشهٔ dataset کنار پنل نیست.
     *
     * @return array<string, string>
     */
    private function syntheticFields(string $typeKey, int $sample): array
    {
        $nationalId = $this->fakeNationalId($sample);
        $first = 'متقاضی';
        $last = 'نمونه‌'.$this->faDigits((string) $sample);
        $full = $first.' '.$last;

        return match ($typeKey) {
            'national_card' => [
                'national_id' => $nationalId,
                'first_name' => $first,
                'last_name' => $last,
                'birth_date' => $this->jalaliOffset(-30 * 365 - $sample * 11),
                'father_name' => 'پدرنمونه',
                'national_card_expire' => $this->jalaliOffset(6 * 365 + $sample),
            ],
            'driving_license' => [
                'national_id' => $nationalId,
                'full_name' => $full,
                'birth_date' => $this->jalaliOffset(-30 * 365 - $sample * 11),
                'license_issue_date' => $this->jalaliOffset(-8 * 365 - $sample),
                'license_number' => $this->faDigits(str_pad((string) (9_000_000 + $sample), 8, '0', STR_PAD_LEFT)),
            ],
            default => [
                'full_name' => $full,
                'national_id' => $nationalId,
                'father_name' => 'پدرنمونه',
                'vin' => 'DEMOVIN'.str_pad((string) $sample, 10, '0', STR_PAD_LEFT),
                'plate_number' => $this->faDigits('12').' الف '
                    .$this->faDigits(str_pad((string) (100 + $sample), 3, '0', STR_PAD_LEFT))
                    .' ایران '.$this->faDigits((string) (10 + $sample % 80)),
            ],
        };
    }

    /** کد ملیِ آشکارا ساختگی (شش صفر) ولی با رقم کنترل درست. */
    private function fakeNationalId(int $sample): string
    {
        $body = '000000'.str_pad((string) $sample, 3, '0', STR_PAD_LEFT);

        $total = 0;

        for ($i = 0; $i < 9; $i++) {
            $total += ((int) $body[$i]) * (10 - $i);
        }

        $remainder = $total % 11;
        $check = $remainder < 2 ? $remainder : 11 - $remainder;

        return $this->faDigits($body.$check);
    }

    /** تاریخ شمسیِ «امروز به‌علاوهٔ N روز»، با ارقام فارسی. */
    private function jalaliOffset(int $days): string
    {
        $moment = Carbon::now()->addDays($days);

        [$year, $month, $day] = Jalali::fromGregorian(
            (int) $moment->year,
            (int) $moment->month,
            (int) $moment->day,
        );

        return $this->faDigits(sprintf('%04d/%02d/%02d', $year, $month, $day));
    }

    // ==================================================================
    // ابزار
    // ==================================================================

    /** گزارش پایانی: توزیع واقعی و هر جایی که خروجی موتور با هدف نخواند. */
    private function report(): void
    {
        $counts = PermitCase::query()
            ->where('code', 'like', self::CODE_PREFIX.'%')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $summary = [];

        foreach ($counts as $status => $total) {
            $summary[] = (PermitCase::STATUSES[$status] ?? $status).': '.$total;
        }

        $this->command?->info(
            'DemoCasesSeeder: '.self::CASE_COUNT.' پروندهٔ نمایشی ساخته شد (کد '
            .self::CODE_PREFIX."0001 به بعد) — وضعیتِ ساختهٔ خودِ موتور: \n  ".implode('، ', $summary)
        );

        foreach ($this->drifted as $line) {
            $this->command?->warn('DemoCasesSeeder: '.$line);
        }
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
