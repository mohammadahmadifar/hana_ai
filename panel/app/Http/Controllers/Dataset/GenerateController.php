<?php

namespace App\Http\Controllers\Dataset;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateDatasetSamples;
use App\Models\DatasetAnnotation;
use App\Models\DatasetSample;
use App\Models\DatasetTag;
use App\Models\DocumentType;
use App\Models\GenerationBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * تولید انبوه نمونهٔ دیتاست.
 *
 * کاربر یک بار فرم را پر می‌کند، یک GenerationBatch ساخته می‌شود و کار سنگین
 * به صف «generate» می‌رود؛ صفحهٔ پیشرفت وضعیت را زنده نشان می‌دهد.
 *
 * نکته دربارهٔ ذخیرهٔ تنظیمات: جدول generation_batches ستونی برای split و
 * تگ ندارد، بنابراین این دو با کلیدهای «_split_mode» و «_tag_ids» داخل همان
 * ستون JSON اعوجاج‌ها نگه داشته می‌شوند. Job فقط کلیدهای کاتالوگ اعوجاج را
 * می‌خواند، پس این کلیدهای کمکی هیچ تداخلی ایجاد نمی‌کنند.
 */
class GenerateController extends Controller
{
    /** فرم تولید + فهرست دسته‌های اخیر. */
    public function create(): View
    {
        return view('dataset.generate.create', [
            'documentTypes' => $this->generatableTypes(),
            'tags' => DatasetTag::orderBy('name')->get(),
            'catalogue' => GenerateDatasetSamples::catalogue(),
            'splitModes' => GenerateDatasetSamples::splitModes(),
            'recentBatches' => GenerationBatch::query()
                ->with('user:id,name')
                ->latest('id')
                ->limit(8)
                ->get(),
        ]);
    }

    /** ساخت دسته و سپردن کار به صف. */
    public function store(Request $request): RedirectResponse
    {
        $catalogue = GenerateDatasetSamples::catalogue();

        $validator = Validator::make($request->all(), [
            'count' => ['required', 'integer', 'min:1', 'max:200'],
            'document_type_ids' => ['required', 'array', 'min:1'],
            'document_type_ids.*' => [
                'integer',
                Rule::exists('document_types', 'id')
                    ->where('is_generatable', true)
                    ->where('is_active', true),
            ],
            'split_mode' => ['required', 'string', Rule::in(array_keys(GenerateDatasetSamples::splitModes()))],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', Rule::exists('dataset_tags', 'id')],
            'aug' => ['nullable', 'array'],
        ], [
            'count.required' => 'تعداد نمونه را وارد کنید.',
            'count.integer' => 'تعداد نمونه باید یک عدد درست باشد.',
            'count.min' => 'دست‌کم یک نمونه لازم است.',
            'count.max' => 'در هر دسته حداکثر ۲۰۰ نمونه می‌توان ساخت.',
            'document_type_ids.required' => 'دست‌کم یک نوع مدرک را انتخاب کنید.',
            'document_type_ids.min' => 'دست‌کم یک نوع مدرک را انتخاب کنید.',
            'document_type_ids.*.exists' => 'یکی از نوع‌های مدرک انتخاب‌شده قابل تولید نیست.',
            'split_mode.required' => 'مقصد split را انتخاب کنید.',
            'split_mode.in' => 'مقصد split انتخاب‌شده معتبر نیست.',
            'tag_ids.*.exists' => 'یکی از تگ‌های انتخاب‌شده وجود ندارد.',
        ]);

        $cleanOnly = $request->boolean('clean_only');
        $augmentations = $cleanOnly ? [] : $this->normalizeAugmentations($request, $catalogue);

        $validator->after(function ($validator) use ($cleanOnly, $augmentations): void {
            if (! $cleanOnly && $augmentations === []) {
                $validator->errors()->add(
                    'aug',
                    'دست‌کم یک اعوجاج را فعال کنید، یا گزینهٔ «بدون اعوجاج — نمونهٔ تمیز» را بزنید.',
                );
            }
        });

        $data = $validator->validate();

        $typeIds = array_values(array_unique(array_map('intval', $data['document_type_ids'])));
        $tagIds = array_values(array_unique(array_map('intval', $data['tag_ids'] ?? [])));

        $batch = new GenerationBatch();
        $batch->user_id = (int) $request->user()->id;
        $batch->document_type_ids = $typeIds;
        $batch->count_requested = (int) $data['count'];
        $batch->count_done = 0;
        $batch->count_failed = 0;
        $batch->augmentations = $augmentations + [
            '_split_mode' => $data['split_mode'],
            '_tag_ids' => $tagIds,
        ];
        $batch->status = 'queued';
        $batch->save();

        GenerateDatasetSamples::dispatch($batch->id);

        $total = $batch->count_requested * count($typeIds);

        return redirect()
            ->route('dataset.generate.show', $batch)
            ->with('success', 'دستهٔ تولید ساخته شد و به صف رفت؛ '.$this->fa($total).' تصویر در راه است.');
    }

    /** صفحهٔ پیشرفت یک دسته. */
    public function show(GenerationBatch $batch): View
    {
        $batch->loadMissing('user:id,name');

        $samples = DatasetSample::query()->where('notes', 'batch:'.$batch->id);

        return view('dataset.generate.show', [
            'batch' => $batch,
            'documentTypes' => DocumentType::whereIn('id', (array) $batch->document_type_ids)
                ->orderBy('sort')
                ->get(),
            'tags' => DatasetTag::whereIn('id', (array) ($batch->augmentations['_tag_ids'] ?? []))->get(),
            'splitLabel' => GenerateDatasetSamples::splitModes()[$batch->augmentations['_split_mode'] ?? 'auto']
                ?? 'نامشخص',
            'appliedAugmentations' => $this->describeAugmentations($batch),
            'sampleCount' => (clone $samples)->count(),
            'annotationCount' => DatasetAnnotation::whereIn(
                'dataset_sample_id',
                (clone $samples)->select('id'),
            )->count(),
            'preview' => (clone $samples)->with('documentType:id,label_fa')->latest('id')->limit(8)->get(),
        ]);
    }

    /** وضعیت زندهٔ دسته — صفحهٔ پیشرفت هر ۳ ثانیه همین را می‌خواند. */
    public function status(GenerationBatch $batch): JsonResponse
    {
        $samples = DatasetSample::query()->where('notes', 'batch:'.$batch->id);

        return response()->json([
            'status' => $batch->status,
            'status_label' => GenerateDatasetSamples::statusLabel($batch->status),
            'status_tone' => GenerateDatasetSamples::statusTone($batch->status),
            'count_requested' => (int) $batch->count_requested,
            'count_done' => (int) $batch->count_done,
            'count_failed' => (int) $batch->count_failed,
            'percent' => $batch->progressPercent(),
            'error' => $batch->error,
            'finished' => in_array($batch->status, ['done', 'failed'], true),
            'sample_count' => (clone $samples)->count(),
            'annotation_count' => DatasetAnnotation::whereIn(
                'dataset_sample_id',
                (clone $samples)->select('id'),
            )->count(),
        ]);
    }

    // ------------------------------------------------------------------
    // کمکی‌ها
    // ------------------------------------------------------------------

    /** فقط نوع‌هایی که موتور می‌تواند تصویرشان را بسازد. */
    private function generatableTypes()
    {
        return DocumentType::query()
            ->where('is_generatable', true)
            ->where('is_active', true)
            ->orderBy('sort')
            ->get();
    }

    /**
     * پاک‌سازی ورودی اعوجاج‌ها: فقط کلیدهای کاتالوگ، مقدار داخل بازهٔ مجاز.
     *
     * @param  array<string, array<string, mixed>>  $catalogue
     * @return array<string, array<string, mixed>>
     */
    private function normalizeAugmentations(Request $request, array $catalogue): array
    {
        $input = $request->input('aug');
        $input = is_array($input) ? $input : [];

        $result = [];

        foreach ($catalogue as $name => $meta) {
            $row = $input[$name] ?? null;

            if (! is_array($row) || ! filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $mode = ($row['mode'] ?? 'random') === 'fixed' ? 'fixed' : 'random';

            $value = (float) $meta['default'];

            if ($mode === 'fixed' && is_numeric($row['value'] ?? null)) {
                $value = (float) $row['value'];
            }

            $value = max((float) $meta['min'], min((float) $meta['max'], $value));

            $result[$name] = [
                'enabled' => true,
                'mode' => $mode,
                'value' => round($value, (int) $meta['decimals']),
            ];
        }

        return $result;
    }

    /**
     * توصیف فارسی اعوجاج‌های یک دسته برای نمایش در صفحهٔ پیشرفت.
     *
     * @return array<int, string>
     */
    private function describeAugmentations(GenerationBatch $batch): array
    {
        $catalogue = GenerateDatasetSamples::catalogue();
        $spec = is_array($batch->augmentations) ? $batch->augmentations : [];

        $lines = [];

        foreach ($catalogue as $name => $meta) {
            $row = $spec[$name] ?? null;

            if (! is_array($row) || ! ($row['enabled'] ?? false)) {
                continue;
            }

            $lines[] = ($row['mode'] ?? 'random') === 'fixed'
                ? $meta['label'].' — '.$meta['param_label'].' '.$this->fa($row['value'] ?? $meta['default']).' '.$meta['unit']
                : $meta['label'].' — تصادفی';
        }

        return $lines;
    }

    /** رقم فارسی برای پیام‌های متنی کنترلر. */
    private function fa(int|float|string $value): string
    {
        return strtr((string) $value, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }
}
