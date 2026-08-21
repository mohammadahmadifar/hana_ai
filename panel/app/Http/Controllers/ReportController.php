<?php

namespace App\Http\Controllers;

use App\Models\DocumentTypeField;
use App\Models\ExtractedField;
use App\Models\Setting;
use App\Models\ValidationResult;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * گزارش خطاهای سامانه.
 *
 * دو پرسش را جواب می‌دهد و هر دو مستقیم از دیتابیس خوانده می‌شوند:
 *
 *   ۱) «پرونده‌ها بیشتر سرِ چه چیزی رد می‌شوند؟» — تجمیع validation_results
 *      روی rule_key با status=failed.
 *   ۲) «کدام فیلد را موتور بدتر می‌خواند؟» — میانگین extracted_fields.confidence
 *      به تفکیک field_key. ارزشمندترین خروجی این صفحه است چون مستقیم می‌گوید
 *      بعد کدام قسمت موتور OCR باید بهتر شود. کنار هر میانگین، «تعداد نمونه»
 *      هم می‌آید تا میانگینِ روی یکی‌دو نمونه گمراه‌کننده نباشد.
 *
 * قاعده دسترسی: این صفحه از داده پرونده تغذیه می‌شود و ردیف‌هایش به پرونده
 * لینک می‌دهند، پس مثل کل گروه «درخواست خدمت» فقط برای نقش‌هایی باز است که
 * canReviewCases() دارند (روت با role:admin,expert بسته شده است).
 *
 * همه کوئری‌ها تجمیعی‌اند (یک کوئری به‌ازای هر جدول، نه یکی به‌ازای هر ردیف)
 * و هر جدولی که می‌تواند بزرگ شود limit دارد.
 */
class ReportController extends Controller
{
    /** بیشترین ردیفِ هر جدول تجمیعی روی صفحه گزارش. */
    public const TOP_LIMIT = 15;

    /** بیشترین ردیف در صفحه‌های ریزبینی (فهرست فیلترشده). */
    public const DRILL_LIMIT = 50;

    /**
     * زیر این حد، خواندنِ فیلد «ضعیف» شمرده می‌شود. فقط سنجه نمایش است نه
     * آستانه تصمیم، ولی باز هم از settings خوانده می‌شود تا هاردکد نماند.
     */
    public const LOW_CONFIDENCE = 60.0;

    /** زیر این تعداد نمونه، میانگین «کم‌اتکا» علامت می‌خورد. */
    public const THIN_SAMPLE = 3;

    /**
     * میانگین اطمینانِ خواندن‌های موتور.
     *
     * عمداً یک ثابت است و هم در SELECT هم در ORDER BY همین رشته می‌نشیند:
     * MySQL اجازه نمی‌دهد نامِ مستعارِ یک تابع تجمیعی داخل *عبارتِ* ORDER BY
     * بیاید («Reference 'avg_confidence' not supported») در حالی که sqlite
     * اجازه می‌دهد. اگر فقط با sqlite تست شود، این کوئری روی پروداکشن
     * MySQL با ۵۰۰ می‌افتد.
     */
    private const OCR_AVG_SQL = "AVG(CASE WHEN source = 'ocr' THEN confidence END)";

    /** حوزه بررسی → برچسب فارسی (قرارداد rule_key سند پایپ‌لاین). */
    public const SCOPE_LABELS = [
        'file' => 'فایل',
        'document' => 'تک‌مدرکی',
        'cross' => 'تطابق بین مدارک',
    ];

    /** صفحه اصلی گزارش‌ها. */
    public function index(Request $request): View
    {
        $lowConfidence = (float) (Setting::get('reports.low_confidence') ?? self::LOW_CONFIDENCE);

        $failedTotal = ValidationResult::query()->where('status', 'failed')->count();
        $warningTotal = ValidationResult::query()->where('status', 'warning')->count();

        $failedRules = ValidationResult::query()
            ->where('status', 'failed')
            ->selectRaw('rule_key, scope')
            ->selectRaw('COUNT(*) AS failures')
            ->selectRaw('COUNT(DISTINCT case_id) AS cases_affected')
            // یک پیام نمونه از دل همان تجمیع؛ جلوی N+1 را می‌گیرد.
            ->selectRaw('MIN(message_fa) AS sample_message')
            ->groupBy('rule_key', 'scope')
            ->orderByDesc('failures')
            ->orderBy('rule_key')
            ->limit(self::TOP_LIMIT)
            ->get();

        $weakFields = $this->weakFields($lowConfidence);
        $weakFieldsByDocument = $this->weakFieldsByDocument();

        $fieldLabels = $this->fieldLabels();

        return view('reports.index', [
            'failedTotal' => $failedTotal,
            'warningTotal' => $warningTotal,
            'failedRules' => $failedRules,
            'failedShown' => (int) $failedRules->sum('failures'),
            'weakFields' => $weakFields,
            'weakFieldsByDocument' => $weakFieldsByDocument,
            'fieldLabels' => $fieldLabels,
            'lowConfidence' => $lowConfidence,
            'thinSample' => self::THIN_SAMPLE,
            'scopeLabels' => self::SCOPE_LABELS,
            'ocrAverage' => $this->ocrAverage(),
        ]);
    }

    /** فهرست فیلترشدهٔ «پرونده‌هایی که این قانون رویشان رد شده». */
    public function rule(Request $request): View
    {
        $ruleKey = $this->queryKey($request, 60);

        $rows = collect();

        if ($ruleKey !== '') {
            $rows = ValidationResult::query()
                ->with(['permitCase:id,code,applicant_name,status,confidence_score,created_at'])
                ->where('rule_key', $ruleKey)
                ->where('status', 'failed')
                ->orderByDesc('id')
                ->limit(self::DRILL_LIMIT)
                ->get();
        }

        return view('reports.rule', [
            'ruleKey' => $ruleKey,
            'rows' => $rows,
            'total' => $ruleKey === ''
                ? 0
                : ValidationResult::query()->where('rule_key', $ruleKey)->where('status', 'failed')->count(),
            'limit' => self::DRILL_LIMIT,
            'scopeLabels' => self::SCOPE_LABELS,
        ]);
    }

    /** فهرست فیلترشدهٔ «ضعیف‌ترین خواندن‌های این فیلد». */
    public function field(Request $request): View
    {
        $fieldKey = $this->queryKey($request, 40);

        $rows = collect();
        $summary = null;

        if ($fieldKey !== '') {
            $rows = ExtractedField::query()
                ->with([
                    'permitCase:id,code,applicant_name,status',
                    'caseDocument:id,document_type_id',
                    'caseDocument.documentType:id,label_fa',
                ])
                ->where('field_key', $fieldKey)
                ->orderBy('confidence')
                ->orderByDesc('id')
                ->limit(self::DRILL_LIMIT)
                ->get();

            $summary = ExtractedField::query()
                ->where('field_key', $fieldKey)
                ->selectRaw('COUNT(*) AS samples')
                ->selectRaw('AVG(confidence) AS avg_confidence')
                ->selectRaw('MIN(confidence) AS min_confidence')
                ->selectRaw('MAX(confidence) AS max_confidence')
                ->first();
        }

        return view('reports.field', [
            'fieldKey' => $fieldKey,
            'fieldLabel' => $this->fieldLabels()[$fieldKey] ?? null,
            'rows' => $rows,
            'summary' => $summary,
            'limit' => self::DRILL_LIMIT,
            'lowConfidence' => (float) (Setting::get('reports.low_confidence') ?? self::LOW_CONFIDENCE),
        ]);
    }

    /**
     * ضعیف‌ترین فیلدها — میانگین اطمینان به تفکیک field_key.
     *
     * میانگین فقط روی خواندن‌های موتور (source=ocr) حساب می‌شود؛ مقداری که
     * کارشناس دستی اصلاح کرده کیفیت OCR را نشان نمی‌دهد و میانگین را
     * مصنوعی بالا می‌برد. در عوض تعداد اصلاح دستی ستون جداگانه دارد، چون
     * خودش یک نشانهٔ مستقلِ «موتور این فیلد را خراب می‌خواند» است.
     *
     * @return Collection<int, \stdClass>
     */
    private function weakFields(float $lowConfidence): Collection
    {
        return ExtractedField::query()
            ->selectRaw('field_key')
            ->selectRaw('COUNT(*) AS samples')
            ->selectRaw("SUM(CASE WHEN source = 'ocr' THEN 1 ELSE 0 END) AS ocr_samples")
            ->selectRaw(self::OCR_AVG_SQL.' AS avg_confidence')
            ->selectRaw("MIN(CASE WHEN source = 'ocr' THEN confidence END) AS min_confidence")
            ->selectRaw(
                "SUM(CASE WHEN source = 'ocr' AND confidence < ? THEN 1 ELSE 0 END) AS low_samples",
                [$lowConfidence],
            )
            ->selectRaw("SUM(CASE WHEN source <> 'ocr' OR corrected_at IS NOT NULL THEN 1 ELSE 0 END) AS corrected_samples")
            ->groupBy('field_key')
            // فیلدی که هیچ خواندن OCR ندارد میانگینش NULL است؛ نباید بالای
            // فهرست «ضعیف‌ترین» بنشیند، چون داده‌ای دربارهٔ آن نداریم.
            ->orderByRaw('CASE WHEN '.self::OCR_AVG_SQL.' IS NULL THEN 1 ELSE 0 END asc')
            ->orderByRaw(self::OCR_AVG_SQL.' asc')
            ->orderBy('field_key')
            ->limit(self::TOP_LIMIT)
            ->get();
    }

    /**
     * همان سنجه، ولی به تفکیک «نوع مدرک × فیلد».
     *
     * چرا لازم است: کلید فیلد بین مدرک‌ها مشترک است (مثلاً national_id در هر
     * چهار مدرک هست). میانگینِ روی field_key تنها می‌گوید «کد ملی ضعیف است»
     * ولی نمی‌گوید روی کدام قالب؛ این جدول همان یک قدم باقی‌مانده را برمی‌دارد.
     *
     * @return Collection<int, \stdClass>
     */
    private function weakFieldsByDocument(): Collection
    {
        return ExtractedField::query()
            ->join('case_documents', 'case_documents.id', '=', 'extracted_fields.case_document_id')
            ->join('document_types', 'document_types.id', '=', 'case_documents.document_type_id')
            ->where('extracted_fields.source', 'ocr')
            ->selectRaw('document_types.label_fa AS document_label')
            ->selectRaw('extracted_fields.field_key AS field_key')
            ->selectRaw('COUNT(*) AS samples')
            ->selectRaw('AVG(extracted_fields.confidence) AS avg_confidence')
            ->groupBy('document_types.id', 'document_types.label_fa', 'extracted_fields.field_key')
            ->orderByRaw('AVG(extracted_fields.confidence) asc')
            ->orderBy('document_types.id')
            ->orderBy('extracted_fields.field_key')
            ->limit(self::TOP_LIMIT)
            ->get();
    }

    /** میانگین کلی اطمینان خواندن‌های موتور، یا null اگر هنوز خواندنی نیست. */
    private function ocrAverage(): ?float
    {
        $value = ExtractedField::query()->where('source', 'ocr')->avg('confidence');

        return $value === null ? null : (float) $value;
    }

    /**
     * برچسب فارسی هر field_key از جدول مرجع.
     *
     * کلید بین چند نوع مدرک تکرار می‌شود و برچسبشان یکی است؛ pluck آخرین را
     * نگه می‌دارد و همان کافی است. یک کوئری برای کل صفحه.
     *
     * @return array<string, string>
     */
    private function fieldLabels(): array
    {
        return DocumentTypeField::query()->pluck('label_fa', 'key')->all();
    }

    /**
     * پارامتر key از کوئری‌استرینگ.
     *
     * ?key[]=x یک آرایه می‌دهد و تبدیل مستقیمش به رشته صفحه را با ۵۰۰
     * می‌ترکاند؛ هر مقدار غیرمتنی «خالی» شمرده می‌شود تا صفحه فقط حالت
     * «کلیدی انتخاب نشده» را نشان بدهد.
     */
    private function queryKey(Request $request, int $maxLength): string
    {
        $value = $request->query('key');

        if (! is_string($value)) {
            return '';
        }

        return mb_substr(trim($value), 0, $maxLength);
    }
}
