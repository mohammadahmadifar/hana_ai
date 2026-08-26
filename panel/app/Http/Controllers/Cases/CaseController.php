<?php

namespace App\Http\Controllers\Cases;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCase;
use App\Models\CaseDocument;
use App\Models\DocumentType;
use App\Models\PermitCase;
use App\Models\ServiceType;
use App\Support\PanelMenu;
use App\Support\PersianValue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * ویزارد «درخواست خدمت» — گام یکم و سوم فلوچارت پروژه.
 *
 *   انتخاب نوع خدمت  →  بارگذاری مدارک  →  ثبت و رفتن به صف بررسی
 *
 * قاعدهٔ محوری این بخش: **فهرست مدارک لازم هاردکد نیست**. هر نوع خدمت
 * مدارکش را در پیوت `service_type_document_type` دارد، پس «صدور» سه مدرک
 * و «تمدید» چهار مدرک می‌خواهد بدون آنکه هیچ‌جای کد نام خدمت را بشناسد.
 * اگر فردا خدمت سومی اضافه شود، فقط یک ردیف دیتابیس لازم است.
 *
 * تقسیم کار با CaseDocumentController: صفحهٔ مدارک و «چک‌لیست کامل بودن»
 * این‌جاست (چون ثبت نهایی هم به همان چک‌لیست نیاز دارد)، و خودِ گرفتن فایل
 * و جایگزینی‌اش آن‌جاست.
 */
class CaseController extends Controller
{
    // ==================================================================
    // فهرست پرونده‌ها
    // ==================================================================

    public function index(Request $request): View
    {
        // مدیر سامانه می‌تواند همه را ببیند؛ بقیه فقط پرونده‌های خودشان را.
        $canSeeAll = $request->user()->isAdmin();
        $mine = ! $canSeeAll || ! $request->boolean('all');

        $cases = PermitCase::query()
            ->with(['serviceType.documentTypes', 'documents', 'user'])
            ->when($mine, fn ($query) => $query->where('user_id', $request->user()->id))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        // پیشرفت هر پرونده: چند مدرکِ لازم آماده است از چندتا.
        $progress = [];

        foreach ($cases as $case) {
            $checklist = $this->checklist($case);

            $progress[$case->id] = [
                'ready' => count(array_filter(
                    $checklist,
                    static fn (array $row): bool => $row['required'] && $row['state'] === 'ready',
                )),
                'required' => count(array_filter(
                    $checklist,
                    static fn (array $row): bool => $row['required'],
                )),
                'missing' => $this->missingLabels($checklist),
            ];
        }

        return view('cases.index', [
            'cases' => $cases,
            'progress' => $progress,
            'mine' => $mine,
            'canSeeAll' => $canSeeAll,
        ]);
    }

    // ==================================================================
    // گام یکم: انتخاب نوع خدمت
    // ==================================================================

    public function create(): View
    {
        return view('cases.create', [
            'services' => $this->activeServices(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $services = $this->activeServices();

        $service = $services->firstWhere('id', $request->integer('service_type_id'));

        if (! $service) {
            return back()->withInput()->withErrors([
                'service_type_id' => 'نوع خدمت انتخاب‌نشده یا نامعتبر است. یکی از خدمت‌های فهرست بالا را انتخاب کنید.',
            ]);
        }

        $request->validate([
            'applicant_name' => ['nullable', 'string', 'max:120'],
            'applicant_national_id' => ['nullable', 'string', 'max:20'],
        ], [], [
            'applicant_name' => 'نام متقاضی',
            'applicant_national_id' => 'کد ملی متقاضی',
        ]);

        $nationalId = PersianValue::forEngine('national_id', $request->string('applicant_national_id')->toString());

        if ($nationalId !== '') {
            $error = PersianValue::validate('national_id', $nationalId, 'کد ملی متقاضی');

            if ($error !== null) {
                return back()->withInput()->withErrors(['applicant_national_id' => $error]);
            }
        }

        $case = $this->createCase(
            userId: $request->user()->id,
            serviceTypeId: (int) $service->id,
            applicantName: PersianValue::normalize($request->string('applicant_name')->toString()) ?: null,
            applicantNationalId: $nationalId !== '' ? $nationalId : null,
        );

        return redirect()
            ->route('cases.documents.edit', $case)
            ->with('success', 'پروندهٔ «'.$service->label_fa.'» با کد '
                .PersianValue::toPersianDigits($case->code)
                .' ساخته شد. حالا '
                .PersianValue::toPersianDigits((string) $service->documentTypes->count())
                .' مدرک زیر را بارگذاری کنید.');
    }

    // ==================================================================
    // گام دوم: صفحهٔ مدارک
    // ==================================================================

    public function documents(Request $request, PermitCase $case): View
    {
        $this->authorizeCase($request, $case);

        $case->load(['serviceType.documentTypes', 'documents.documentType']);

        $checklist = $this->checklist($case);
        $missing = $this->missingLabels($checklist);

        return view('cases.documents', [
            'case' => $case,
            'checklist' => $checklist,
            'missing' => $missing,
            'isComplete' => $missing === [],
            // فقط پروندهٔ پیش‌نویس قابل ویرایش است؛ بعد از ثبت، صفحه خواندنی می‌شود.
            'editable' => $case->status === 'draft',
        ]);
    }

    // ==================================================================
    // گام سوم: ثبت پرونده
    // ==================================================================

    /**
     * ثبت نهایی: پرونده از «پیش‌نویس» به «ثبت‌شده» می‌رود.
     *
     * ⚠️ نقطهٔ اتصال صف OCR (تسک ۶۳۱): این‌جا و فقط این‌جا وضعیت عوض می‌شود،
     * پس dispatch کردن Job پردازش باید دقیقاً بعد از همان save انجام شود
     * (خط علامت‌گذاری‌شده پایین‌تر). این کنترلر خودش صف را راه نمی‌اندازد.
     */
    public function submit(Request $request, PermitCase $case): RedirectResponse
    {
        $this->authorizeCase($request, $case);

        $case->load(['serviceType.documentTypes', 'documents']);

        if ($case->status !== 'draft') {
            return redirect()
                ->route('cases.documents.edit', $case)
                ->with('info', 'این پرونده قبلاً ثبت شده است و دوباره ثبت نمی‌شود؛ وضعیت فعلی‌اش «'
                    .$case->statusLabel().'» است.');
        }

        $missing = $this->missingLabels($this->checklist($case));

        if ($missing !== []) {
            return back()->with('error', 'پرونده هنوز ناقص است و ثبت نشد. این مدرک‌ها مانده‌اند: '
                .implode('، ', $missing).'. هر کدام را بارگذاری کنید تا دکمهٔ ثبت باز شود.');
        }

        $case->forceFill([
            'status' => 'submitted',
            'submitted_at' => now(),
        ])->save();

        // پرونده «در حال پردازش» علامت می‌خورد، مدارکش «در صف»، و OCR و استخراج
        // فیلد و امتیازدهی روی صف `ocr` اجرا می‌شوند — نه در چرخهٔ همین درخواست.
        ProcessCase::start($case);

        return redirect()
            ->to(PanelMenu::url('cases.show', ['case' => $case->id]) ?? route('cases.index'))
            ->with('success', 'پروندهٔ '.PersianValue::toPersianDigits($case->code)
                .' ثبت شد و برای بررسی در نوبت قرار گرفت. نتیجه در همین صفحه نمایش داده می‌شود.');
    }

    // ==================================================================
    // چک‌لیست مدارک
    // ==================================================================

    /**
     * وضعیت هر مدرکِ لازمِ این خدمت.
     *
     * ترتیب و «لازم بودن» از پیوت می‌آید، نه از کد. برای هر ردیف یکی از
     * چهار وضعیت برمی‌گردد:
     *   missing  : هنوز فایلی نیامده
     *   rejected : فایل آمده ولی اعتبارسنجی اولیه ردش کرده (باید جایگزین شود)
     *   pending  : فایل هست ولی هنوز بررسی نشده
     *   ready    : فایل هست و اعتبارسنجی اولیه را پاس کرده
     *
     * @return list<array{
     *     type: DocumentType,
     *     required: bool,
     *     document: ?CaseDocument,
     *     state: string,
     *     issues: list<array<string, mixed>>
     * }>
     */
    private function checklist(PermitCase $case): array
    {
        $byType = $case->documents->keyBy('document_type_id');

        $rows = [];

        foreach ($case->serviceType?->documentTypes ?? collect() as $type) {
            /** @var CaseDocument|null $document */
            $document = $byType->get($type->id);

            $rows[] = [
                'type' => $type,
                'required' => (bool) ($type->pivot->is_required ?? true),
                'document' => $document,
                'state' => $this->documentState($document),
                'issues' => $this->issuesOf($document),
            ];
        }

        return $rows;
    }

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
     * ایرادهای اعتبارسنجی اولیه، به همان شکلی که DocumentPrecheck ذخیره کرده.
     *
     * @return list<array<string, mixed>>
     */
    private function issuesOf(?CaseDocument $document): array
    {
        $issues = $document?->precheck_issues;

        if (! is_array($issues)) {
            return [];
        }

        return array_values(array_filter($issues, 'is_array'));
    }

    /**
     * برچسب مدارکی که هنوز آمادهٔ ثبت نیستند — همان «ناقص بودن» پرونده.
     *
     * @param  list<array<string, mixed>>  $checklist
     * @return list<string>
     */
    private function missingLabels(array $checklist): array
    {
        $labels = [];

        foreach ($checklist as $row) {
            if ($row['required'] && $row['state'] !== 'ready') {
                $labels[] = (string) $row['type']->label_fa;
            }
        }

        return $labels;
    }

    // ==================================================================
    // ابزار
    // ==================================================================

    /** @return Collection<int, ServiceType> */
    private function activeServices(): Collection
    {
        return ServiceType::query()
            ->active()
            ->with('documentTypes')
            ->orderBy('sort')
            ->get();
    }

    /**
     * ساخت پرونده با کد یکتا.
     *
     * یکتایی در دیتابیس تضمین شده (`cases.code` unique)، پس این‌جا فقط باید
     * در برابر تصادفِ همزمانی مقاوم باشیم: اگر دو کاربر در یک لحظه شماره
     * بگیرند، insert دومی خطای یکتایی می‌دهد و همان‌جا با شمارهٔ تازه دوباره
     * تلاش می‌شود. سه تلاش نخست شمارهٔ پشت‌سرهم می‌گیرد (برای انسان خواناست)
     * و بعد از آن شمارهٔ تصادفی، تا حلقه روی یک تصادف پرترافیک گیر نکند.
     */
    /**
     * ساخت پروندهٔ پیش‌نویس — منطقش روی مدل است (PermitCase::openDraft).
     *
     * چرا این‌جا نیست: همان کد را دکمهٔ «بفرست به فرایند بررسی» صفحهٔ تصویر
     * تستی هم لازم دارد و الگوی کد پرونده نباید دو جا نوشته شود.
     */
    private function createCase(
        int $userId,
        int $serviceTypeId,
        ?string $applicantName,
        ?string $applicantNationalId,
    ): PermitCase {
        return PermitCase::openDraft($userId, $serviceTypeId, $applicantName, $applicantNationalId);
    }

    /**
     * مالکیت پرونده: هر کس فقط پرونده‌های خودش را می‌بیند، مدیر سامانه همه را.
     * (همان الگوی TestImageController.)
     */
    private function authorizeCase(Request $request, PermitCase $case): void
    {
        abort_unless(
            $request->user()->isAdmin() || $case->user_id === $request->user()->id,
            403,
            'این پرونده متعلق به کاربر دیگری است و شما اجازهٔ دیدنش را ندارید.',
        );
    }
}
