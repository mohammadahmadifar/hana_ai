<?php

namespace App\Http\Controllers;

use App\Models\DatasetSample;
use App\Models\PermitCase;
use App\Models\Setting;
use App\Models\TestImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * صفحه نخست پنل پس از ورود.
 *
 * همه اعداد این صفحه مستقیم از دیتابیس خوانده می‌شوند؛ هیچ داده نمونه‌ای
 * hard-code نشده است. وقتی دیتابیس خالی است، صفحه باید حالت خالیِ تمیز
 * نشان بدهد نه صفر‌های بی‌معنی — به‌ویژه «میانگین»ها که روی صفر پرونده
 * معنایی ندارند و به‌جای ۰ باید «—» نشان داده شوند.
 *
 * قاعده دسترسی: داده‌های پرونده (کد، نام متقاضی، وضعیت) فقط برای نقش‌هایی
 * خوانده می‌شود که اجازه بررسی پرونده دارند. نقش «کارشناس داده» کل گروه
 * «درخواست خدمت» را در منو نمی‌بیند، پس نباید در داشبورد هم ببیند؛ به‌جایش
 * صف تگ‌گذاری دیتاست برایش نمایش داده می‌شود.
 */
class DashboardController extends Controller
{
    /** چند پرونده در جدول «صف بررسی انسانی» نشان داده شود. */
    public const REVIEW_QUEUE_SIZE = 8;

    /**
     * سقف ردیف‌هایی که برای رتبه‌بندی فوریت از دیتابیس خوانده می‌شوند.
     *
     * امتیاز فوریت ترکیبی است و در SQL قابل حمل بین sqlite و MySQL نوشته
     * نمی‌شود (تفاضل زمان در این دو یکی نیست). پس فقط «قدیمی‌ترین‌ها» خوانده
     * می‌شوند و رتبه‌بندی روی همان‌ها در PHP انجام می‌شود. چون کهنگی خودش
     * نیمی از فوریت است، پرونده‌ای که بیرون این سقف بماند ذاتاً تازه‌تر و
     * کم‌فوریت‌تر است.
     */
    public const REVIEW_SCAN_LIMIT = 200;

    /**
     * سقف انتظار قابل قبول صف بررسی (ساعت). فقط برای رتبه‌بندی نمایش است،
     * نه آستانه تصمیم؛ با این حال از settings خوانده می‌شود تا اگر روزی
     * کلیدش اضافه شد بدون تغییر کد قابل تنظیم باشد.
     */
    public const REVIEW_SLA_HOURS = 72;

    /** وزن دو مؤلفه فوریت (جمع = ۱۰۰). */
    private const URGENCY_WAIT_WEIGHT = 60.0;

    private const URGENCY_NEARNESS_WEIGHT = 40.0;

    /** شمار برچسب‌های هر نمونه — برای مرتب‌سازی «کم‌کارترین اول». */
    private const ANNOTATIONS_SQL = '(select count(*) from `dataset_annotations` `da`'
        .' where `da`.`dataset_sample_id` = `dataset_samples`.`id`)';

    /**
     * شمار فیلدهای «الزامی»ای که هنوز مقدار ندارند — عیناً همان معیاری که
     * صف تگ‌گذاری (Dataset\AnnotateController::queue) برای حالت «در انتظار کار»
     * دارد، تا عدد داشبورد و عدد آن صفحه یکی باشند.
     */
    private const MISSING_REQUIRED_SQL = '(select count(*) from `document_type_fields` `dfr`'
        .' where `dfr`.`document_type_id` = `dataset_samples`.`document_type_id`'
        .' and `dfr`.`is_required` = 1'
        .' and not exists (select 1 from `dataset_annotations` `dar`'
        .'     where `dar`.`dataset_sample_id` = `dataset_samples`.`id`'
        .'     and `dar`.`field_key` = `dfr`.`key`'
        ."     and `dar`.`value` is not null and `dar`.`value` <> ''))";

    public function index(Request $request): View
    {
        $user = $request->user();
        $canReviewCases = (bool) $user?->canReviewCases();
        $canManageDataset = (bool) $user?->canManageDataset();

        // تعداد پرونده‌ها به تفکیک وضعیت — یک کوئری برای همه وضعیت‌ها
        $rawStatusCounts = PermitCase::selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // همه وضعیت‌های تعریف‌شده را با مقدار صفر پر می‌کنیم تا ترتیب ثابت بماند
        $statusCounts = collect(PermitCase::STATUSES)
            ->map(fn ($label, $key) => (int) ($rawStatusCounts[$key] ?? 0));

        // ستون status یک varchar بدون قید است؛ ممکن است مقداری بیرون از
        // PermitCase::STATUSES در جدول باشد. «کل» باید شمارش واقعی ردیف‌ها
        // باشد و باقی‌مانده در ردیف «سایر» دیده شود، نه اینکه گم شود.
        $casesTotal = PermitCase::count();
        $casesOther = max(0, $casesTotal - (int) $statusCounts->sum());

        // میانگین‌ها در همان یک کوئریِ تجمیعی — نه شش count/avg جداگانه
        $metrics = $this->caseMetrics();

        $stats = [
            'cases_total' => $casesTotal,
            'cases_needs_review' => (int) $statusCounts->get('needs_review', 0),
            'cases_approved' => (int) $statusCounts->get('approved', 0),
            'cases_rejected' => (int) $statusCounts->get('rejected', 0),
            'dataset_samples' => DatasetSample::count(),
            'test_images' => TestImage::count(),
        ];

        // فهرست پرونده‌ها فقط برای نقش‌های مجاز خوانده می‌شود.
        $recentCases = $canReviewCases
            ? PermitCase::query()
                ->with(['serviceType:id,label_fa', 'user:id,name'])
                ->latest('id')
                ->limit(5)
                ->get()
            : collect();

        // صف بررسی انسانی — فوری‌ترین اول. باز هم فقط برای نقش‌های مجاز.
        $reviewQueue = $canReviewCases ? $this->reviewQueue() : collect();

        $pendingSamplesTotal = 0;
        $pendingSamples = collect();

        if ($canManageDataset) {
            $pendingSamplesTotal = $this->pendingSamplesQuery()->count();
            $pendingSamples = $this->pendingSamplesQuery()
                ->with(['documentType' => fn ($query) => $query->withCount('fields')])
                ->withCount('annotations')
                ->orderByRaw("case when `dataset_samples`.`source` = 'uploaded' then 0 else 1 end")
                ->orderByRaw(self::ANNOTATIONS_SQL.' asc')
                ->orderBy('dataset_samples.id')
                ->limit(5)
                ->get();
        }

        return view('dashboard.index', [
            'user' => $user,
            'stats' => $stats,
            'metrics' => $metrics,
            'statusCounts' => $statusCounts,
            'casesTotal' => $casesTotal,
            'casesOther' => $casesOther,
            'recentCases' => $recentCases,
            'reviewQueue' => $reviewQueue,
            'reviewQueueTotal' => (int) $statusCounts->get('needs_review', 0),
            'canReviewCases' => $canReviewCases,
            'canManageDataset' => $canManageDataset,
            'pendingSamples' => $pendingSamples,
            'pendingSamplesTotal' => $pendingSamplesTotal,
        ]);
    }

    /**
     * میانگین‌های پرونده در یک کوئری تجمیعی.
     *
     * AVG در هر دو موتور (sqlite و MySQL) ردیف‌های NULL را نادیده می‌گیرد،
     * پس «میانگین امتیاز» فقط روی پرونده‌های امتیازدهی‌شده حساب می‌شود و
     * پیش‌نویس‌ها آن را رقیق نمی‌کنند. اگر هیچ ردیفی نبود، مقدار null
     * برمی‌گردد تا قالب «—» نشان بدهد نه صفرِ گمراه‌کننده.
     *
     * @return array{scored:int, avg_confidence:?float, timed:int, avg_processing_ms:?float}
     */
    private function caseMetrics(): array
    {
        $row = PermitCase::query()
            ->selectRaw('COUNT(confidence_score) AS scored')
            ->selectRaw('AVG(confidence_score) AS avg_confidence')
            ->selectRaw('COUNT(processing_ms) AS timed')
            ->selectRaw('AVG(processing_ms) AS avg_processing_ms')
            ->first();

        return [
            'scored' => (int) ($row->scored ?? 0),
            'avg_confidence' => $row?->avg_confidence === null ? null : (float) $row->avg_confidence,
            'timed' => (int) ($row->timed ?? 0),
            'avg_processing_ms' => $row?->avg_processing_ms === null ? null : (float) $row->avg_processing_ms,
        ];
    }

    /**
     * صف بررسی انسانی، فوری‌ترین اول.
     *
     * تعریف «فوری» در این سامانه ترکیب دو چیز است، چون هیچ‌کدام به‌تنهایی
     * صف درستی نمی‌دهد:
     *
     *   ۱) «چقدر منتظر مانده» (وزن ۶۰) — نسبت زمان انتظار از لحظه ثبت به سقف
     *      انتظار قابل قبول. اگر فقط این باشد صف یک FIFO ساده است و پرونده‌ای
     *      که با یک اصلاح کوچک تعیین‌تکلیف می‌شود پشت پرونده‌های سنگین می‌ماند.
     *
     *   ۲) «چقدر به آستانه تایید نزدیک است» (وزن ۴۰) — جای امتیاز اطمینان در
     *      بازه [reject_below, approve_at]. پرونده‌ای که نزدیک آستانه تایید
     *      ایستاده با کمترین کار کارشناس به نتیجه می‌رسد، پس بازدهی صف را
     *      بالا می‌برد. اگر فقط این باشد، پرونده‌های کم‌امتیاز برای همیشه
     *      ته صف می‌مانند (قحطی) — به همین دلیل کنار مؤلفه انتظار می‌آید.
     *
     * پرونده بدون امتیاز فقط از مؤلفه انتظار امتیاز می‌گیرد (نزدیکی = ۰).
     *
     * @return Collection<int, array{case: PermitCase, urgency: float, wait_hours: float, nearness: float}>
     */
    private function reviewQueue(): Collection
    {
        $thresholds = Setting::get('scoring.thresholds');
        $thresholds = is_array($thresholds) ? $thresholds : [];

        $approveAt = (float) ($thresholds['approve_at'] ?? 80);
        $rejectBelow = (float) ($thresholds['reject_below'] ?? 45);
        $span = max(1.0, $approveAt - $rejectBelow);

        $slaHours = (float) (Setting::get('review.sla_hours') ?? self::REVIEW_SLA_HOURS);
        $slaHours = max(1.0, $slaHours);

        $now = Carbon::now();

        return PermitCase::query()
            ->where('status', 'needs_review')
            ->with(['serviceType:id,label_fa', 'user:id,name'])
            ->orderByRaw('COALESCE(submitted_at, created_at) asc')
            ->orderBy('id')
            ->limit(self::REVIEW_SCAN_LIMIT)
            ->get()
            ->map(function (PermitCase $case) use ($now, $slaHours, $rejectBelow, $span): array {
                $waitingSince = $case->submitted_at ?? $case->created_at;
                $waitHours = $waitingSince ? abs((float) $waitingSince->diffInHours($now)) : 0.0;

                $score = $case->confidence_score === null ? null : (float) $case->confidence_score;
                $nearness = $score === null
                    ? 0.0
                    : min(1.0, max(0.0, ($score - $rejectBelow) / $span));

                $urgency = self::URGENCY_WAIT_WEIGHT * min(1.0, $waitHours / $slaHours)
                    + self::URGENCY_NEARNESS_WEIGHT * $nearness;

                return [
                    'case' => $case,
                    'urgency' => round($urgency, 1),
                    'wait_hours' => round($waitHours, 1),
                    'nearness' => round($nearness * 100, 1),
                ];
            })
            ->sortByDesc('urgency')
            ->values()
            ->take(self::REVIEW_QUEUE_SIZE);
    }

    /**
     * نمونه‌هایی که هنوز کار تگ‌گذاری دارند: یا تایید نشده‌اند یا دست‌کم یک
     * فیلد الزامیِ خالی دارند. عمداً همان شرطِ حالت «در انتظار کار» در صف
     * تگ‌گذاری است تا عدد این کارت با عدد آن صفحه یکی باشد.
     */
    private function pendingSamplesQuery(): Builder
    {
        return DatasetSample::query()->where(function (Builder $query): void {
            $query->where('is_verified', false)
                ->orWhereRaw(self::MISSING_REQUIRED_SQL.' > 0');
        });
    }
}
