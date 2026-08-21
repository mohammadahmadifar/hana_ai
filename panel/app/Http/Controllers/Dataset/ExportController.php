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

        return redirect()
            ->route('dataset.export.index')
            ->with('success', 'ساخت بستهٔ خروجی در صف قرار گرفت؛ به‌محض آماده شدن، دکمهٔ دانلودش در فهرست پایین فعال می‌شود.');
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
