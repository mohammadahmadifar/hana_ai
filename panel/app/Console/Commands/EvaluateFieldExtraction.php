<?php

namespace App\Console\Commands;

use App\Models\DocumentType;
use App\Services\Cases\FieldExtractor;
use App\Services\HanaEngine;
use App\Support\PersianValue;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Throwable;

/**
 * اندازه‌گیری دقت استخراج فیلد روی دیتاست برچسب‌خوردهٔ موتور.
 *
 * چرا دستور آرتیزان و نه اسکریپت موقت: خروجی OCR مدام در حال بهبود است
 * (تسک‌های پیش‌پردازش و ماژول‌های ویژه)، پس این عدد باید هر بار **دوباره**
 * گرفته شود، نه یک‌بار در گزارش نوشته شود.
 *
 * منبع حقیقت: `dataset/labels/<type>/NNN.json` که خودِ ژنراتور هنگام ساخت
 * تصویر نوشته است — یعنی برچسب صددرصد درست و رایگان.
 * ورودی: `dataset/ocr_results/<type>/NNN.txt` که همان تصویر بعد از OCR است.
 *
 * مقایسه روی **شکل قانونی** انجام می‌شود (PersianValue::forEngine) نه رشتهٔ
 * خام، وگرنه «ايليا» عربی و «ایلیا» فارسی دو مقدار متفاوت شمرده می‌شدند.
 *
 * ### دو ورودی، دو عدد
 * بدون سوییچ، متن از `dataset/ocr_results` خوانده می‌شود؛ همان یک OCR که
 * main.py نوشته. با `--variants` همان تصویر از راهی می‌رود که **پرونده**
 * می‌رود: موتور مدرک را در چند بزرگ‌نمایی می‌خواند (تسک ۶۶۲) و استخراج‌گر
 * برای هر فیلد بهترین نسخه را برمی‌دارد. کندتر است (یک پروسهٔ پایتون به‌ازای
 * هر نمونه) ولی عددش همان چیزی است که کاربر در صفحهٔ نتیجه می‌بیند.
 *
 * ### `--last` بخوانید، نه `--limit`
 * `--limit` از **ابتدای** فهرست برمی‌دارد، یعنی قدیمی‌ترین نمونه‌ها. قالب‌ها
 * و ژنراتور که عوض شوند، آن نمونه‌ها با لیبل‌های زمان خودشان می‌مانند و عدد
 * را بی‌دلیل پایین می‌کشند (همان تلهٔ تسک ۶۶۵). برای اندازه‌گیری واقعی
 * `--last=25` بدهید تا تازه‌ترین‌ها سنجیده شوند.
 */
class EvaluateFieldExtraction extends Command
{
    protected $signature = 'hana:evaluate-extraction
                            {--limit=10 : چند نمونه از هر نوع مدرک بررسی شود (از ابتدای فهرست)}
                            {--last= : به‌جای ابتدا، این تعداد از تازه‌ترین نمونه‌ها}
                            {--type= : فقط یک نوع مدرک (national_card|driving_license|vehicle_card)}
                            {--variants : متن را از مسیر چندمقیاسی موتور بگیر (همان راهی که پرونده می‌رود)}
                            {--miss : نمونه‌های نادرست هم چاپ شوند}';

    protected $description = 'سنجش دقت استخراج فیلد روی دیتاست برچسب‌خورده';

    public function handle(FieldExtractor $extractor): int
    {
        $root = rtrim((string) config('hana.root'), '/');
        $labelRoot = $root.'/dataset/labels';
        $ocrRoot = $root.'/dataset/ocr_results';

        if (! is_dir($labelRoot) || ! is_dir($ocrRoot)) {
            $this->components->error(
                'پوشهٔ دیتاست پیدا نشد. انتظار داشتیم «'.$labelRoot.'» باشد.'
                .' مسیر ریشهٔ موتور را در config/hana.php یا HANA_ENGINE_ROOT درست کنید.'
            );

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $last = max(0, (int) $this->option('last'));
        $only = (string) $this->option('type');
        $types = $only !== '' ? [$only] : $this->datasetTypes($labelRoot);

        if ($this->option('variants')) {
            $this->components->info(
                'مسیر چندمقیاسی موتور — یک پروسهٔ پایتون به‌ازای هر نمونه، پس کند است.'
            );
        }

        $overall = ['count' => 0, 'correct' => 0, 'confidence' => 0.0, 'samples' => 0];

        foreach ($types as $typeKey) {
            $type = DocumentType::query()->with('fields')->where('key', $typeKey)->first();

            if ($type === null) {
                $this->components->warn("نوع مدرک «{$typeKey}» در دیتابیس نیست؛ از قلم افتاد."
                    .' اول ReferenceDataSeeder را اجرا کنید.');

                continue;
            }

            $report = $this->evaluateType($extractor, $type, $labelRoot, $ocrRoot, $limit, $last);

            if ($report['samples'] === 0) {
                $this->components->warn("برای «{$typeKey}» هیچ نمونه‌ای با متن OCR پیدا نشد.");

                continue;
            }

            $this->renderTable($type, $report);

            if ($report['skipped'] !== []) {
                $this->components->warn(
                    count($report['skipped']).' نمونهٔ «'.$type->label_fa.'» متن نداشت و سنجیده نشد: '
                    .implode('، ', array_slice($report['skipped'], 0, 8))
                    .(count($report['skipped']) > 8 ? ' …' : '')
                );
            }

            $overall['count'] += $report['totals']['count'];
            $overall['correct'] += $report['totals']['correct'];
            $overall['confidence'] += $report['totals']['confidence'];
            $overall['samples'] += $report['samples'];

            if ($this->option('miss')) {
                $this->renderMisses($report['misses']);
            }
        }

        if ($overall['count'] === 0) {
            $this->components->error('هیچ فیلدی سنجیده نشد.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail(
            '<options=bold>جمع کل — نمونه‌ها</>',
            PersianValue::toPersianDigits((string) $overall['samples']),
        );
        $this->components->twoColumnDetail(
            '<options=bold>جمع کل — فیلدهای درست</>',
            PersianValue::toPersianDigits($overall['correct'].' از '.$overall['count'])
            .' ('.PersianValue::decimal(100 * $overall['correct'] / $overall['count'], 1).'٪)',
        );
        $this->components->twoColumnDetail(
            '<options=bold>میانگین اطمینان</>',
            PersianValue::decimal($overall['confidence'] / $overall['count'], 1),
        );
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     samples: int,
     *     rows: array<string, array{label: string, count: int, correct: int, confidence: float}>,
     *     totals: array{count: int, correct: int, confidence: float},
     *     misses: list<array{sample: string, field: string, expected: string, got: string, confidence: float}>,
     *     skipped: list<string>
     * }
     */
    private function evaluateType(
        FieldExtractor $extractor,
        DocumentType $type,
        string $labelRoot,
        string $ocrRoot,
        int $limit,
        int $last = 0,
    ): array {
        $files = glob($labelRoot.'/'.$type->key.'/*.json') ?: [];
        sort($files);

        // `--last` تازه‌ترین نمونه‌ها را برمی‌دارد؛ قدیمی‌ترها ممکن است با
        // قالب و ژنراتورِ نسخه‌های قبل ساخته شده باشند (تسک ۶۶۵).
        if ($last > 0) {
            $files = array_slice($files, -min($last, count($files)));
            $limit = max($limit, count($files));
        }

        $rows = [];
        $misses = [];
        $skipped = [];
        $samples = 0;

        foreach ($type->fields as $field) {
            $rows[(string) $field->key] = [
                'label' => (string) $field->label_fa,
                'type' => (string) $field->value_type,
                'count' => 0,
                'correct' => 0,
                'confidence' => 0.0,
            ];
        }

        foreach ($files as $labelFile) {
            if ($samples >= $limit) {
                break;
            }

            $name = pathinfo($labelFile, PATHINFO_FILENAME);

            $truth = json_decode((string) file_get_contents($labelFile), true);

            // لیبل اول اعتبارسنجی می‌شود: با --variants هر نمونه یک پروسهٔ
            // کامل پایتون است و خرج‌کردنش برای نمونه‌ای که لیبل خراب دارد
            // چند ثانیه وقت دورریز است.
            if (! is_array($truth)) {
                $skipped[] = $name.' (لیبل خوانده نشد)';

                continue;
            }

            $variants = $this->option('variants')
                ? $this->variantsFromEngine($type, $name)
                : $this->variantFromOcrResults($ocrRoot, $type, $name);

            // نمونه‌ای که متن ندارد از مخرج بیرون می‌ماند، پس باید **شمرده و
            // گزارش** شود: در سکوت رد کردنش درصد را الکی بالا می‌برد.
            if ($variants === []) {
                $skipped[] = $name;

                continue;
            }

            $samples++;

            $got = $extractor->fieldsFromVariants($type, $variants);

            foreach ($type->fields as $field) {
                $key = (string) $field->key;

                if (! array_key_exists($key, $truth) || (string) $truth[$key] === '') {
                    continue; // ژنراتور این فیلد را چاپ نکرده؛ سنجیدنش بی‌معناست
                }

                $expected = PersianValue::forEngine((string) $field->value_type, (string) $truth[$key]);
                $actual = (string) ($got[$key]['normalized'] ?? '');
                $confidence = (float) ($got[$key]['confidence'] ?? 0);

                $rows[$key]['count']++;
                $rows[$key]['confidence'] += $confidence;

                if ($actual !== '' && $actual === $expected) {
                    $rows[$key]['correct']++;

                    continue;
                }

                $misses[] = [
                    'sample' => $type->key.'/'.$name,
                    'field' => $rows[$key]['label'],
                    'expected' => $expected,
                    'got' => $actual === '' ? '—' : $actual,
                    'confidence' => $confidence,
                ];
            }
        }

        $totals = ['count' => 0, 'correct' => 0, 'confidence' => 0.0];

        foreach ($rows as $row) {
            $totals['count'] += $row['count'];
            $totals['correct'] += $row['correct'];
            $totals['confidence'] += $row['confidence'];
        }

        return [
            'samples' => $samples,
            'rows' => $rows,
            'totals' => $totals,
            'misses' => $misses,
            'skipped' => $skipped,
        ];
    }

    /**
     * متن تک‌نسخه‌ای از `dataset/ocr_results` — همان چیزی که main.py نوشته.
     *
     * @return list<array{raw_text: string, extra: array<string, mixed>}>
     */
    private function variantFromOcrResults(string $ocrRoot, DocumentType $type, string $name): array
    {
        $ocrFile = $ocrRoot.'/'.$type->key.'/'.$name.'.txt';

        if (! is_file($ocrFile)) {
            return [];
        }

        return [['raw_text' => (string) file_get_contents($ocrFile), 'extra' => []]];
    }

    /**
     * نسخه‌های چندمقیاسی، مستقیم از موتور — همان مسیری که پرونده می‌رود.
     *
     * ورودی `dataset/processed` است نه `dataset/preprocessed`: موتور خودش
     * پیش‌پردازش می‌کند و دادنِ تصویرِ از پیش پیش‌پردازش‌شده یعنی دو بار
     * اجرای همان مراحل روی هم.
     *
     * @return list<array{raw_text: string, extra: array<string, mixed>}>
     */
    private function variantsFromEngine(DocumentType $type, string $name): array
    {
        $root = rtrim((string) config('hana.root'), '/');
        $sources = glob($root.'/dataset/processed/'.$type->key.'/'.$name.'_*.png') ?: [];

        if ($sources === []) {
            return [];
        }

        sort($sources);

        try {
            $result = app(HanaEngine::class)->ocrDocument(
                $sources[0],
                (string) $type->key,
                // نیمهٔ موتور، نه storage پنل: این دستور را root اجرا می‌کند و
                // پوشهٔ root در مسیر مشترکِ www-data بعداً آپلود را می‌شکند.
                outDir: $root.'/dataset/benchmark',
            );
        } catch (Throwable $exception) {
            $this->components->warn(
                "نمونهٔ {$type->key}/{$name} از موتور نتیجه نگرفت: ".$exception->getMessage()
            );

            return [];
        }

        $variants = is_array($result['variants'] ?? null) ? $result['variants'] : [];

        $out = [];

        foreach ($variants as $variant) {
            $extra = is_array($variant['extra'] ?? null) ? $variant['extra'] : [];

            $out[] = [
                'raw_text' => (string) ($variant['raw_text'] ?? ''),
                'extra' => ['vin' => $extra['vin'] ?? null, 'plate' => $extra['plate'] ?? null],
            ];
        }

        return $out === []
            ? [['raw_text' => (string) ($result['raw_text'] ?? ''), 'extra' => $result['extra'] ?? []]]
            : $out;
    }

    /** @param  array<string, mixed>  $report */
    private function renderTable(DocumentType $type, array $report): void
    {
        $this->newLine();
        $this->components->info(
            'نوع مدرک: '.$type->label_fa
            .' — '.PersianValue::toPersianDigits((string) $report['samples']).' نمونه'
        );

        $body = [];

        foreach ($report['rows'] as $row) {
            if ($row['count'] === 0) {
                continue;
            }

            $body[] = [
                $row['label'],
                PersianValue::toPersianDigits((string) $row['count']),
                PersianValue::toPersianDigits((string) $row['correct']),
                PersianValue::decimal(100 * $row['correct'] / $row['count'], 1).'٪',
                PersianValue::decimal($row['confidence'] / $row['count'], 1),
            ];
        }

        $totals = $report['totals'];

        if ($totals['count'] > 0) {
            $body[] = new TableSeparator;
            $body[] = [
                'همه فیلدها',
                PersianValue::toPersianDigits((string) $totals['count']),
                PersianValue::toPersianDigits((string) $totals['correct']),
                PersianValue::decimal(100 * $totals['correct'] / $totals['count'], 1).'٪',
                PersianValue::decimal($totals['confidence'] / $totals['count'], 1),
            ];
        }

        $this->table(['فیلد', 'تعداد', 'درست', 'درصد', 'میانگین اطمینان'], $body);
    }

    /** @param  list<array{sample: string, field: string, expected: string, got: string, confidence: float}>  $misses */
    private function renderMisses(array $misses): void
    {
        if ($misses === []) {
            return;
        }

        $this->table(
            ['نمونه', 'فیلد', 'درست', 'خوانده‌شده', 'اطمینان'],
            array_map(static fn (array $m): array => [
                $m['sample'],
                $m['field'],
                $m['expected'],
                $m['got'],
                PersianValue::decimal($m['confidence'], 1),
            ], $misses),
        );
    }

    /** @return list<string> */
    private function datasetTypes(string $labelRoot): array
    {
        $types = [];

        foreach (glob($labelRoot.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $types[] = basename($dir);
        }

        sort($types);

        return $types;
    }
}
