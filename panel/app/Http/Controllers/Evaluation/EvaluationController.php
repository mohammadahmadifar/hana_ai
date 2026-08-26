<?php

namespace App\Http\Controllers\Evaluation;

use App\Http\Controllers\Controller;
use App\Jobs\RunAccuracyEvaluation;
use App\Models\DocumentType;
use App\Models\EvaluationRun;
use App\Support\PersianValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «ارزیابی دقت» — تسک ۷۲۶.
 *
 * کاربر یک عدد بین ۱۰۰ تا ۱۰۰۰ می‌دهد؛ سامانه همان تعداد مدرک مصنوعی می‌سازد،
 * هرکدام را از همان مسیری می‌خواند که یک پروندهٔ واقعی می‌رود (OCR چندمقیاسی
 * → استخراج فیلد) و مقدار خوانده‌شده را با متنی که خودش روی تصویر چاپ کرده
 * مقایسه می‌کند. خروجی یک عدد است: «چند درصد فیلدها درست خوانده شدند».
 *
 * کار سنگین است (هزار تصویر حدود چهارده دقیقه)، پس همه‌اش داخل صف اجرا می‌شود
 * و این کنترلر فقط ردیف می‌سازد و پیشرفت را نشان می‌دهد.
 */
class EvaluationController extends Controller
{
    /** کمترین و بیشترین تعداد تصویر یک اجرا — همان بازه‌ای که تسک خواسته. */
    private const MIN_IMAGES = 100;

    private const MAX_IMAGES = 1000;

    /** فرم اجرای تازه + فهرست اجراهای اخیر. */
    public function create(): View
    {
        return view('evaluation.create', [
            'documentTypes' => $this->generatableTypes(),
            'augmentModes' => RunAccuracyEvaluation::augmentModes(),
            'min' => self::MIN_IMAGES,
            'max' => self::MAX_IMAGES,
            'recentRuns' => EvaluationRun::query()
                ->with('user:id,name')
                ->latest('id')
                ->limit(8)
                ->get(),
        ]);
    }

    /** ساخت اجرا و سپردن کار به صف. */
    public function store(Request $request): RedirectResponse
    {
        $data = Validator::make($request->all(), [
            'count' => ['required', 'integer', 'min:'.self::MIN_IMAGES, 'max:'.self::MAX_IMAGES],
            'document_type_ids' => ['required', 'array', 'min:1'],
            'document_type_ids.*' => [
                'integer',
                Rule::exists('document_types', 'id')
                    ->where('is_generatable', true)
                    ->where('is_active', true),
            ],
            'augment_mode' => ['required', 'string', Rule::in(array_keys(RunAccuracyEvaluation::augmentModes()))],
        ], [
            'count.required' => 'تعداد تصویر را وارد کنید.',
            'count.integer' => 'تعداد تصویر باید یک عدد درست باشد.',
            'count.min' => 'دست‌کم '.PersianValue::toPersianDigits((string) self::MIN_IMAGES)
                .' تصویر لازم است؛ با نمونهٔ کمتر، درصدِ به‌دست‌آمده نویز است نه اندازه‌گیری.',
            'count.max' => 'در هر اجرا حداکثر '.PersianValue::toPersianDigits((string) self::MAX_IMAGES)
                .' تصویر می‌شود ارزیابی کرد.',
            'document_type_ids.required' => 'دست‌کم یک نوع مدرک را انتخاب کنید.',
            'document_type_ids.min' => 'دست‌کم یک نوع مدرک را انتخاب کنید.',
            'document_type_ids.*.exists' => 'یکی از نوع‌های مدرک انتخاب‌شده قابل تولید نیست.',
            'augment_mode.required' => 'حالت تصویر را انتخاب کنید.',
            'augment_mode.in' => 'حالت تصویر انتخاب‌شده معتبر نیست.',
        ])->validate();

        // یک اجرا در هر لحظه. هر اجرا تا چهارده دقیقه کارگر صف و هشت هستهٔ
        // ماشین را می‌گیرد؛ دو اجرای هم‌زمان نه سریع‌تر تمام می‌شوند و نه عدد
        // بهتری می‌دهند، فقط پردازش پرونده‌های واقعی را پشت خودشان نگه می‌دارند.
        $active = EvaluationRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if ($active !== null) {
            return redirect()
                ->route('evaluation.show', $active)
                ->with('info', 'ارزیابی #'.PersianValue::toPersianDigits((string) $active->id)
                    .' هنوز در حال اجراست. تا تمام‌شدنش ارزیابی تازه شروع نمی‌شود — '
                    .'پیشرفتش همین‌جاست.');
        }

        $run = new EvaluationRun;
        $run->user_id = (int) $request->user()->id;
        $run->document_type_ids = array_values(array_unique(array_map('intval', $data['document_type_ids'])));
        $run->count_requested = (int) $data['count'];
        $run->augment_mode = (string) $data['augment_mode'];
        $run->status = 'queued';
        $run->save();

        RunAccuracyEvaluation::dispatch($run->id);

        return redirect()
            ->route('evaluation.show', $run)
            ->with('success', 'ارزیابی ساخته شد و به صف رفت؛ '
                .PersianValue::toPersianDigits((string) $run->count_requested)
                .' تصویر ساخته و خوانده می‌شود. این کار چند دقیقه طول می‌کشد و '
                .'همین صفحه خودش به‌روز می‌شود.');
    }

    /** صفحهٔ پیشرفت و نتیجهٔ یک اجرا. */
    public function show(EvaluationRun $run): View
    {
        $run->loadMissing('user:id,name');

        return view('evaluation.show', [
            'run' => $run,
            'documentTypes' => DocumentType::whereIn('id', (array) $run->document_type_ids)
                ->orderBy('sort')
                ->get(),
            'augmentLabel' => RunAccuracyEvaluation::augmentModes()[$run->augment_mode] ?? 'نامشخص',
            'rows' => $this->breakdownRows($run),
        ]);
    }

    /** وضعیت زنده (JSON) — صفحهٔ پیشرفت هر ۳ ثانیه همین را می‌خواند. */
    public function status(EvaluationRun $run): JsonResponse
    {
        return response()->json([
            'status' => $run->status,
            'status_label' => RunAccuracyEvaluation::statusLabel($run->status),
            'status_tone' => RunAccuracyEvaluation::statusTone($run->status),
            'count_requested' => (int) $run->count_requested,
            'count_done' => (int) $run->count_done,
            'count_failed' => (int) $run->count_failed,
            'percent' => $run->progressPercent(),
            'fields_total' => (int) $run->fields_total,
            'fields_correct' => (int) $run->fields_correct,
            'accuracy' => $run->accuracy(),
            'avg_confidence' => $run->averageConfidence(),
            'error' => $run->error,
            'finished' => $run->isFinished(),
        ]);
    }

    // ------------------------------------------------------------------
    // کمکی‌ها
    // ------------------------------------------------------------------

    /**
     * جدول «نوع مدرک × فیلد» آمادهٔ نمایش.
     *
     * درصدها همین‌جا از شمارش خام ساخته می‌شوند، نه در ویو: ویو نباید بداند
     * فیلدی که هرگز چاپ نشده (count = 0) چطور باید نمایش داده شود.
     *
     * @return list<array<string, mixed>>
     */
    private function breakdownRows(EvaluationRun $run): array
    {
        $breakdown = is_array($run->breakdown) ? $run->breakdown : [];
        $out = [];

        foreach ($breakdown as $typeKey => $type) {
            if (! is_array($type)) {
                continue;
            }

            $fields = [];
            $count = 0;
            $correct = 0;
            $confidence = 0.0;

            foreach (is_array($type['fields'] ?? null) ? $type['fields'] : [] as $fieldKey => $cell) {
                if (! is_array($cell) || (int) ($cell['count'] ?? 0) < 1) {
                    continue;
                }

                $cellCount = (int) $cell['count'];
                $cellCorrect = (int) ($cell['correct'] ?? 0);
                $cellConfidence = (float) ($cell['confidence'] ?? 0);

                $fields[] = [
                    'key' => (string) $fieldKey,
                    'label' => (string) ($cell['label'] ?? $fieldKey),
                    'count' => $cellCount,
                    'correct' => $cellCorrect,
                    'accuracy' => round(100 * $cellCorrect / $cellCount, 1),
                    'confidence' => round($cellConfidence / $cellCount, 1),
                ];

                $count += $cellCount;
                $correct += $cellCorrect;
                $confidence += $cellConfidence;
            }

            if ($fields === []) {
                continue;
            }

            // ضعیف‌ترین فیلد بالای فهرست: همان چیزی که باید بعداً درست شود.
            usort($fields, static fn (array $a, array $b): int => $a['accuracy'] <=> $b['accuracy']);

            $out[] = [
                'key' => (string) $typeKey,
                'label' => (string) ($type['label'] ?? $typeKey),
                'samples' => (int) ($type['samples'] ?? 0),
                'count' => $count,
                'correct' => $correct,
                'accuracy' => round(100 * $correct / $count, 1),
                'confidence' => round($confidence / $count, 1),
                'fields' => $fields,
            ];
        }

        usort($out, static fn (array $a, array $b): int => $a['accuracy'] <=> $b['accuracy']);

        return $out;
    }

    private function generatableTypes()
    {
        return DocumentType::query()
            ->where('is_generatable', true)
            ->where('is_active', true)
            ->orderBy('sort')
            ->get();
    }
}
