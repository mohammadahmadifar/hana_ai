<?php

namespace App\Http\Controllers\Cases;

use App\Http\Controllers\Controller;
use App\Models\CaseDocument;
use App\Models\DatasetAnnotation;
use App\Models\DatasetSample;
use App\Models\DocumentTypeField;
use App\Models\ExtractedField;
use App\Models\PermitCase;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cases\CaseScorer;
use App\Services\Cases\DocumentValidator;
use App\Support\PersianValue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

/**
 * مرحلهٔ آخر فرایند مجوز: نتیجهٔ پرونده و بررسی انسانی (تسک ۶۳۵).
 *
 * سه صفحه/عمل:
 *   review        — صف پرونده‌هایی که ماشین تصمیمشان را به کارشناس واگذار کرده
 *   show          — نتیجهٔ یک پرونده: امتیاز، دلایل، و مهم‌تر از همه
 *                   «فیلد خوانده‌شده کنار تصویر همان مدرک»
 *   updateFields  — اصلاح دستی مقدار فیلد توسط کارشناس
 *   decide        — تصمیم نهایی انسانی (تایید / رد / پس‌گرفتن تصمیم)
 *
 * چرا فیلد و تصویر باید کنار هم باشند: کارشناس هیچ راه دیگری ندارد بفهمد
 * «۸۱۴۳۰۳۷۳۸۱» که OCR خوانده همان چیزی است که روی کارت چاپ شده یا نه. صفحه‌ای
 * که فقط جدول فیلد نشان بدهد، کارشناس را مجبور می‌کند فایل را جدا باز کند و
 * عملاً بررسی انسانی را بی‌اثر می‌کند.
 *
 * ── سه قرارداد که این کنترلر باید رعایتشان کند ─────────────────────────
 *
 * ۱) FieldExtractor (تسک ۶۳۲) هرگز روی ردیفی که `source = manual` است
 *    نمی‌نویسد. پس اصلاح کارشناس باید حتماً source را «manual» کند، وگرنه
 *    اجرای بعدی OCR آن را پاک می‌کند.
 * ۲) CaseScorer (تسک ۶۳۴) وقتی `decision_is_manual = true` باشد تصمیم را
 *    بازنویسی نمی‌کند. پس تصمیم انسانی باید همان پرچم را بگذارد، وگرنه اولین
 *    اجرای دوبارهٔ پایپ‌لاین آن را دور می‌ریزد.
 * ۳) هر اصلاح فیلد، «تطابق بین مدارک» را عوض می‌کند. پس بعد از هر اصلاح اول
 *    DocumentValidator و بعد CaseScorer دوباره اجرا می‌شوند تا کارشناس همان
 *    لحظه اثر کارش را روی امتیاز ببیند. هر دو ارزان‌اند (فقط ردیف دیتابیس
 *    می‌خوانند، نه فایل و نه موتور)، پس در چرخهٔ درخواست اجرا می‌شوند.
 *
 * ── قانون ۱۳۰: اصلاح کارشناس دادهٔ آموزشی رایگان است ────────────────────
 * وقتی کارشناس مقداری را تصحیح می‌کند، دقیقاً همان چیزی را ساخته که تگ‌گذار
 * دستی می‌ساخت: یک «مقدار درست» برای یک تصویر مشخص. این در pushToDataset()
 * به شکل DatasetSample + DatasetAnnotation(source=correction) به دیتاست
 * برمی‌گردد. جزئیات و ملاحظهٔ قانون ۱۲۹ در توضیح همان متد آمده است.
 */
class CaseReviewController extends Controller
{
    /** بیشترین طول متن دلیل تصمیم که از کارشناس گرفته می‌شود. */
    private const MAX_REASON = 1000;

    /** کمترین طول دلیل «رد» — رد بی‌دلیل برای متقاضی بی‌فایده است. */
    private const MIN_REJECT_REASON = 3;

    /** عرض تصویر مدرک روی صفحهٔ نتیجه (از فهرست عرض‌های مجاز MediaController). */
    private const IMAGE_WIDTH = 800;

    /** کلید تنظیمات: آیا اصلاح کارشناس به دیتاست تگ‌گذاری برگردد؟ */
    private const COLLECT_SETTING = 'dataset.collect_case_corrections';

    /** برچسب فارسی سه حوزهٔ بررسی — همان قرارداد rule_key پایپ‌لاین. */
    public const SCOPE_LABELS = [
        'file' => 'اعتبارسنجی اولیهٔ فایل',
        'document' => 'بررسی تک‌مدرکی',
        'cross' => 'تطابق بین مدارک',
    ];

    /** رنگ نشان هر وضعیت بررسی. */
    public const CHECK_TONES = [
        'passed' => 'ok',
        'warning' => 'warn',
        'failed' => 'bad',
        'skipped' => 'info',
    ];

    /** برچسب فارسی هر وضعیت بررسی. */
    public const CHECK_LABELS = [
        'passed' => 'پاس شد',
        'warning' => 'مشکوک',
        'failed' => 'رد شد',
        'skipped' => 'بررسی نشد',
    ];

    public function __construct(
        private readonly DocumentValidator $validator,
        private readonly CaseScorer $scorer,
    ) {}

    // ==================================================================
    // صف بررسی
    // ==================================================================

    /**
     * پرونده‌هایی که وضعیتشان «نیاز به بررسی» است.
     *
     * ترتیب: قدیمی‌ترین اول. صف بررسی یک صف است، نه فهرست؛ پرونده‌ای که سه روز
     * منتظر مانده باید بالای پرونده‌ای باشد که همین حالا رسیده.
     */
    public function review(Request $request): View
    {
        $cases = $this->reviewQueue();

        return view('cases.review', [
            'cases' => $cases,
            'summary' => $this->queueSummary(),
        ]);
    }

    /** @return LengthAwarePaginator<int, PermitCase> */
    private function reviewQueue(): LengthAwarePaginator
    {
        return PermitCase::query()
            ->with(['serviceType', 'user'])
            // شمارش «ایراد» فقط failed و warning است؛ ردیف‌های passed از تسک ۶۳۳
            // هم در همین جدول‌اند و شمارش بی‌فیلتر یک پروندهٔ سالم را «۱۳ ایراد» می‌بیند.
            ->withCount([
                'validationResults as failed_count' => fn ($query) => $query->where('status', 'failed'),
                'validationResults as warning_count' => fn ($query) => $query->where('status', 'warning'),
            ])
            ->where('status', 'needs_review')
            ->orderByRaw('COALESCE(submitted_at, created_at) asc')
            ->paginate(15)
            ->withQueryString();
    }

    /**
     * خلاصهٔ بالای صف — سه عددی که کارشناس اول از همه می‌خواهد.
     *
     * @return array{waiting: int, average: ?float, oldest: ?\Illuminate\Support\Carbon}
     */
    private function queueSummary(): array
    {
        $base = PermitCase::query()->where('status', 'needs_review');

        $average = (clone $base)->avg('confidence_score');
        $oldest = (clone $base)->orderByRaw('COALESCE(submitted_at, created_at) asc')->first();

        return [
            'waiting' => (clone $base)->count(),
            'average' => $average === null ? null : round((float) $average, 1),
            'oldest' => $oldest?->submitted_at ?? $oldest?->created_at,
        ];
    }

    // ==================================================================
    // صفحهٔ نتیجهٔ پرونده
    // ==================================================================

    public function show(Request $request, PermitCase $case): View
    {
        $user = $request->user();

        // مرز این صفحه نقشِ تنها نیست: کارشناس هر پرونده‌ای را می‌بیند (کارش
        // همین است)، ولی متقاضی فقط پروندهٔ خودش را. روت هر دو را راه می‌دهد
        // و تفکیک این‌جاست، چون به مالکیتِ همین ردیف بستگی دارد.
        abort_unless(
            $user->canReviewCases() || (int) $case->user_id === (int) $user->id,
            403,
            'این پرونده متعلق به کاربر دیگری است و شما اجازهٔ دیدنش را ندارید.',
        );

        $case = $this->loadCase($case);

        $checks = $case->validationResults;

        $panels = $this->documentPanels($case);

        return view('cases.show', [
            'case' => $case,
            'panels' => $panels,
            'actions' => $this->nextActions($case, $panels, (bool) $user->canReviewCases()),
            'checksByScope' => $this->checksByScope($checks),
            'checkTotals' => $this->checkTotals($checks),
            'components' => $case->scoreComponents->sortByDesc('contribution')->values(),
            'reviewable' => $this->reviewable($case),
            // متقاضی همین صفحه را فقط‌خواندنی می‌بیند: نه فرم اصلاح فیلد، نه
            // فرم تصمیم. روت‌های آن دو هم اصلاً برایش باز نیستند، پس این پرچم
            // فقط دکمهٔ بی‌فایده را حذف می‌کند، نه اینکه تنها نگهبان باشد.
            'canReview' => (bool) $user->canReviewCases(),
            'correctors' => $this->correctorNames($case),
            'pending' => in_array($case->status, ['submitted', 'processing'], true),
            'editingDocument' => (int) session('editing_document', 0),
            'datasetOn' => $this->datasetCollectionEnabled(),
        ]);
    }

    /**
     * «حالا چه کار کنم؟» — چند جملهٔ عملی بالای صفحهٔ نتیجه.
     *
     * انگیزه‌اش شکایت تسک ۶۶۱ بود: صفحه امتیاز و فهرست ایرادها را نشان می‌داد
     * ولی نمی‌گفت کاربر باید چه کند. فهرست ایراد جواب «چه شد» را می‌دهد؛ این
     * بخش جواب «حالا چه کنم» را.
     *
     * هیچ داده‌ای این‌جا دوباره محاسبه نمی‌شود — همه‌اش از همان پنل‌ها و
     * ردیف‌های اعتبارسنجی درمی‌آید که صفحه از قبل دارد. قاعدهٔ اولویت: اول
     * چیزی که **کاربر** می‌تواند درستش کند (مدرک نیامده، فایل رد شده، تصویر
     * کوچک)، بعد چیزی که **کارشناس** باید درستش کند (مقدار خوانده‌نشده،
     * ناهمخوانی).
     *
     * متن برای متقاضی و کارشناس فرق می‌کند: متقاضی مدرک را دوباره بارگذاری
     * می‌کند، کارشناس مقدار را دستی وارد می‌کند.
     *
     * @param  list<array<string, mixed>>  $panels
     * @return list<array{icon: string, title: string, detail: string, document: ?string}>
     */
    private function nextActions(PermitCase $case, array $panels, bool $canReview): array
    {
        $actions = [];

        foreach ($panels as $panel) {
            $label = (string) $panel['type']->label_fa;

            if ($panel['state'] === 'missing') {
                if ($panel['required']) {
                    $actions[] = [
                        'icon' => '📂',
                        'title' => 'مدرک «'.$label.'» هنوز بارگذاری نشده است.',
                        'detail' => $canReview
                            ? 'تا این مدرک نیاید، پرونده ناقص می‌ماند و امتیاز کامل نمی‌شود.'
                            : 'همین صفحه، در کارت همین مدرک، دکمهٔ بارگذاری هست.',
                        'document' => $label,
                    ];
                }

                continue;
            }

            if ($panel['state'] === 'rejected') {
                $blocking = null;

                foreach ($panel['issues'] as $issue) {
                    if (($issue['severity'] ?? 'error') === 'error') {
                        $blocking = $issue;

                        break;
                    }
                }

                $actions[] = [
                    'icon' => '⛔',
                    'title' => 'فایل «'.$label.'» در بررسی اولیه رد شد'
                        .($blocking === null ? '.' : ': '.$blocking['message_fa']),
                    'detail' => (string) ($blocking['hint_fa'] ?? 'فایل سالم را دوباره بارگذاری کنید.'),
                    'document' => $label,
                ];

                continue;
            }

            $actions = array_merge($actions, $this->unreadFieldActions($panel, $label, $canReview));
        }

        foreach ($case->validationResults as $check) {
            if ($check->scope !== 'cross' || ! in_array($check->status, ['failed', 'warning'], true)) {
                continue;
            }

            $actions[] = [
                'icon' => '🔀',
                'title' => (string) $check->message_fa,
                'detail' => $canReview
                    ? 'مقدار هر دو مدرک را با تصویرشان بسنجید؛ اگر خطای خواندن بود اصلاحش کنید.'
                    : 'اگر مقدارها روی مدارک شما یکی است، خطای خواندن بوده و کارشناس اصلاحش می‌کند.',
                'document' => null,
            ];
        }

        return array_slice($actions, 0, 6);
    }

    /**
     * فیلدهای اجباریِ خوانده‌نشدهٔ یک مدرک، با راهنمای متناسب با علتش.
     *
     * سه علت متفاوت، سه راهنمای متفاوت — و ترتیبشان همان ترتیب «چقدر احتمال
     * دارد کاربر بتواند خودش حلش کند» است:
     *   تصویر از رزولوشن مرجع کوچک‌تر است  → نسخهٔ اصلی را بفرست
     *   موتور خواند ولی شکلش معتبر نبود      → نسخهٔ واضح‌تر، یا ورود دستی
     *   هیچ ردپایی نیست                      → کارشناس از روی تصویر وارد کند
     *
     * @param  array<string, mixed>  $panel
     * @return list<array{icon: string, title: string, detail: string, document: ?string}>
     */
    private function unreadFieldActions(array $panel, string $label, bool $canReview): array
    {
        $unread = [];

        foreach ($panel['fields'] as $field) {
            if ($field['required'] && $field['value'] === '') {
                $unread[$field['key']] = $field['label'];
            }
        }

        if ($unread === []) {
            return [];
        }

        $undersized = false;

        foreach ($panel['issues'] as $issue) {
            if (($issue['code'] ?? '') === 'file.below_reference_width') {
                $undersized = true;
            }
        }

        $unreadable = [];

        foreach ($panel['checks'] as $check) {
            if (str_starts_with((string) $check->rule_key, 'document.missing_required.')) {
                $unreadable = is_array($check->details['unreadable'] ?? null)
                    ? $check->details['unreadable']
                    : [];
            }
        }

        $names = implode('، ', array_map(static fn (string $name): string => '«'.$name.'»', $unread));

        $detail = match (true) {
            $undersized => 'تصویر این مدرک از اندازهٔ لازم کوچک‌تر است. '
                .($canReview
                    ? 'از متقاضی نسخهٔ اصلی همان عکس را بخواهید، یا مقدار را از روی همین تصویر دستی وارد کنید.'
                    : 'نسخهٔ اصلی همان عکس را بارگذاری کنید — پیام‌رسان‌ها عکس را کوچک می‌کنند؛ فایل را به‌صورت «سند» بفرستید.'),
            array_intersect_key($unreadable, $unread) !== [] => 'مقدار روی مدرک هست و موتور چیزی هم خواند، '
                .'ولی خوانده‌شده شکل معتبری نداشت. '
                .($canReview
                    ? 'از روی تصویر بخوانید و دستی وارد کنید.'
                    : 'نسخهٔ واضح‌تری از همین مدرک بارگذاری کنید؛ وگرنه کارشناس مقدار را دستی وارد می‌کند.'),
            default => $canReview
                ? 'مقدار را از روی تصویر همین مدرک بخوانید و دستی وارد کنید.'
                : 'کارشناس این مقدار را از روی تصویر مدرک وارد می‌کند؛ کاری از شما لازم نیست.',
        };

        return [[
            'icon' => '🔍',
            'title' => count($unread) === 1
                ? 'فیلد '.$names.' از «'.$label.'» خوانده نشد.'
                : PersianValue::toPersianDigits((string) count($unread))
                    .' فیلد از «'.$label.'» خوانده نشد: '.$names.'.',
            'detail' => $detail,
            'document' => $label,
        ]];
    }

    /** بارگذاری کامل پرونده با همهٔ رابطه‌هایی که صفحه لازم دارد. */
    private function loadCase(PermitCase $case): PermitCase
    {
        return $case->load([
            'user',
            'decidedBy',
            'serviceType.documentTypes.fields',
            'documents.documentType.fields',
            'documents.latestOcrRun',
            'extractedFields',
            'validationResults',
            'scoreComponents',
        ]);
    }

    /**
     * نام کارشناسی که هر فیلد را اصلاح کرده.
     *
     * جدا از رابطه گرفته می‌شود چون مدل ExtractedField رابطهٔ correctedBy ندارد
     * و مدل‌ها قرارداد مشترک سامانه‌اند (این تسک اجازهٔ تغییرشان را ندارد).
     *
     * @return array<int, string>
     */
    private function correctorNames(PermitCase $case): array
    {
        $ids = $case->extractedFields
            ->pluck('corrected_by')
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * یک «پنل» برای هر مدرکِ لازمِ این خدمت: تصویر + فیلدها + بررسی‌های همان مدرک.
     *
     * ترتیب و «لازم بودن» از پیوت service_type_document_type می‌آید نه از کد،
     * پس اضافه‌شدن خدمت تازه هیچ تغییری در این کنترلر نمی‌خواهد. مدرکی که در
     * فهرست خدمت نیست ولی روی پرونده هست (مثلاً بعد از تغییر دادهٔ مرجع) هم
     * ته فهرست می‌آید تا بی‌صدا گم نشود.
     *
     * @return list<array<string, mixed>>
     */
    private function documentPanels(PermitCase $case): array
    {
        $documentsByType = $case->documents->keyBy('document_type_id');
        $fieldsByDocument = $case->extractedFields->groupBy('case_document_id');
        $checksByDocument = $case->validationResults
            ->whereNotNull('case_document_id')
            ->groupBy('case_document_id');

        $panels = [];
        $seen = [];

        foreach ($case->serviceType?->documentTypes ?? collect() as $type) {
            $seen[] = (int) $type->id;

            $panels[] = $this->panel(
                $type,
                $documentsByType->get($type->id),
                (bool) ($type->pivot->is_required ?? true),
                $fieldsByDocument,
                $checksByDocument,
            );
        }

        foreach ($case->documents as $document) {
            if ($document->documentType === null || in_array((int) $document->document_type_id, $seen, true)) {
                continue;
            }

            $panels[] = $this->panel(
                $document->documentType,
                $document,
                false,
                $fieldsByDocument,
                $checksByDocument,
            );
        }

        return $panels;
    }

    /**
     * @param  Collection<int|string, Collection<int, ExtractedField>>  $fieldsByDocument
     * @param  Collection<int|string, Collection<int, \App\Models\ValidationResult>>  $checksByDocument
     * @return array<string, mixed>
     */
    private function panel(
        \App\Models\DocumentType $type,
        ?CaseDocument $document,
        bool $required,
        Collection $fieldsByDocument,
        Collection $checksByDocument,
    ): array {
        $rows = $document === null
            ? collect()
            : ($fieldsByDocument->get($document->id) ?? collect());

        $checks = $document === null
            ? collect()
            : ($checksByDocument->get($document->id) ?? collect());

        return [
            'type' => $type,
            'required' => $required,
            'document' => $document,
            'state' => $this->documentState($document),
            'image' => $this->imageUrl($document),
            'issues' => $this->precheckIssues($document),
            'checks' => $checks->sortBy(fn ($check) => $this->checkOrder($check->status))->values(),
            'fields' => $this->fieldRows($type, $rows),
            'ocr' => $document?->latestOcrRun,
        ];
    }

    /**
     * فیلدهای یک مدرک: تعریفِ نوع مدرک + آنچه واقعاً خوانده شده.
     *
     * فهرست از document_type_fields می‌آید نه از extracted_fields، چون فیلدی که
     * OCR اصلاً پیدایش نکرده مهم‌ترین چیزی است که کارشناس باید ببیند و پرش کند.
     *
     * @param  Collection<int, ExtractedField>  $rows
     * @return list<array<string, mixed>>
     */
    private function fieldRows(\App\Models\DocumentType $type, Collection $rows): array
    {
        // اگر برای یک فیلد چند ردیف باشد، دست‌نویس کارشناس مقدم است، بعد اطمینان بالاتر.
        $byKey = $rows
            ->sortByDesc(fn (ExtractedField $row): array => [
                $row->source === 'manual' ? 1 : 0,
                (float) $row->confidence,
                (int) $row->id,
            ])
            ->keyBy('field_key');

        $out = [];

        foreach ($type->fields as $field) {
            /** @var ExtractedField|null $row */
            $row = $byKey->get($field->key);

            $value = trim((string) ($row?->normalized_value ?? $row?->raw_value ?? ''));
            $manual = $row?->source === 'manual';

            $out[] = [
                'key' => (string) $field->key,
                'label' => (string) $field->label_fa,
                'value_type' => (string) $field->value_type,
                'required' => (bool) $field->is_required,
                'cross_checked' => (bool) $field->is_cross_checked,
                'row' => $row,
                'value' => $value,
                'display' => $value === '' ? '' : PersianValue::toPersianDigits($value),
                'raw' => $row?->raw_value,
                'confidence' => $row === null ? null : round((float) $row->confidence, 1),
                'source' => $row === null ? 'none' : ($manual ? 'manual' : 'ocr'),
                'hint' => $this->valueTypeHint((string) $field->value_type),
            ];
        }

        return $out;
    }

    /** راهنمای شکل مقدار — کارشناس باید بداند چه چیزی پذیرفته می‌شود. */
    private function valueTypeHint(string $valueType): string
    {
        return match ($valueType) {
            'national_id' => 'ده رقم، با رقم کنترل معتبر',
            'jalali_date' => 'تاریخ شمسی به شکل ۱۴۰۵/۰۵/۳۰',
            'digits' => 'فقط رقم',
            'vin' => 'شمارهٔ شاسی، ۱۷ نویسهٔ لاتین',
            'plate' => 'مثل «۱۲ ب ۳۴۵ ایران ۶۷»',
            default => 'متن فارسی',
        };
    }

    /** وضعیت اعتبارسنجی اولیهٔ یک مدرک — همان چهار حالت صفحهٔ مدارک. */
    private function documentState(?CaseDocument $document): string
    {
        if ($document === null) {
            return 'missing';
        }

        return match ($document->precheck_status) {
            'passed' => 'ready',
            'failed' => 'rejected',
            default => 'pending',
        };
    }

    /**
     * تصویر مدرک — فقط از روت محافظت‌شدهٔ media و فقط با عرض مجاز.
     * فایل مدرک هرگز روی دیسک عمومی نیست (قانون پروژه).
     */
    private function imageUrl(?CaseDocument $document): ?string
    {
        if ($document === null || ! is_string($document->mime) || ! str_starts_with($document->mime, 'image/')) {
            return null;
        }

        return route('media', [
            'disk' => $document->disk,
            'path' => $document->path,
            'w' => self::IMAGE_WIDTH,
        ]);
    }

    /**
     * ایرادهای اعتبارسنجی اولیه، به همان شکلی که DocumentPrecheck ذخیره کرده.
     *
     * @return list<array<string, mixed>>
     */
    private function precheckIssues(?CaseDocument $document): array
    {
        $issues = $document?->precheck_issues;

        return is_array($issues) ? array_values(array_filter($issues, 'is_array')) : [];
    }

    /**
     * بررسی‌ها بر پایهٔ حوزه، و درون هر حوزه بدترین وضعیت اول.
     *
     * @param  Collection<int, \App\Models\ValidationResult>  $checks
     * @return array<string, Collection<int, \App\Models\ValidationResult>>
     */
    private function checksByScope(Collection $checks): array
    {
        $out = [];

        foreach (array_keys(self::SCOPE_LABELS) as $scope) {
            $rows = $checks
                ->where('scope', $scope)
                ->sortBy(fn ($check) => [$this->checkOrder($check->status), (string) $check->rule_key])
                ->values();

            if ($rows->isNotEmpty()) {
                $out[$scope] = $rows;
            }
        }

        return $out;
    }

    /** ترتیب نمایش: رد، مشکوک، بررسی‌نشده، پاس. */
    private function checkOrder(?string $status): int
    {
        return match ($status) {
            'failed' => 0,
            'warning' => 1,
            'skipped' => 2,
            default => 3,
        };
    }

    /**
     * شمارش بررسی‌ها — «چند پاس، چند رد».
     *
     * @param  Collection<int, \App\Models\ValidationResult>  $checks
     * @return array<string, int>
     */
    private function checkTotals(Collection $checks): array
    {
        return [
            'passed' => $checks->where('status', 'passed')->count(),
            'warning' => $checks->where('status', 'warning')->count(),
            'failed' => $checks->where('status', 'failed')->count(),
            'skipped' => $checks->where('status', 'skipped')->count(),
        ];
    }

    // ==================================================================
    // اصلاح دستی فیلد
    // ==================================================================

    /**
     * ذخیرهٔ اصلاح‌های کارشناس روی فیلدهای یک مدرک.
     *
     * یک فرم برای هر مدرک فرستاده می‌شود ولی فقط فیلدی که واقعاً عوض شده
     * ذخیره می‌شود. دلیلش قرارداد تسک ۶۳۲ است: ردیف manual دیگر هرگز با OCR
     * به‌روز نمی‌شود، پس «دستی» کردن فیلدی که کارشناس اصلاً دست نزده، خروجی
     * ماشین را برای همیشه منجمد می‌کند.
     */
    public function updateFields(Request $request, PermitCase $case, CaseDocument $document): RedirectResponse
    {
        abort_unless(
            (int) $document->case_id === (int) $case->id,
            404,
            'این مدرک به این پرونده مربوط نیست.',
        );

        if (! $this->reviewable($case)) {
            return $this->draftRedirect($case);
        }

        $type = $document->documentType;

        abort_if($type === null, 404, 'نوع این مدرک در دادهٔ مرجع سامانه پیدا نشد؛ بدون آن نمی‌شود فیلدهایش را اصلاح کرد.');

        $definitions = $type->fields->keyBy('key');
        $existing = ExtractedField::query()
            ->where('case_id', $case->id)
            ->where('case_document_id', $document->id)
            ->get()
            ->keyBy('field_key');

        $input = $request->input('fields');
        $input = is_array($input) ? $input : [];

        $errors = [];
        $changes = [];

        foreach ($input as $key => $raw) {
            /** @var DocumentTypeField|null $definition */
            $definition = $definitions->get((string) $key);

            // فیلدی که در تعریف این نوع مدرک نیست، اصلاً وارد پرونده نمی‌شود
            if ($definition === null) {
                continue;
            }

            $label = (string) $definition->label_fa;
            $valueType = (string) $definition->value_type;
            $normalized = PersianValue::forEngine($valueType, is_scalar($raw) ? (string) $raw : '');

            /** @var ExtractedField|null $row */
            $row = $existing->get((string) $key);
            $current = trim((string) ($row?->normalized_value ?? ''));

            if ($normalized === $current) {
                continue; // دست‌نخورده
            }

            if ($normalized === '') {
                $errors['fields.'.$key] = 'مقدار «'.$label.'» را خالی نگذارید. اگر این فیلد روی تصویر مدرک '
                    .'خوانا نیست، به‌جای خالی‌کردن، پرونده را با دلیل «مدرک ناخوانا است» رد کنید.';

                continue;
            }

            $error = PersianValue::validate($valueType, $normalized, $label);

            if ($error !== null) {
                $errors['fields.'.$key] = $error;

                continue;
            }

            $changes[(string) $key] = [
                'label' => $label,
                'old' => $current,
                'new' => $normalized,
                'raw' => PersianValue::normalize(is_scalar($raw) ? (string) $raw : ''),
                'row' => $row,
            ];
        }

        if ($errors !== []) {
            return back()
                ->withInput()
                ->with('editing_document', (int) $document->id)
                ->withErrors($errors);
        }

        if ($changes === []) {
            return redirect()
                ->route('cases.show', $case)
                ->with('info', 'هیچ مقداری تغییر نکرده بود، پس چیزی ذخیره نشد. برای اصلاح، مقدار داخل کادر '
                    .'همان فیلد را عوض کنید و دوباره «ذخیرهٔ اصلاح‌ها» را بزنید.');
        }

        $scoreBefore = $case->confidence_score === null ? null : (float) $case->confidence_score;
        $decisionBefore = (string) $case->decision;
        $user = $request->user();

        $this->saveCorrections($case, $document, $changes, $user);

        // اصلاح فیلد هم تطابق بین مدارک را عوض می‌کند و هم امتیاز را؛ پس هر دو
        // مرحله دوباره اجرا می‌شوند تا کارشناس همین حالا اثر کارش را ببیند.
        // (تصمیم دستیِ قبلی دست‌نخورده می‌ماند — CaseScorer آن را بازنویسی نمی‌کند.)
        $this->validator->validate($case);
        $this->scorer->score($case);

        $case->refresh();

        $dataset = $this->pushToDataset($document, $changes, $user);

        return redirect()
            ->route('cases.show', $case)
            ->with('success', $this->correctionMessages($changes, $scoreBefore, $decisionBefore, $case, $dataset));
    }

    /**
     * نوشتن ردیف‌های اصلاح‌شده.
     *
     * @param  array<string, array<string, mixed>>  $changes
     */
    private function saveCorrections(PermitCase $case, CaseDocument $document, array $changes, User $user): void
    {
        DB::transaction(function () use ($case, $document, $changes, $user): void {
            foreach ($changes as $key => $change) {
                ExtractedField::query()->updateOrCreate(
                    [
                        'case_id' => $case->id,
                        'case_document_id' => $document->id,
                        'field_key' => $key,
                    ],
                    [
                        // raw_value همان چیزی می‌ماند که کارشناس تایپ کرد و
                        // normalized_value شکل قانونی‌اش؛ دقیقاً همان تفکیکی که
                        // FieldExtractor برای خروجی OCR رعایت می‌کند.
                        'raw_value' => $change['raw'],
                        'normalized_value' => $change['new'],
                        // مقدار دست‌نویس کارشناس قطعی است، نه حدس ماشین.
                        'confidence' => 100,
                        'source' => 'manual',
                        'corrected_by' => $user->id,
                        'corrected_at' => now(),
                    ],
                );
            }
        });

        $case->unsetRelation('extractedFields');
    }

    /**
     * پیام موفقیت — چه عوض شد، امتیاز چه شد، به دیتاست رفت یا نه.
     *
     * @param  array<string, array<string, mixed>>  $changes
     * @param  array{ok: bool, count: int, sample_id: ?int, reason: ?string}  $dataset
     * @return list<string>
     */
    private function correctionMessages(
        array $changes,
        ?float $scoreBefore,
        string $decisionBefore,
        PermitCase $case,
        array $dataset,
    ): array {
        $parts = [];

        foreach ($changes as $change) {
            $old = $change['old'] === ''
                ? 'خالی بود'
                : '«'.PersianValue::toPersianDigits((string) $change['old']).'»';

            $parts[] = $change['label'].': '.$old.' ← «'
                .PersianValue::toPersianDigits((string) $change['new']).'»';
        }

        $lines = [
            PersianValue::toPersianDigits((string) count($changes)).' فیلد اصلاح شد — '.implode('؛ ', $parts).'.',
        ];

        $scoreAfter = $case->confidence_score === null ? null : (float) $case->confidence_score;

        if ($scoreAfter !== null) {
            $lines[] = $scoreBefore === null
                ? 'امتیاز اطمینان پرونده پس از اصلاح: '.PersianValue::decimal($scoreAfter, 1).' از ۱۰۰.'
                : 'امتیاز اطمینان از '.PersianValue::decimal($scoreBefore, 1).' به '
                    .PersianValue::decimal($scoreAfter, 1).' تغییر کرد.';
        }

        if ($case->decision !== $decisionBefore && ! $case->decision_is_manual) {
            $lines[] = 'با این اصلاح، پیشنهاد ماشین هم عوض شد: '
                .(PermitCase::DECISIONS[$case->decision] ?? (string) $case->decision).'.';
        }

        $lines[] = $dataset['ok']
            ? PersianValue::toPersianDigits((string) $dataset['count']).' برچسب اصلاح‌شده به دیتاست تگ‌گذاری '
                .'اضافه شد (نمونهٔ شمارهٔ '.PersianValue::toPersianDigits((string) $dataset['sample_id']).') '
                .'و از این به بعد دادهٔ آموزشی است.'
            : 'این اصلاح به دیتاست تگ‌گذاری اضافه نشد: '.($dataset['reason'] ?? 'دلیل نامشخص');

        return $lines;
    }

    // ==================================================================
    // قانون ۱۳۰ — بازگشت اصلاح به دیتاست
    // ==================================================================

    /**
     * اصلاح کارشناس را به دیتاست تگ‌گذاری برمی‌گرداند.
     *
     * ── چه می‌سازد ──────────────────────────────────────────────────────
     * یک `DatasetSample` با `source = uploaded` که به **همان فایلِ همان مدرک**
     * اشاره می‌کند (disk و path مدرک؛ هیچ کپی‌ای از فایل گرفته نمی‌شود) و برای
     * هر فیلد اصلاح‌شده یک `DatasetAnnotation` با `source = correction` — همان
     * مقداری که ستون منبعِ جدول برایش تعریف شده بود.
     *
     * ── چرا بدون migration ─────────────────────────────────────────────
     * برای «کدام نمونهٔ دیتاست مال کدام مدرک پرونده است» ستونی وجود ندارد.
     * به‌جای ساختن ستون، جفت (disk, path) کلید یکتای طبیعی است: هر مدرک یک
     * فایل دارد و هر فایل یک نمونه. پس اصلاح دوم روی همان مدرک، نمونهٔ تکراری
     * نمی‌سازد و فقط برچسب‌ها را به‌روز می‌کند. اگر روزی گزارشِ «این نمونه از
     * کدام پرونده آمد» لازم شود، آن‌وقت ستون واقعی ارزش migration را دارد؛
     * فعلاً متن notes همان را به زبان آدمیزاد می‌گوید.
     *
     * ── قانون ۱۲۹ (هیچ مدرک واقعی وارد دیتاست نشود) ────────────────────
     * در این پروژه همهٔ مدارک مصنوعی‌اند، پس امروز مسئله‌ای نیست. ولی اگر
     * سامانه روزی مدرک واقعی بگیرد، همین مسیر عکس کارت ملی یک شهروند را وارد
     * «نمونه‌های دیتاست» و بستهٔ خروجی آموزش می‌کند. برای همین کل مسیر پشت یک
     * کلید تنظیمات است و با خاموش‌کردنش اصلاح کارشناس همچنان روی پرونده ذخیره
     * می‌شود ولی به دیتاست نمی‌رود. پیش‌فرض روشن است، چون قانون ۱۳۰ همین را
     * می‌خواهد و دادهٔ امروزِ سامانه مصنوعی است.
     *
     * @param  array<string, array<string, mixed>>  $changes
     * @return array{ok: bool, count: int, sample_id: ?int, reason: ?string}
     */
    private function pushToDataset(CaseDocument $document, array $changes, User $user): array
    {
        $fail = static fn (string $reason): array => [
            'ok' => false, 'count' => 0, 'sample_id' => null, 'reason' => $reason,
        ];

        if (! $this->datasetCollectionEnabled()) {
            return $fail('جمع‌آوری اصلاح‌ها برای دیتاست در تنظیمات خاموش است.');
        }

        if ($document->document_type_id === null) {
            return $fail('نوع این مدرک مشخص نیست و بدون نوع مدرک نمی‌شود نمونهٔ دیتاست ساخت.');
        }

        if (! is_array(config('filesystems.disks.'.$document->disk))) {
            return $fail('دیسک «'.$document->disk.'» در سامانه تعریف نشده است.');
        }

        try {
            $disk = Storage::disk($document->disk);

            if (! $disk->exists($document->path)) {
                return $fail('فایل تصویر این مدرک روی دیسک نیست، و نمونهٔ دیتاست بدون تصویر به درد آموزش نمی‌خورد.');
            }

            return DB::transaction(function () use ($document, $changes, $user): array {
                $sample = $this->datasetSampleFor($document, $user);

                foreach ($changes as $key => $change) {
                    DatasetAnnotation::query()->updateOrCreate(
                        ['dataset_sample_id' => $sample->id, 'field_key' => $key],
                        [
                            'value' => $change['new'],
                            // همان منبعی که مهاجرت جدول برایش تعریف کرده بود:
                            // «correction: اصلاح کارشناس روی پرونده»
                            'source' => 'correction',
                            'created_by' => $user->id,
                        ],
                    );
                }

                return [
                    'ok' => true,
                    'count' => count($changes),
                    'sample_id' => (int) $sample->id,
                    'reason' => null,
                ];
            });
        } catch (Throwable $exception) {
            // خرابی دیتاست نباید اصلاحِ ذخیره‌شدهٔ پرونده را زمین بزند؛ کارشناس
            // فقط باید بداند که این تکه انجام نشد.
            report($exception);

            return $fail('ذخیره در دیتاست با خطای فنی روبه‌رو شد؛ اصلاح روی پرونده ثبت شده است.');
        }
    }

    /** نمونهٔ دیتاستِ همین فایل مدرک — اگر نبود ساخته می‌شود. */
    private function datasetSampleFor(CaseDocument $document, User $user): DatasetSample
    {
        $sample = DatasetSample::query()->firstOrCreate(
            ['disk' => (string) $document->disk, 'path' => (string) $document->path],
            [
                'document_type_id' => $document->document_type_id,
                'created_by' => $user->id,
                // uploaded = تصویر واقعیِ بارگذاری‌شده، در برابر generated که
                // ساختهٔ موتور است و برچسبش از پیش درست است.
                'source' => 'uploaded',
                'original_name' => $document->original_name,
                'width' => $document->width,
                'height' => $document->height,
                'split' => 'train',
                'is_verified' => false,
                'notes' => 'از اصلاح کارشناس روی مدرک پروندهٔ '
                    .PersianValue::toPersianDigits((string) $document->permitCase?->code)
                    .' ساخته شد (مدرک شمارهٔ '.PersianValue::toPersianDigits((string) $document->id).').',
            ],
        );

        // ابعادی که موقع ساخت نمونه نبوده‌اند (نمونهٔ قدیمی) پر می‌شوند
        if ($sample->width === null && $document->width !== null) {
            $sample->forceFill(['width' => $document->width, 'height' => $document->height])->save();
        }

        return $sample;
    }

    /** آیا اصلاح‌های کارشناس به دیتاست برگردند؟ (هاردکد نیست — از تنظیمات) */
    private function datasetCollectionEnabled(): bool
    {
        try {
            $value = Setting::get(self::COLLECT_SETTING);
        } catch (Throwable) {
            // جدول تنظیمات ممکن است هنوز seed نشده باشد؛ پیش‌فرض امن = قانون ۱۳۰
            return true;
        }

        return $value === null ? true : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    // ==================================================================
    // تصمیم نهایی
    // ==================================================================

    /**
     * تصمیم انسانی روی پرونده.
     *
     * سه عمل: تایید، رد، و «پس‌گرفتن تصمیم دستی». سومی لازم است چون
     * `decision_is_manual` جلوی بازنویسی CaseScorer را می‌گیرد؛ بدون راهی برای
     * برداشتنش، یک تصمیم اشتباه تا ابد روی پرونده می‌ماند و اجرای دوبارهٔ
     * پایپ‌لاین هم اصلاحش نمی‌کند.
     */
    public function decide(Request $request, PermitCase $case): RedirectResponse
    {
        if (! $this->reviewable($case)) {
            return $this->draftRedirect($case);
        }

        $decision = (string) $request->input('decision', '');

        if ($decision === 'reset') {
            return $this->resetDecision($case);
        }

        if (! in_array($decision, ['approved', 'rejected'], true)) {
            return back()->with('error', 'تصمیم نامعتبر است. یکی از دکمه‌های «تایید پرونده» یا «رد پرونده» را بزنید.');
        }

        $reason = PersianValue::normalize((string) $request->input('reason', ''));

        if (mb_strlen($reason) > self::MAX_REASON) {
            return back()->withInput()->withErrors([
                'reason' => 'توضیح تصمیم نباید بیشتر از '.PersianValue::toPersianDigits((string) self::MAX_REASON)
                    .' نویسه باشد؛ خلاصه‌اش کنید.',
            ]);
        }

        if ($decision === 'rejected' && mb_strlen($reason) < self::MIN_REJECT_REASON) {
            return back()->withInput()->withErrors([
                'reason' => 'برای رد پرونده باید دلیل بنویسید تا متقاضی بداند چه چیزی را اصلاح کند '
                    .'(مثلاً «تصویر گواهینامه ناخواناست، دوباره و بدون انعکاس نور بگیرید»).',
            ]);
        }

        $user = $request->user();
        $score = $case->confidence_score === null
            ? 'محاسبه‌نشده'
            : PersianValue::decimal((float) $case->confidence_score, 1).' از ۱۰۰';

        $default = $decision === 'approved'
            ? 'پرونده پس از بررسی انسانی و مقایسهٔ فیلدها با تصویر مدارک تایید شد.'
            : 'پرونده پس از بررسی انسانی رد شد.';

        $case->forceFill([
            'decision' => $decision,
            'decision_reason' => 'تصمیم دستی کارشناس '.$user->name.' — '
                .($reason !== '' ? $reason : $default)
                .' (امتیاز اطمینان ماشین در لحظهٔ تصمیم: '.$score.'.)',
            // بدون این پرچم، اولین اجرای دوبارهٔ CaseScorer تصمیم را دور می‌ریزد.
            'decision_is_manual' => true,
            'decided_by' => $user->id,
            'decided_at' => now(),
            'status' => $decision,
        ])->save();

        return redirect()->route('cases.show', $case)->with('success', [
            'تصمیم شما ثبت شد: '.(PermitCase::DECISIONS[$decision] ?? $decision).'.',
            'از این پس اجرای دوبارهٔ پردازش، امتیاز را به‌روز می‌کند ولی تصمیم شما را عوض نمی‌کند. '
                .'اگر خواستید تصمیم را پس بگیرید، دکمهٔ «پس‌گرفتن تصمیم» را بزنید.',
        ]);
    }

    /** پس‌گرفتن تصمیم دستی: پرونده دوباره به قضاوت ماشین سپرده می‌شود. */
    private function resetDecision(PermitCase $case): RedirectResponse
    {
        if (! $case->decision_is_manual) {
            return back()->with('info', 'روی این پرونده تصمیم دستی ثبت نشده است؛ چیزی برای پس‌گرفتن وجود ندارد.');
        }

        $case->forceFill([
            'decision_is_manual' => false,
            'decided_by' => null,
            'decided_at' => null,
        ])->save();

        // حالا که پرچم برداشته شده، امتیازدهی دوباره تصمیم و وضعیت را می‌نویسد.
        $this->scorer->score($case);
        $case->refresh();

        return redirect()->route('cases.show', $case)->with('info', [
            'تصمیم دستی پس گرفته شد و پرونده دوباره بر پایهٔ امتیاز ماشین ارزیابی شد.',
            'نتیجهٔ تازه: '.(PermitCase::DECISIONS[$case->decision] ?? 'نامشخص').'.',
        ]);
    }

    // ==================================================================
    // ابزار
    // ==================================================================

    /**
     * آیا این پرونده قابل بررسی انسانی است؟
     *
     * پروندهٔ «پیش‌نویس» هنوز وسط ویزارد بارگذاری است: نه OCR شده، نه فیلدی
     * دارد، نه امتیازی. بررسی‌کردنش بی‌معنی است و کارشناس باید به صفحهٔ مدارک
     * برود. بقیهٔ وضعیت‌ها — حتی «تایید» و «رد» — قابل بازبینی‌اند، چون تصمیم
     * اشتباه باید قابل اصلاح باشد.
     */
    private function reviewable(PermitCase $case): bool
    {
        return $case->status !== 'draft';
    }

    private function draftRedirect(PermitCase $case): RedirectResponse
    {
        return redirect()
            ->route('cases.documents.edit', $case)
            ->with('info', 'این پرونده هنوز «پیش‌نویس» است و پردازشی رویش انجام نشده، پس چیزی برای بررسی '
                .'وجود ندارد. اول مدارک را کامل کنید و پرونده را ثبت کنید.');
    }
}
