<?php

namespace App\Http\Controllers;

use App\Models\DatasetSample;
use App\Models\PermitCase;
use App\Models\TestImage;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * صفحه نخست پنل پس از ورود.
 *
 * همه اعداد این صفحه مستقیم از دیتابیس خوانده می‌شوند؛ هیچ داده نمونه‌ای
 * hard-code نشده است. وقتی دیتابیس خالی است، صفحه باید حالت خالیِ تمیز
 * نشان بدهد نه صفر‌های بی‌معنی.
 */
class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        // تعداد پرونده‌ها به تفکیک وضعیت — یک کوئری برای همه وضعیت‌ها
        $rawStatusCounts = PermitCase::selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // همه وضعیت‌های تعریف‌شده را با مقدار صفر پر می‌کنیم تا ترتیب ثابت بماند
        $statusCounts = collect(PermitCase::STATUSES)
            ->map(fn ($label, $key) => (int) ($rawStatusCounts[$key] ?? 0));

        $casesTotal = (int) $statusCounts->sum();

        $stats = [
            'cases_total' => $casesTotal,
            'cases_needs_review' => (int) $statusCounts->get('needs_review', 0),
            'cases_approved' => (int) $statusCounts->get('approved', 0),
            'cases_rejected' => (int) $statusCounts->get('rejected', 0),
            'dataset_samples' => DatasetSample::count(),
            'test_images' => TestImage::count(),
        ];

        $recentCases = PermitCase::query()
            ->with(['serviceType:id,label_fa', 'user:id,name'])
            ->latest('id')
            ->limit(5)
            ->get();

        return view('dashboard.index', [
            'user' => $user,
            'stats' => $stats,
            'statusCounts' => $statusCounts,
            'casesTotal' => $casesTotal,
            'recentCases' => $recentCases,
        ]);
    }
}
