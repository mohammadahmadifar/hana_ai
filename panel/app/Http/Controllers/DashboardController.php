<?php

namespace App\Http\Controllers;

use App\Models\DatasetSample;
use App\Models\PermitCase;
use App\Models\TestImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * صفحه نخست پنل پس از ورود.
 *
 * همه اعداد این صفحه مستقیم از دیتابیس خوانده می‌شوند؛ هیچ داده نمونه‌ای
 * hard-code نشده است. وقتی دیتابیس خالی است، صفحه باید حالت خالیِ تمیز
 * نشان بدهد نه صفر‌های بی‌معنی.
 *
 * قاعده دسترسی: داده‌های پرونده (کد، نام متقاضی، وضعیت) فقط برای نقش‌هایی
 * خوانده می‌شود که اجازه بررسی پرونده دارند. نقش «کارشناس داده» کل گروه
 * «درخواست خدمت» را در منو نمی‌بیند، پس نباید در داشبورد هم ببیند؛ به‌جایش
 * صف تگ‌گذاری دیتاست برایش نمایش داده می‌شود.
 */
class DashboardController extends Controller
{
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
        ."     where `dar`.`dataset_sample_id` = `dataset_samples`.`id`"
        ."     and `dar`.`field_key` = `dfr`.`key`"
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
            'statusCounts' => $statusCounts,
            'casesTotal' => $casesTotal,
            'casesOther' => $casesOther,
            'recentCases' => $recentCases,
            'canReviewCases' => $canReviewCases,
            'canManageDataset' => $canManageDataset,
            'pendingSamples' => $pendingSamples,
            'pendingSamplesTotal' => $pendingSamplesTotal,
        ]);
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
