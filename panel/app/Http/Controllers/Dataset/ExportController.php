<?php

namespace App\Http\Controllers\Dataset;

use App\Http\Controllers\Controller;
use App\Jobs\BuildDatasetExport;
use App\Models\DatasetSample;
use App\Models\DatasetTag;
use App\Models\DocumentType;
use App\Support\DatasetExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * خروجی دیتاست برای آموزش مدل.
 *
 * فیلتر می‌گیریم، تعداد نمونه را زنده نشان می‌دهیم و ساخت بسته را به صف
 * می‌سپاریم. فهرست خروجی‌ها مستقیم از دیسک «exports» خوانده می‌شود؛ هیچ
 * جدولی برای این بخش وجود ندارد.
 *
 * سه لایه نگهبان دیسک: محدودیت نرخ برای هر کاربر، ممنوعیت بستهٔ دوم تا
 * پایان بستهٔ در حال ساخت، و پاک‌سازی خودکار بسته‌های قدیمی هنگام هر
 * درخواست تازه.
 */
class ExportController extends Controller
{
    public function __construct(private readonly DatasetExporter $exporter) {}

    /** فرم فیلتر + فهرست بسته‌های ساخته‌شده. */
    public function index(Request $request): View
    {
        $filters = DatasetExporter::normalizeFilters($request->all());
        $options = DatasetExporter::normalizeOptions($request->all());

        $countsPerType = DatasetSample::query()
            ->selectRaw('document_type_id, COUNT(*) AS aggregate_total')
            ->groupBy('document_type_id')
            ->pluck('aggregate_total', 'document_type_id');

        $exports = DatasetExporter::listAll();
        $userId = (int) ($request->user()?->id ?? 0);

        return view('dataset.export.index', [
            'documentTypes' => DocumentType::query()->orderBy('sort')->get(['id', 'key', 'label_fa']),
            'countsPerType' => $countsPerType,
            'tags' => DatasetTag::query()->orderBy('id')->get(['id', 'name', 'color']),
            'filters' => $filters,
            'options' => $options,
            'preview' => $this->exporter->preview($filters),
            'exports' => $exports,
            'pendingCount' => collect($exports)->where('is_pending', true)->count(),
            'totalSamples' => (int) $countsPerType->sum(),
            'formats' => DatasetExporter::FORMATS,
            'formatHints' => DatasetExporter::FORMAT_HINTS,
            'sources' => DatasetExporter::SOURCES,
            'splits' => DatasetExporter::SPLITS,
            'maxSamples' => DatasetExporter::MAX_SAMPLES,
            'quota' => [
                'limit' => DatasetExporter::RATE_LIMIT_PER_HOUR,
                'left' => max(0, RateLimiter::remaining($this->rateKey($userId), DatasetExporter::RATE_LIMIT_PER_HOUR)),
                'note' => DatasetExporter::quotaNote(),
                'retention' => DatasetExporter::retentionNote(),
                'has_pending' => DatasetExporter::pendingForUser($userId, $exports) !== null,
            ],
        ]);
    }

    /** پیش‌نمایش زندهٔ تعداد نمونه‌های منطبق با فیلتر (فراخوانی از جاوااسکریپت). */
    public function preview(Request $request): JsonResponse
    {
        $data = $this->validated($request, requireFormat: false);

        return response()->json($this->exporter->preview($data));
    }

    /** ثبت درخواست ساخت بسته و سپردن آن به صف. */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, requireFormat: true);

        $filters = DatasetExporter::normalizeFilters($data);
        $options = DatasetExporter::normalizeOptions($data);

        if ($options['resplit'] && array_sum($options['ratios']) !== 100) {
            throw ValidationException::withMessages([
                'train_ratio' => 'مجموع درصدهای بازتقسیم باید دقیقاً ۱۰۰ باشد.',
            ]);
        }

        $user = $request->user();
        $userId = (int) ($user?->id ?? 0);

        // لایهٔ ۱ — هم‌زمان فقط یک بسته برای هر کاربر.
        $pending = DatasetExporter::pendingForUser($userId);
        if ($pending !== null) {
            return redirect()->route('dataset.export.index')->with(
                'warning',
                'شما همین حالا بستهٔ «'.$pending['token'].'» را در حال ساخت دارید؛ تا تمام شدنش بستهٔ تازه‌ای ساخته نمی‌شود.',
            );
        }

        // لایهٔ ۲ — سقف تعداد درخواست در هر ساعت.
        $rateKey = $this->rateKey($userId);
        if (RateLimiter::tooManyAttempts($rateKey, DatasetExporter::RATE_LIMIT_PER_HOUR)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($rateKey) / 60));

            return redirect()->route('dataset.export.index')->with('warning', sprintf(
                'سقف ساخت %s بسته در هر ساعت پر شده است؛ %s دقیقهٔ دیگر دوباره تلاش کنید.',
                DatasetExporter::faDigits((string) DatasetExporter::RATE_LIMIT_PER_HOUR),
                DatasetExporter::faDigits((string) $minutes),
            ));
        }

        RateLimiter::hit($rateKey, DatasetExporter::RATE_LIMIT_WINDOW);

        // لایهٔ ۳ — پاک‌سازی خودکار بسته‌های قدیمی پیش از اشغال دیسک تازه.
        $pruned = DatasetExporter::prune();

        $token = DatasetExporter::newToken();

        DatasetExporter::writeMeta($token, [
            'token' => $token,
            'status' => 'queued',
            'format' => $options['format'],
            'format_label' => DatasetExporter::FORMATS[$options['format']],
            'filters' => $filters,
            'options' => $options,
            'filters_fa' => DatasetExporter::describeFilters($filters, $options),
            'created_at' => now()->toIso8601String(),
            'started_at' => null,
            'finished_at' => null,
            'created_by' => ['id' => $user?->id, 'name' => $user?->name],
            'progress' => ['done' => 0, 'total' => 0],
            'counts' => null,
            'error' => null,
        ]);

        BuildDatasetExport::dispatch($token);

        $message = ['ساخت بستهٔ خروجی در صف قرار گرفت؛ به‌محض آماده شدن، دکمهٔ دانلودش در فهرست پایین فعال می‌شود.'];

        if ($pruned !== []) {
            $message[] = sprintf(
                'در همین حال %s بستهٔ قدیمی طبق قانون نگهداری حذف شد.',
                DatasetExporter::faDigits((string) count($pruned)),
            );
        }

        return redirect()
            ->route('dataset.export.index')
            ->with('success', $message);
    }

    /** وضعیت خروجی‌ها برای به‌روزرسانی خودکار فهرست. */
    public function status(): JsonResponse
    {
        $items = [];

        foreach (DatasetExporter::listAll() as $export) {
            $progress = $export['progress'] ?? ['done' => 0, 'total' => 0];
            $total = (int) ($progress['total'] ?? 0);
            $done = (int) ($progress['done'] ?? 0);

            $items[] = [
                'token' => $export['token'],
                'status' => $export['status'],
                'status_label' => $export['status_label'],
                'percent' => $total > 0 ? min(100, (int) round(100 * $done / $total)) : 0,
                'done' => $done,
                'total' => $total,
            ];
        }

        return response()->json([
            'items' => $items,
            'pending' => collect($items)->whereIn('status', ['queued', 'running'])->count(),
        ]);
    }

    /**
     * دانلود بسته.
     *
     * نام فایل از ورودی کاربر ساخته نمی‌شود: فقط شناسهٔ الگودار خروجی که
     * روت هم آن را محدود کرده، و مسیر ثابت روی دیسک خصوصی «exports».
     */
    public function download(string $token): StreamedResponse
    {
        abort_unless(DatasetExporter::isValidToken($token), 404);
        abort_if(DatasetExporter::readMeta($token) === null, 404);

        $disk = Storage::disk(DatasetExporter::DISK);
        $path = DatasetExporter::zipPath($token);

        abort_unless($disk->exists($path), 404);

        return $disk->download($path, $token.'.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }

    /** حذف بسته و فایل وضعیتش. */
    public function destroy(string $token): RedirectResponse
    {
        abort_unless(DatasetExporter::isValidToken($token), 404);

        $meta = DatasetExporter::readMeta($token);
        abort_if($meta === null, 404);

        if (in_array($meta['status'] ?? '', ['queued', 'running'], true)) {
            return redirect()->route('dataset.export.index')
                ->with('warning', 'این بسته هنوز در حال ساخت است؛ تا پایان کارش نمی‌شود حذفش کرد.');
        }

        DatasetExporter::forget($token);

        return redirect()->route('dataset.export.index')
            ->with('success', 'بستهٔ خروجی حذف شد.');
    }

    /** کلید محدودیت نرخ — برای هر کاربر جداگانه. */
    private function rateKey(int $userId): string
    {
        return 'dataset-export:'.($userId > 0 ? $userId : 'guest');
    }

    /** اعتبارسنجی مشترک فرم پیش‌نمایش و فرم ساخت. */
    private function validated(Request $request, bool $requireFormat): array
    {
        $rules = [
            'document_type_ids' => ['nullable', 'array'],
            'document_type_ids.*' => ['integer', 'exists:document_types,id'],
            'sources' => ['nullable', 'array'],
            'sources.*' => ['string', 'in:'.implode(',', array_keys(DatasetExporter::SOURCES))],
            'splits' => ['nullable', 'array'],
            'splits.*' => ['string', 'in:'.implode(',', array_keys(DatasetExporter::SPLITS))],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:dataset_tags,id'],
            'verified_only' => ['nullable', 'boolean'],
            'with_boxes_only' => ['nullable', 'boolean'],
            'format' => [$requireFormat ? 'required' : 'nullable', 'string', 'in:'.implode(',', array_keys(DatasetExporter::FORMATS))],
            'include_images' => ['nullable', 'boolean'],
            'resplit' => ['nullable', 'boolean'],
            'train_ratio' => ['nullable', 'integer', 'min:0', 'max:100'],
            'val_ratio' => ['nullable', 'integer', 'min:0', 'max:100'],
            'test_ratio' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];

        $attributes = [
            'document_type_ids' => 'نوع مدرک',
            'document_type_ids.*' => 'نوع مدرک',
            'sources' => 'منبع نمونه',
            'sources.*' => 'منبع نمونه',
            'splits' => 'بخش دیتاست',
            'splits.*' => 'بخش دیتاست',
            'tag_ids' => 'تگ',
            'tag_ids.*' => 'تگ',
            'verified_only' => 'فقط تاییدشده‌ها',
            'with_boxes_only' => 'فقط نمونه‌های دارای کادر',
            'format' => 'قالب خروجی',
            'include_images' => 'شامل تصویرها',
            'resplit' => 'بازتقسیم',
            'train_ratio' => 'درصد آموزش',
            'val_ratio' => 'درصد اعتبارسنجی',
            'test_ratio' => 'درصد آزمون',
        ];

        $messages = [
            'required' => 'وارد کردن :attribute لازم است.',
            'in' => 'مقدار :attribute معتبر نیست.',
            'exists' => 'مقدار انتخاب‌شده برای :attribute در سامانه نیست.',
            'integer' => 'مقدار :attribute باید عدد باشد.',
            'array' => 'مقدار :attribute باید فهرست باشد.',
            'boolean' => 'مقدار :attribute باید بله یا خیر باشد.',
            'min' => 'مقدار :attribute نباید کمتر از :min باشد.',
            'max' => 'مقدار :attribute نباید بیشتر از :max باشد.',
        ];

        return $request->validate($rules, $messages, $attributes);
    }
}
