<?php

namespace App\Jobs;

use App\Exceptions\EngineException;
use App\Models\DatasetAnnotation;
use App\Models\DatasetSample;
use App\Models\DocumentType;
use App\Models\GenerationBatch;
use App\Services\HanaEngine;
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
 * تولید انبوه نمونهٔ دیتاست با برچسب کامل.
 *
 * ارزان‌ترین دادهٔ آموزشی سامانه همین‌جا ساخته می‌شود: موتور متن هر فیلد را
 * روی قالب می‌نویسد و کادر نسبی همان فیلد را برمی‌گرداند، پس برچسب‌گذاری
 * «رایگان» و بدون دخالت انسان انجام می‌شود (قانون پروژه ۱۳۰).
 *
 * قرارداد پیشرفت: بعد از هر «نمونه» (یعنی هر شخص) شمارندهٔ count_done یا
 * count_failed یک واحد بالا می‌رود تا صفحهٔ پیشرفت زنده باشد. خطای یک نمونه
 * هرگز کل دسته را نمی‌کشد.
 *
 * سنجه اما «تصویر» است نه «نمونه»: نمونه فقط وقتی انجام‌شده حساب می‌شود که
 * همهٔ مدرک‌های خواسته‌شده‌اش ساخته شده باشند، و شمار تصویرهای ساخته‌نشده به
 * همراه خطای هر نوع مدرک در ستون error دسته نوشته می‌شود تا در صفحهٔ پیشرفت
 * دیده شود. وگرنه شکستِ همیشگی یک نوع مدرک پشت «۱۰۰٪ موفق» پنهان می‌ماند.
 */
class GenerateDatasetSamples implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** حداکثر تلاش. تلاش دوم از همان‌جایی که کار متوقف شده ادامه می‌دهد. */
    public int $tries = 2;

    /** سقف زمان یک اجرا (ثانیه). */
    public int $timeout = 900;

    /**
     * اگر کار از این مرز زمانی رد شد، بقیهٔ دسته به یک Job تازه سپرده می‌شود
     * تا هیچ‌وقت به timeout نخوریم. (رندر هر تصویر حدود نیم‌ثانیه است.)
     */
    private const SOFT_DEADLINE_SECONDS = 660;

    public function __construct(public int $batchId)
    {
        $this->onQueue('generate');
    }

    // ------------------------------------------------------------------
    // کاتالوگ اعوجاج‌ها — تنها منبع حقیقت؛ فرم تولید هم از همین می‌خواند.
    // ------------------------------------------------------------------

    /**
     * مشخصات هر اعوجاج: نام پارامتری که موتور می‌فهمد، بازهٔ مجاز، مقدار
     * پیش‌فرض و بازه‌ای که در حالت «تصادفی» از آن قرعه کشیده می‌شود.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function catalogue(): array
    {
        return [
            'rotation' => [
                'label' => 'چرخش',
                'icon' => '🔄',
                'hint' => 'کج‌بودن مدرک هنگام اسکن یا عکس‌برداری با موبایل.',
                'param' => 'angle',
                'param_label' => 'زاویه',
                'unit' => 'درجه',
                'min' => -15.0,
                'max' => 15.0,
                'step' => 0.5,
                'default' => 7.0,
                'random' => [-12.0, 12.0],
                'decimals' => 1,
            ],
            'brightness' => [
                'label' => 'روشنایی',
                'icon' => '💡',
                'hint' => 'نور کم اتاق یا فلاش بیش از حد. مقدار منفی یعنی تاریک‌تر.',
                'param' => 'value',
                'param_label' => 'تغییر روشنایی',
                'unit' => 'واحد',
                'min' => -90.0,
                'max' => 90.0,
                'step' => 5.0,
                'default' => -25.0,
                'random' => [-70.0, 70.0],
                'decimals' => 0,
            ],
            'blur' => [
                'label' => 'تاری',
                'icon' => '🌫',
                'hint' => 'لرزش دست یا نبود فوکوس. عدد بزرگ‌تر یعنی تارتر (فقط فرد).',
                'param' => 'kernel',
                'param_label' => 'شدت تاری',
                'unit' => 'پیکسل',
                'min' => 3.0,
                'max' => 15.0,
                'step' => 2.0,
                'default' => 5.0,
                'random' => [3.0, 11.0],
                'decimals' => 0,
                'odd' => true,
            ],
            'noise' => [
                'label' => 'نویز',
                'icon' => '📻',
                'hint' => 'دانه‌دانه‌شدن تصویر دوربین‌های ارزان در نور کم.',
                'param' => 'std',
                'param_label' => 'شدت نویز',
                'unit' => 'انحراف معیار',
                'min' => 1.0,
                'max' => 40.0,
                'step' => 1.0,
                'default' => 8.0,
                'random' => [3.0, 22.0],
                'decimals' => 0,
            ],
            'shadow' => [
                'label' => 'سایه',
                'icon' => '🌑',
                'hint' => 'سایهٔ دست یا گوشی روی مدرک. عدد کوچک‌تر یعنی سایهٔ تیره‌تر.',
                'param' => 'alpha',
                'param_label' => 'شفافیت سایه',
                'unit' => '',
                'min' => 0.2,
                'max' => 0.95,
                'step' => 0.05,
                'default' => 0.75,
                'random' => [0.4, 0.9],
                'decimals' => 2,
            ],
        ];
    }

    /** برچسب فارسی وضعیت یک دسته. */
    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            'queued' => 'در صف',
            'running' => 'در حال تولید',
            'done' => 'پایان‌یافته',
            'failed' => 'ناموفق',
            default => 'نامشخص',
        };
    }

    /** رنگ نشان وضعیت (متناسب با badge/bar در app.css). */
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

    /** گزینه‌های split مقصد. */
    public static function splitModes(): array
    {
        return [
            'auto' => 'تقسیم خودکار ۸۰ / ۱۰ / ۱۰',
            'train' => 'همه در train (آموزش)',
            'val' => 'همه در val (اعتبارسنجی)',
            'test' => 'همه در test (آزمون)',
        ];
    }

    // ------------------------------------------------------------------
    // اجرا
    // ------------------------------------------------------------------

    public function handle(HanaEngine $engine): void
    {
        $batch = GenerationBatch::find($this->batchId);

        if ($batch === null) {
            Log::warning('generate-dataset: batch not found', ['batch_id' => $this->batchId]);

            return;
        }

        // دسته‌ای که قبلاً تمام شده دوباره اجرا نمی‌شود (مثلاً تلاش دوم بعد از موفقیت).
        if (in_array($batch->status, ['done', 'failed'], true)) {
            return;
        }

        $types = DocumentType::query()
            ->whereIn('id', (array) $batch->document_type_ids)
            ->where('is_generatable', true)
            ->where('is_active', true)
            ->orderBy('sort')
            ->get();

        if ($types->isEmpty()) {
            $this->markFailed($batch, 'هیچ نوع مدرک قابل تولیدی برای این دسته باقی نمانده است.');

            return;
        }

        $batch->status = 'running';
        $batch->save();

        // چند نمونه از قبل پردازش شده؟ (تلاش دوم یا ادامهٔ دستهٔ بزرگ)
        $processed = (int) $batch->count_done + (int) $batch->count_failed;
        $remaining = max(0, (int) $batch->count_requested - $processed);

        if ($remaining === 0) {
            $this->markDone($batch);

            return;
        }

        // همهٔ افراد در «یک» فراخوانی موتور ساخته می‌شوند؛ هر فراخوانی یک
        // پروسهٔ پایتون است و این کار صدها اجرای اضافی را حذف می‌کند.
        try {
            $generated = $engine->generatePerson($remaining);
        } catch (EngineException $exception) {
            Log::error('generate-dataset: person generation failed', [
                'batch_id' => $batch->id,
            ] + $exception->context());

            $this->markFailed($batch, $exception->userMessage());

            return;
        }

        $people = $generated['people'] ?? [];

        if (! is_array($people) || $people === []) {
            $this->markFailed($batch, 'موتور هیچ دادهٔ شخصی برنگرداند.');

            return;
        }

        $spec = is_array($batch->augmentations) ? $batch->augmentations : [];
        $tagIds = $this->tagIdsOf($spec);
        $subDirectory = Carbon::now()->format('Y-m');
        $absoluteDirectory = Storage::disk('dataset')->path($subDirectory);
        $startedAt = microtime(true);
        $typeCount = $types->count();

        // شمار تصویرهای ساخته‌شده/ناموفق + خطای هر نوع مدرک؛ همین‌ها به صفحهٔ پیشرفت می‌روند.
        /** @var array<string, array{label: string, count: int, message: string}> $typeFailures */
        $typeFailures = [];
        $imagesFailed = 0;

        foreach (array_values($people) as $offset => $person) {
            if (! is_array($person)) {
                continue;
            }

            $index = $processed + $offset;

            if ($index >= (int) $batch->count_requested) {
                break;
            }

            $split = $this->splitFor($batch, $index);
            $succeeded = 0;

            foreach ($types as $type) {
                try {
                    $this->renderOne($engine, $batch, $type, $person, $spec, $split, $index, $subDirectory, $absoluteDirectory, $tagIds);
                    $succeeded++;
                } catch (Throwable $exception) {
                    $imagesFailed++;
                    $this->recordTypeFailure($typeFailures, $type, $exception);

                    Log::warning('generate-dataset: one document failed', [
                        'batch_id' => $batch->id,
                        'sample_index' => $index,
                        'document_type' => $type->key,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            // «نمونه» یعنی یک شخص، ولی فقط وقتی انجام‌شده است که هیچ‌کدام از
            // مدرک‌هایش جا نمانده باشد؛ وگرنه یک نوع مدرکِ خراب دیده نمی‌شود.
            $batch->increment($succeeded === $typeCount ? 'count_done' : 'count_failed');
            $batch->refresh();

            $this->refreshFailureNote($batch, $typeCount, $typeFailures);

            // مرز زمانی نرم: بقیهٔ کار به یک Job تازه سپرده می‌شود تا به timeout نخوریم.
            $isLast = ($index + 1) >= (int) $batch->count_requested;

            if (! $isLast && (microtime(true) - $startedAt) > self::SOFT_DEADLINE_SECONDS) {
                Log::info('generate-dataset: handing the rest to a fresh job', [
                    'batch_id' => $batch->id,
                    'done' => $batch->count_done,
                    'failed' => $batch->count_failed,
                ]);

                self::dispatch($batch->id);

                return;
            }
        }

        $this->refreshFailureNote($batch, $typeCount, $typeFailures);

        // اگر در کل دسته (نه فقط این اجرا) هیچ تصویری ساخته نشد، «پایان‌یافته» نیست؛ ناموفق است.
        if ($imagesFailed > 0 && DatasetSample::query()->where('notes', 'batch:'.$batch->id)->count() === 0) {
            $this->markFailed($batch, 'هیچ تصویری ساخته نشد. '.(string) $batch->error);

            return;
        }

        $this->markDone($batch);
    }

    /**
     * وقتی همهٔ تلاش‌ها شکست خورد (یا timeout شد) وضعیت دسته را قطعی می‌کنیم.
     */
    public function failed(?Throwable $exception): void
    {
        $batch = GenerationBatch::find($this->batchId);

        if ($batch === null || in_array($batch->status, ['done', 'failed'], true)) {
            return;
        }

        $message = $exception instanceof EngineException
            ? $exception->userMessage()
            : 'تولید دسته با خطای پیش‌بینی‌نشده متوقف شد.';

        $detail = $exception?->getMessage();

        if (filled($detail)) {
            $message .= ' — '.Str::limit($detail, 300);
        }

        $this->markFailed($batch, $message);
    }

    // ------------------------------------------------------------------
    // ساخت یک تصویر + برچسب‌هایش
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $person
     * @param  array<string, mixed>  $spec
     * @param  array<int, int>  $tagIds
     */
    private function renderOne(
        HanaEngine $engine,
        GenerationBatch $batch,
        DocumentType $type,
        array $person,
        array $spec,
        string $split,
        int $index,
        string $subDirectory,
        string $absoluteDirectory,
        array $tagIds,
    ): void {
        // برای هر تصویر یک قرعهٔ تازه؛ وگرنه همهٔ نمونه‌ها یک شکل می‌شوند.
        $augmentations = $this->resolveAugmentations($spec);

        $basename = 'b'.$batch->id.'_s'.($index + 1).'_'.$type->key.'_'.Str::lower(Str::random(6));

        $result = $engine->renderDocument(
            $type->key,
            $person,
            $augmentations,
            $absoluteDirectory,
            $basename,
        );

        // نام واقعی فایل را خود موتور برمی‌گرداند (ممکن است پاک‌سازی شده باشد).
        $name = (string) ($result['basename'] ?? $basename);

        $cleanRelative = $subDirectory.'/'.$name.'.png';
        $augmentedRelative = filled($result['augmented_path'] ?? null)
            ? $subDirectory.'/'.$name.'_aug.png'
            : null;

        if (! Storage::disk('dataset')->exists($cleanRelative)) {
            throw new \RuntimeException('فایل تصویر ساخته‌شده روی دیسک پیدا نشد: '.$cleanRelative);
        }

        if ($augmentedRelative !== null && ! Storage::disk('dataset')->exists($augmentedRelative)) {
            $augmentedRelative = null;
        }

        $applied = is_array($result['applied'] ?? null) ? $result['applied'] : [];
        $appliedNames = array_values(array_filter(array_map(
            static fn ($item) => is_array($item) ? ($item['name'] ?? null) : null,
            $applied,
        )));

        $sample = new DatasetSample();
        $sample->document_type_id = $type->id;
        $sample->created_by = $batch->user_id;
        $sample->source = 'generated';
        $sample->disk = 'dataset';
        // تصویر «اصلی» نمونه همان چیزی است که مدل باید رویش آموزش ببیند.
        $sample->path = $augmentedRelative ?? $cleanRelative;
        $sample->clean_path = $cleanRelative;
        $sample->original_name = $name.'.png';
        $sample->width = (int) ($result['width'] ?? 0) ?: null;
        $sample->height = (int) ($result['height'] ?? 0) ?: null;
        $sample->augmentation = match (count($appliedNames)) {
            0 => 'none',
            1 => (string) $appliedNames[0],
            default => 'mixed',
        };
        $sample->augmentation_params = [
            'applied' => $applied,
            'requested' => $augmentations,
            'batch_id' => $batch->id,
        ];
        $sample->generation_payload = $person + ['_batch_id' => $batch->id];
        $sample->split = $split;
        $sample->is_verified = false;
        // کلید پیوند نمونه به دسته — صفحهٔ پیشرفت و فیلتر فهرست از همین می‌خوانند.
        $sample->notes = 'batch:'.$batch->id;
        $sample->save();

        $this->storeAnnotations($sample, $result, $augmentedRelative !== null, $batch->user_id);

        if ($tagIds !== []) {
            $sample->tags()->syncWithoutDetaching($tagIds);
        }
    }

    /**
     * برچسب هر فیلد: متنی که واقعاً چاپ شده + کادر نسبی همان فیلد روی
     * تصویری که در ستون path ذخیره شده است.
     *
     * @param  array<string, mixed>  $result
     */
    private function storeAnnotations(DatasetSample $sample, array $result, bool $useAugmentedBoxes, ?int $userId): void
    {
        $fields = is_array($result['fields'] ?? null) ? $result['fields'] : [];
        $augmentedFields = is_array($result['fields_augmented'] ?? null) ? $result['fields_augmented'] : [];

        $rows = [];
        $now = Carbon::now();

        foreach ($fields as $fieldKey => $info) {
            if (! is_array($info)) {
                continue;
            }

            $box = $useAugmentedBoxes && is_array($augmentedFields[$fieldKey]['norm'] ?? null)
                ? $augmentedFields[$fieldKey]['norm']
                : ($info['norm'] ?? null);

            $rows[] = [
                'dataset_sample_id' => $sample->id,
                'field_key' => Str::limit((string) $fieldKey, 40, ''),
                'value' => (string) ($info['text'] ?? ''),
                'bbox_x' => is_array($box) ? (float) ($box['x'] ?? 0) : null,
                'bbox_y' => is_array($box) ? (float) ($box['y'] ?? 0) : null,
                'bbox_w' => is_array($box) ? (float) ($box['w'] ?? 0) : null,
                'bbox_h' => is_array($box) ? (float) ($box['h'] ?? 0) : null,
                'source' => 'generated',
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DatasetAnnotation::insert($rows);
        }
    }

    // ------------------------------------------------------------------
    // کمکی‌ها
    // ------------------------------------------------------------------

    /**
     * تبدیل «خواستهٔ کاربر» به پارامترهای دقیقی که موتور می‌فهمد.
     * حالت «تصادفی» برای هر تصویر قرعهٔ تازه می‌زند.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, array<string, mixed>>
     */
    private function resolveAugmentations(array $spec): array
    {
        $catalogue = self::catalogue();
        $resolved = [];

        foreach ($catalogue as $name => $meta) {
            $wanted = $spec[$name] ?? null;

            if (! is_array($wanted) || ! ($wanted['enabled'] ?? false)) {
                continue;
            }

            $isRandom = ($wanted['mode'] ?? 'random') === 'random';

            $value = $isRandom
                ? $this->randomBetween((float) $meta['random'][0], (float) $meta['random'][1], (int) $meta['decimals'])
                : (float) ($wanted['value'] ?? $meta['default']);

            $value = max((float) $meta['min'], min((float) $meta['max'], $value));

            if ($meta['odd'] ?? false) {
                $value = (float) max(3, (int) round($value) | 1);
            }

            $params = ['enabled' => true, $meta['param'] => $value];

            if ($name === 'noise') {
                // seed را ذخیره می‌کنیم تا همان تصویر دقیقاً بازتولیدپذیر بماند.
                $params['seed'] = random_int(1, 999999);
            }

            if ($name === 'shadow' && $isRandom) {
                $params['x_ratio'] = $this->randomBetween(0.1, 0.9, 2);
                $params['y_ratio'] = $this->randomBetween(0.1, 0.9, 2);
                $params['size_x'] = $this->randomBetween(0.1, 0.3, 2);
                $params['size_y'] = $this->randomBetween(0.1, 0.3, 2);
                $params['angle'] = $this->randomBetween(0, 180, 0);
            }

            $resolved[$name] = $params;
        }

        return $resolved;
    }

    private function randomBetween(float $min, float $max, int $decimals): float
    {
        if ($max <= $min) {
            return round($min, $decimals);
        }

        $factor = 10 ** max(0, $decimals);

        return round(random_int((int) round($min * $factor), (int) round($max * $factor)) / $factor, $decimals);
    }

    /**
     * split هر نمونه. در حالت خودکار الگوی ثابت ۸ تا train، یکی val، یکی test
     * تکرار می‌شود؛ چون همهٔ مدرک‌های یک شخص در یک split می‌مانند، نشت داده
     * بین آموزش و آزمون رخ نمی‌دهد.
     */
    private function splitFor(GenerationBatch $batch, int $index): string
    {
        $spec = is_array($batch->augmentations) ? $batch->augmentations : [];
        $mode = (string) ($spec['_split_mode'] ?? 'auto');

        if (in_array($mode, ['train', 'val', 'test'], true)) {
            return $mode;
        }

        return match ($index % 10) {
            8 => 'val',
            9 => 'test',
            default => 'train',
        };
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<int, int>
     */
    private function tagIdsOf(array $spec): array
    {
        $ids = $spec['_tag_ids'] ?? [];

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * خطای یک نوع مدرک را جمع می‌کند: شمار تصویرهای ناموفق + نخستین پیام.
     *
     * @param  array<string, array{label: string, count: int, message: string}>  $typeFailures
     */
    private function recordTypeFailure(array &$typeFailures, DocumentType $type, Throwable $exception): void
    {
        $key = (string) $type->key;

        if (! isset($typeFailures[$key])) {
            $raw = $exception instanceof EngineException
                ? $exception->userMessage()
                : $exception->getMessage();

            $raw = trim((string) $raw);

            $typeFailures[$key] = [
                'label' => (string) ($type->label_fa ?: $key),
                'count' => 0,
                'message' => Str::limit($raw !== '' ? $raw : 'خطای نامشخص', 160),
            ];
        }

        $typeFailures[$key]['count']++;
    }

    /**
     * خلاصهٔ تصویرهای ساخته‌نشده را روی ستون error می‌نویسد تا صفحهٔ پیشرفت
     * همان لحظه نشانش دهد. شمارش در سطح «تصویر» است: انتظار = نمونهٔ
     * پردازش‌شده × نوع مدرک، واقعیت = ردیف‌های ثبت‌شدهٔ همین دسته در دیتاست.
     *
     * @param  array<string, array{label: string, count: int, message: string}>  $typeFailures
     */
    private function refreshFailureNote(GenerationBatch $batch, int $typeCount, array $typeFailures): void
    {
        // اجرای بی‌خطا نه پرس‌وجوی اضافه می‌زند و نه یادداشت اجرای قبلی را پاک می‌کند.
        if ($typeFailures === []) {
            return;
        }

        $processed = (int) $batch->count_done + (int) $batch->count_failed;
        $expected = $processed * max(1, $typeCount);
        $stored = DatasetSample::query()->where('notes', 'batch:'.$batch->id)->count();
        $missing = max(0, $expected - $stored);

        $lines = ['از '.$this->fa($expected).' تصویر پردازش‌شده، '.$this->fa($missing).' تصویر ساخته نشد.'];

        foreach ($typeFailures as $row) {
            $lines[] = '«'.$row['label'].'»: '.$this->fa($row['count']).' تصویر ناموفق — '.$row['message'];
        }

        $note = Str::limit(implode(' ', $lines), 800);

        if ($note === (string) $batch->error) {
            return;
        }

        $batch->error = $note;
        $batch->save();
    }

    /** رقم فارسی برای پیامی که کاربر می‌بیند. */
    private function fa(int|float|string $value): string
    {
        return strtr((string) $value, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }

    private function markDone(GenerationBatch $batch): void
    {
        $batch->status = 'done';
        $batch->finished_at = Carbon::now();
        $batch->save();
    }

    private function markFailed(GenerationBatch $batch, string $message): void
    {
        $batch->status = 'failed';
        $batch->error = Str::limit($message, 800);
        $batch->finished_at = Carbon::now();
        $batch->save();
    }
}
