<?php

namespace App\Jobs;

use App\Exceptions\EngineException;
use App\Models\DocumentType;
use App\Models\EvaluationRun;
use App\Services\Cases\FieldExtractor;
use App\Services\HanaEngine;
use App\Support\PersianValue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * ارزیابی دقت روی دستهٔ بزرگ تصویر مصنوعی (تسک ۷۲۶).
 *
 * ```
 * شخص مصنوعی → تصویر مدرک → OCR چندمقیاسی → FieldExtractor → مقایسه با متنِ چاپ‌شده
 * ```
 *
 * ### چرا برچسب رایگان است
 * تصویر را خودمان می‌سازیم، پس «چه چیزی روی مدرک نوشته شده» را از قبل می‌دانیم
 * (قانون پروژه ۱۳۰). این تنها جایی است که می‌شود دقت را بدون هیچ تگ‌گذاری
 * انسانی و روی هزار نمونه اندازه گرفت.
 *
 * ### سه قاعده‌ای که عدد را درست نگه می‌دارند
 * ۱. **فیلدی که ژنراتور چاپ نکرده سنجیده نمی‌شود.** قالب گواهینامه تاریخ انقضا
 *    ندارد؛ شمردنش به‌عنوان «غلط» دقت را الکی پایین می‌آورد و شمردنش به‌عنوان
 *    «درست» بالا. از مخرج بیرون است.
 * ۲. **مقایسه روی شکل قانونی است** (`PersianValue::forEngine`)، نه رشتهٔ خام،
 *    وگرنه «ايليا» عربی و «ایلیا» فارسی دو مقدار متفاوت شمرده می‌شوند.
 * ۳. **تصویری که ساخته نشد یا OCRش ترکید** در `count_failed` می‌نشیند و هیچ
 *    فیلدی از آن وارد مخرج نمی‌شود — سکوت کردنش یعنی «۱۰۰٪ موفق» روی دسته‌ای
 *    که نیمی‌اش اصلاً پردازش نشده.
 *
 * ### چرا دسته تکه‌تکه می‌شود
 * موتور هر تکه را در یک پروسه و با استخر چند کارگره پردازش می‌کند (حدود ۰٫۹
 * ثانیه برای هر تصویر). بعد از هر تکه شمارنده‌ها ذخیره می‌شوند تا صفحهٔ پیشرفت
 * زنده باشد، و اگر از مرز زمانی نرم رد شدیم بقیهٔ کار به یک Job تازه سپرده
 * می‌شود؛ هم به timeout نمی‌خوریم، هم صف برای کارهای دیگر آزاد می‌شود.
 */
class RunAccuracyEvaluation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** تلاش دوم از همان‌جایی که کار مانده ادامه می‌دهد. */
    public int $tries = 2;

    public int $timeout = 600;

    /**
     * بعد از این مدت، بقیهٔ دسته به یک Job تازه سپرده می‌شود.
     *
     * عمداً کوتاه است: کارگر صف یکی است و پردازش پرونده‌های واقعی نباید پشت یک
     * ارزیابی چهارده‌دقیقه‌ای بماند. هر بار که کار به صف برمی‌گردد، کارهای
     * صف‌های جلوتر (ocr) نوبت می‌گیرند.
     */
    private const SOFT_DEADLINE_SECONDS = 120;

    /** سقف تکه — همان سقفی که موتور می‌پذیرد (hana_engine/evaluate.py: MAX_ITEMS). */
    private const MAX_CHUNK = 200;

    /** بیشترین تعداد خواندن نادرستی که برای نمایش نگه داشته می‌شود. */
    private const MAX_MISSES = 60;

    /** بیشترین تعداد تصویری که برای دیدن نگه داشته می‌شود. */
    private const MAX_PREVIEWS = 6;

    public function __construct(public int $runId)
    {
        $this->onQueue('generate');
    }

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            'queued' => 'در صف',
            'running' => 'در حال ارزیابی',
            'done' => 'پایان‌یافته',
            'failed' => 'ناموفق',
            default => 'نامشخص',
        };
    }

    public static function statusTone(?string $status): string
    {
        return match ($status) {
            'queued' => 'info',
            'running' => 'warn',
            'done' => 'ok',
            'failed' => 'bad',
            default => 'info',
        };
    }

    /** حالت‌های تصویر — فرم و صفحهٔ نتیجه هر دو از همین می‌خوانند. */
    public static function augmentModes(): array
    {
        return [
            'clean' => 'تصویر تمیز — بدون اعوجاج',
            'random' => 'با اعوجاج تصادفی — شبیه عکس واقعی موبایل',
        ];
    }

    // ------------------------------------------------------------------

    public function handle(HanaEngine $engine, FieldExtractor $extractor): void
    {
        $run = EvaluationRun::find($this->runId);

        if ($run === null) {
            Log::warning('evaluation: run not found', ['run_id' => $this->runId]);

            return;
        }

        if ($run->isFinished()) {
            return;
        }

        $types = DocumentType::query()
            ->with('fields')
            ->whereIn('id', (array) $run->document_type_ids)
            ->where('is_generatable', true)
            ->where('is_active', true)
            ->orderBy('sort')
            ->get();

        if ($types->isEmpty()) {
            $this->markFailed($run, 'هیچ نوع مدرک قابل تولیدی برای این ارزیابی باقی نمانده است.');

            return;
        }

        $run->forceFill([
            'status' => 'running',
            'started_at' => $run->started_at ?? Carbon::now(),
        ])->save();

        // سقف بالا اختیاری نیست: هم `generate_person` و هم `evaluate_batch` در
        // موتور بیش از ۲۰۰ آیتم را رد می‌کنند، پس تکهٔ بزرگ‌تر یعنی مردنِ اجرا
        // از همان تکهٔ اول.
        $chunkSize = max(1, min(self::MAX_CHUNK, (int) config('hana.evaluate_chunk', 40)));
        $workers = max(1, (int) config('hana.evaluate_workers', 8));
        $startedAt = microtime(true);

        while ($run->processed() < (int) $run->count_requested) {
            $remaining = (int) $run->count_requested - $run->processed();
            $size = min($chunkSize, $remaining);
            $before = $run->processed();

            try {
                $this->runChunk($engine, $extractor, $run, $types, $size, $workers);
            } catch (EngineException $exception) {
                Log::error('evaluation: chunk failed', [
                    'run_id' => $run->id,
                ] + $exception->context());

                $this->markFailed($run, $exception->userMessage());

                return;
            }

            $run->refresh();

            // تکه‌ای که هیچ تصویری جلو نبرد یعنی موتور کمتر از خواسته برگردانده
            // است؛ بدون این نگهبان همان تکه تا ابد دوباره اجرا می‌شود و کارگر
            // صف را قفل می‌کند.
            if ($run->processed() <= $before) {
                $this->markFailed(
                    $run,
                    'موتور برای این تکه هیچ نتیجه‌ای برنگرداند، پس ارزیابی جلو نمی‌رود و متوقف شد. '
                    .'لاگ موتور را ببینید و دوباره اجرا کنید.'
                );

                return;
            }

            if ($run->processed() >= (int) $run->count_requested) {
                break;
            }

            if ((microtime(true) - $startedAt) > self::SOFT_DEADLINE_SECONDS) {
                Log::info('evaluation: handing the rest to a fresh job', [
                    'run_id' => $run->id,
                    'processed' => $run->processed(),
                    'requested' => $run->count_requested,
                ]);

                self::dispatch($run->id);

                return;
            }
        }

        $this->markDone($run);
    }

    public function failed(?Throwable $exception): void
    {
        $run = EvaluationRun::find($this->runId);

        if ($run === null || $run->isFinished()) {
            return;
        }

        $message = $exception instanceof EngineException
            ? $exception->userMessage()
            : 'ارزیابی با خطای پیش‌بینی‌نشده متوقف شد.';

        $detail = $exception?->getMessage();

        if (filled($detail)) {
            $message .= ' — '.Str::limit($detail, 300);
        }

        $this->markFailed($run, $message);
    }

    // ------------------------------------------------------------------
    // یک تکه
    // ------------------------------------------------------------------

    /**
     * @param  \Illuminate\Support\Collection<int, DocumentType>  $types
     */
    private function runChunk(
        HanaEngine $engine,
        FieldExtractor $extractor,
        EvaluationRun $run,
        $types,
        int $size,
        int $workers,
    ): void {
        $people = $engine->generatePerson($size)['people'] ?? [];

        if (! is_array($people) || $people === []) {
            throw EngineException::fromEngine('generate_person', 'موتور هیچ دادهٔ شخصی برنگرداند.', '');
        }

        $offset = $run->processed();
        // چند تصویر اجازهٔ ماندن دارند؟ هزار تصویر حدود دو گیگابایت است و
        // عددِ این صفحه به فایل نیاز ندارد؛ فقط چند نمونه برای دیدن می‌ماند.
        $keepBudget = max(0, self::MAX_PREVIEWS - count(is_array($run->previews) ? $run->previews : []));
        $items = [];

        foreach (array_values($people) as $position => $person) {
            if ($position >= $size || ! is_array($person)) {
                continue;
            }

            $index = $offset + $position;

            // نوع مدرک به‌ترتیب می‌چرخد تا سهم هر نوع در دسته برابر بماند؛
            // اگر تصادفی انتخاب می‌شد، درصدِ کل به بخت وابسته می‌شد.
            $type = $types[$index % $types->count()];

            $keep = $keepBudget > 0;

            if ($keep) {
                $keepBudget--;
            }

            $items[] = [
                'index' => $index,
                'document_type' => (string) $type->key,
                'payload' => $person,
                'augmentations' => $this->augmentationsFor($run),
                'keep' => $keep,
                'basename' => 'run'.$run->id.'_'.$index.'_'.$type->key,
            ];
        }

        $result = $engine->evaluateBatch($items, $this->outDir($run), $workers);

        $this->absorb($extractor, $run, $types, is_array($result['items'] ?? null) ? $result['items'] : []);
    }

    /**
     * نتیجهٔ یک تکه را به شمارنده‌های دسته اضافه می‌کند.
     *
     * @param  \Illuminate\Support\Collection<int, DocumentType>  $types
     * @param  list<array<string, mixed>>  $items
     */
    private function absorb(FieldExtractor $extractor, EvaluationRun $run, $types, array $items): void
    {
        $byKey = $types->keyBy('key');
        $breakdown = is_array($run->breakdown) ? $run->breakdown : [];
        $misses = is_array($run->misses) ? $run->misses : [];
        $previews = is_array($run->previews) ? $run->previews : [];

        $done = 0;
        $failed = 0;
        $total = 0;
        $correct = 0;
        $confidence = 0.0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            /** @var DocumentType|null $type */
            $type = $byKey->get((string) ($item['document_type'] ?? ''));
            $truth = array_filter(
                is_array($item['fields'] ?? null) ? $item['fields'] : [],
                static fn ($text): bool => trim((string) $text) !== '',
            );

            // تصویری که «سالم» برگشته ولی هیچ متنی روی آن چاپ نشده، سالم نیست:
            // بدون این شرط، یک خرابیِ سیستماتیکِ رندر به‌شکل «۱۰۰٪ روی هزار
            // تصویر» درمی‌آمد با مخرجی که بی‌سروصدا آب رفته است.
            $reason = match (true) {
                ($item['ok'] ?? false) !== true => $item['error'] ?? 'موتور دلیلی نگفت.',
                $type === null => 'نوع مدرک «'.($item['document_type'] ?? '؟')
                    .'» در دادهٔ مرجع این ارزیابی نیست.',
                $truth === [] => 'موتور تصویر را ساخت ولی هیچ فیلدی روی آن چاپ نشده بود.',
                default => null,
            };

            if ($reason !== null) {
                $failed++;

                if (! filled($run->error)) {
                    // نخستین خطا کافی است؛ هزار تکرار همان جمله چیزی اضافه نمی‌کند.
                    $run->error = Str::limit('نمونه‌ای پردازش نشد: '.$reason, 500);
                }

                continue;
            }

            $done++;
            $variants = $this->variantsOf($item);
            $got = $extractor->fieldsFromVariants($type, $variants);

            $row = $breakdown[$type->key] ?? [
                'label' => (string) $type->label_fa,
                'samples' => 0,
                'fields' => [],
            ];

            $row['samples']++;
            $comparison = [];

            foreach ($type->fields as $field) {
                $key = (string) $field->key;
                $printed = (string) ($truth[$key] ?? '');

                // قاعدهٔ ۱: فیلدی که روی این قالب چاپ نمی‌شود سنجیده نمی‌شود.
                if ($printed === '') {
                    continue;
                }

                $expected = PersianValue::forEngine((string) $field->value_type, $printed);
                $actual = (string) ($got[$key]['normalized'] ?? '');
                $score = (float) ($got[$key]['confidence'] ?? 0);
                $isCorrect = $actual !== '' && $actual === $expected;

                $cell = $row['fields'][$key] ?? [
                    'label' => (string) $field->label_fa,
                    'count' => 0,
                    'correct' => 0,
                    'confidence' => 0.0,
                ];

                $cell['count']++;
                $cell['confidence'] = round((float) $cell['confidence'] + $score, 2);

                $total++;
                $confidence += $score;

                if ($isCorrect) {
                    $cell['correct']++;
                    $correct++;
                }

                $row['fields'][$key] = $cell;

                $comparison[] = [
                    'label' => (string) $field->label_fa,
                    'expected' => $expected,
                    'got' => $actual,
                    'ok' => $isCorrect,
                ];

                if (! $isCorrect && count($misses) < self::MAX_MISSES) {
                    $misses[] = [
                        'document' => (string) $type->label_fa,
                        'field' => (string) $field->label_fa,
                        'expected' => $expected,
                        'got' => $actual === '' ? '—' : $actual,
                        'confidence' => round($score, 1),
                    ];
                }
            }

            $breakdown[$type->key] = $row;

            if (filled($item['image'] ?? null) && count($previews) < self::MAX_PREVIEWS) {
                $path = $this->previewPath($run, (string) $item['image']);

                if ($path !== null) {
                    $previews[] = [
                        'path' => $path,
                        'document' => (string) $type->label_fa,
                        'fields' => $comparison,
                    ];
                }
            }
        }

        // یک نوشتنِ کاملِ ردیف در پایان هر تکه، نه چند UPDATE جدا: صفحهٔ پیشرفت
        // هرگز حالت نیمه‌جمع‌شده را نمی‌بیند. امنیتش به این بند است که در هر
        // لحظه فقط یک Job روی یک اجرا کار می‌کند — صف redis است و
        // `retry_after` آن (config/queue.php) از timeout این Job بلندتر است،
        // پس تحویل دوبارهٔ جابِ در حال اجرا رخ نمی‌دهد. اگر روزی صف عوض شد،
        // همان‌جا باید این فرض دوباره بررسی شود.
        $run->forceFill([
            'count_done' => (int) $run->count_done + $done,
            'count_failed' => (int) $run->count_failed + $failed,
            'fields_total' => (int) $run->fields_total + $total,
            'fields_correct' => (int) $run->fields_correct + $correct,
            'confidence_sum' => round((float) $run->confidence_sum + $confidence, 2),
            'breakdown' => $breakdown,
            'misses' => $misses,
            'previews' => $previews,
        ])->save();
    }

    // ------------------------------------------------------------------
    // کمکی‌ها
    // ------------------------------------------------------------------

    /**
     * نسخه‌های متن یک تصویر، به همان شکلی که FieldExtractor می‌خواهد.
     *
     * @param  array<string, mixed>  $item
     * @return list<array{raw_text: string, extra: array<string, mixed>}>
     */
    private function variantsOf(array $item): array
    {
        $out = [];

        foreach (is_array($item['variants'] ?? null) ? $item['variants'] : [] as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $extra = is_array($variant['extra'] ?? null) ? $variant['extra'] : [];

            $out[] = [
                'raw_text' => (string) ($variant['raw_text'] ?? ''),
                'extra' => ['vin' => $extra['vin'] ?? null, 'plate' => $extra['plate'] ?? null],
            ];
        }

        // متن خالی هم باید سنجیده شود: «هیچ‌چیز خوانده نشد» یعنی همهٔ فیلدهای
        // این تصویر غلط‌اند، نه اینکه تصویر از مخرج بیرون بیفتد.
        return $out === [] ? [['raw_text' => '', 'extra' => []]] : $out;
    }

    /** اعوجاج‌های این اجرا؛ برای هر تصویر قرعهٔ تازه می‌خورد (داخل موتور نه). */
    private function augmentationsFor(EvaluationRun $run): array
    {
        if ((string) $run->augment_mode !== 'random') {
            return [];
        }

        $out = [];

        foreach (GenerateDatasetSamples::catalogue() as $name => $meta) {
            $value = $this->randomBetween(
                (float) $meta['random'][0],
                (float) $meta['random'][1],
                (int) $meta['decimals'],
            );

            if ($meta['odd'] ?? false) {
                $value = (float) max(3, (int) round($value) | 1);
            }

            $out[$name] = ['enabled' => true, $meta['param'] => $value];

            if ($name === 'noise') {
                $out[$name]['seed'] = random_int(1, 999999);
            }
        }

        return $out;
    }

    private function randomBetween(float $min, float $max, int $decimals): float
    {
        if ($max <= $min) {
            return round($min, $decimals);
        }

        $factor = 10 ** max(0, $decimals);

        return round(random_int((int) round($min * $factor), (int) round($max * $factor)) / $factor, $decimals);
    }

    /** پوشهٔ کار موتور برای این اجرا — روی دیسک خصوصی dataset. */
    private function outDir(EvaluationRun $run): string
    {
        return Storage::disk('dataset')->path('evaluations/'.$run->id);
    }

    /**
     * مسیر نسبی تصویر نگه‌داشته‌شده روی دیسک dataset.
     *
     * نام از موتور می‌آید و مستقیم داخل نشانی `media` می‌نشیند، پس فقط شکل
     * قابل‌انتظار پذیرفته می‌شود. `basename()` تنها نگهبانِ کافی نیست —
     * `basename('..')` خودِ `..` است.
     */
    private function previewPath(EvaluationRun $run, string $filename): ?string
    {
        $name = basename(str_replace('\\', '/', $filename));

        if (preg_match('/^[A-Za-z0-9._-]{1,120}\.png$/', $name) !== 1 || str_contains($name, '..')) {
            Log::warning('evaluation: engine returned an unusable image name', [
                'run_id' => $run->id,
                'name' => Str::limit($filename, 120),
            ]);

            return null;
        }

        return 'evaluations/'.$run->id.'/'.$name;
    }

    private function markDone(EvaluationRun $run): void
    {
        $run->forceFill([
            'status' => 'done',
            'finished_at' => Carbon::now(),
        ])->save();

        $this->sweepWorkDirectory($run);
    }

    private function markFailed(EvaluationRun $run, string $message): void
    {
        $run->forceFill([
            'status' => 'failed',
            'error' => Str::limit($message, 800),
            'finished_at' => Carbon::now(),
        ])->save();

        $this->sweepWorkDirectory($run);
    }

    /**
     * پوشهٔ کار این اجرا را به همان چند تصویری می‌رساند که قرار است بماند.
     *
     * موتور تصویر هر آیتم را همان‌جا پاک می‌کند، ولی اگر استخر پروسه‌ها وسط
     * کار کشته شود (timeout جاب، ری‌استارت کارگر) تا یک تکه تصویر تمام‌اندازه
     * جا می‌ماند و تا اجرای زمان‌بندی‌شدهٔ پاک‌سازی — پیش‌فرض هفت روز — روی
     * دیسک می‌نشیند. پایان اجرا تنها لحظه‌ای است که با اطمینان می‌دانیم هیچ
     * پروسه‌ای دیگر در این پوشه نمی‌نویسد.
     */
    private function sweepWorkDirectory(EvaluationRun $run): void
    {
        $disk = Storage::disk('dataset');
        $directory = 'evaluations/'.$run->id;

        $keep = array_flip(array_filter(array_map(
            static fn ($preview): ?string => is_array($preview) ? ($preview['path'] ?? null) : null,
            is_array($run->previews) ? $run->previews : [],
        )));

        try {
            foreach ($disk->files($directory) as $file) {
                if (! isset($keep[$file])) {
                    $disk->delete($file);
                }
            }
        } catch (Throwable $exception) {
            // پاک‌سازی، جزء اختیاریِ کار است: شکستش نباید نتیجهٔ ارزیابی را
            // که همین حالا ذخیره شده زیر سؤال ببرد. زمان‌بندی روزانه هم
            // همین پوشه را می‌بیند (config/hana.php: prune_roots).
            Log::warning('evaluation: could not sweep the work directory', [
                'run_id' => $run->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
