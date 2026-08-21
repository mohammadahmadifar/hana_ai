<?php

namespace App\Support;

use App\Models\DatasetSample;
use App\Models\DatasetTag;
use App\Models\DocumentType;
use App\Models\DocumentTypeField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * ساخت بستهٔ خروجی دیتاست برای آموزش مدل.
 *
 * این کلاس تنها جای منطق خروجی است؛ کنترلر فقط فیلترها را می‌گیرد و Job فقط
 * build() را صدا می‌زند. هیچ جدول جدیدی در کار نیست: وضعیت هر خروجی کنار
 * خود فایل zip، در یک فایل هم‌نام .json روی دیسک «exports» نگه‌داری می‌شود.
 *
 * قالب‌های خروجی:
 *   json      → یک فایل dataset.json با ساختار {meta, samples[]} (+ پوشهٔ images)
 *   tesseract → برای هر فیلدِ دارای کادر، تصویرِ بریده‌شدهٔ همان کادر (یک خط)
 *               به‌همراه یک .gt.txt تک‌خطی و یک .box با مختصات داخل همان برش
 */
class DatasetExporter
{
    /** دیسک خصوصی خروجی‌ها و پوشهٔ اختصاصی خروجی دیتاست. */
    public const DISK = 'exports';

    public const DIR = 'dataset';

    /** الگوی شناسهٔ خروجی — نام فایل هرگز از ورودی کاربر ساخته نمی‌شود. */
    public const TOKEN_REGEX = '/^export-\d{8}-\d{6}-[a-z0-9]{6}$/';

    public const TOKEN_ROUTE_PATTERN = 'export\-[0-9]{8}\-[0-9]{6}\-[a-z0-9]{6}';

    public const FORMATS = [
        'json' => 'JSON یکپارچه',
        'tesseract' => 'Tesseract — خط‌های بریده‌شده',
    ];

    public const FORMAT_HINTS = [
        'json' => 'یک فایل dataset.json با متادیتا، مقدار فیلدها و کادرها (نسبی ۰ تا ۱) به‌همراه تصویرها.',
        'tesseract' => 'برای هر فیلدِ دارای کادر، تصویر همان کادر بریده می‌شود و کنارش یک .gt.txt تک‌خطی و یک .box می‌آید — ورودی مستقیم tesstrain. فیلدهای بدون کادر و نمونه‌های بدون تصویر در این قالب خروجی ندارند.',
    ];

    public const STATUS_LABELS = [
        'queued' => 'در صف',
        'running' => 'در حال ساخت',
        'done' => 'آماده',
        'failed' => 'ناموفق',
    ];

    public const STATUS_TONES = [
        'queued' => 'info',
        'running' => 'warn',
        'done' => 'ok',
        'failed' => 'bad',
    ];

    public const SOURCES = [
        'generated' => 'ساختهٔ موتور',
        'uploaded' => 'بارگذاری‌شده',
    ];

    public const SPLITS = [
        'train' => 'آموزش (train)',
        'val' => 'اعتبارسنجی (val)',
        'test' => 'آزمون (test)',
    ];

    /** سقف نمونه در هر بسته — نگهبان مصرف حافظه و دیسک. */
    public const MAX_SAMPLES = 5000;

    /* ------------------------------------------------------------------
     | سهمیهٔ دیسک — اعداد ثابت‌اند و همین‌ها در رابط کاربری نوشته می‌شوند.
     * ------------------------------------------------------------------ */

    /** سقف درخواست ساخت بسته برای هر کاربر در هر ساعت. */
    public const RATE_LIMIT_PER_HOUR = 3;

    /** پنجرهٔ محدودیت نرخ به ثانیه. */
    public const RATE_LIMIT_WINDOW = 3600;

    /** بیشترین تعداد بسته‌ای که روی دیسک نگه داشته می‌شود. */
    public const KEEP_LAST = 20;

    /** بیشترین عمر یک بسته به روز. */
    public const KEEP_DAYS = 7;

    /**
     * سقف مجموع حجم بسته‌ها روی دیسک (۲ گیگابایت). یک بستهٔ JSON با تصویر
     * می‌تواند بیش از صد مگابایت باشد، پس شمردن تعداد به‌تنهایی دیسک را
     * محدود نمی‌کند.
     */
    public const KEEP_BYTES = 2147483648;

    /**
     * بعد از این تعداد دقیقه، بستهٔ «در صف/در حال ساخت» مرده حساب می‌شود.
     * سقف واقعی کار = timeout جاب (۹۰۰ ثانیه) + retry_after صف (۱۲۰۰ ثانیه)
     * یعنی ۳۵ دقیقه؛ ۴۵ دقیقه با حاشیهٔ امن. بدون این، کشته‌شدن کارگر کاربر
     * را برای همیشه پشت قانون «هم‌زمان یک بسته» زندانی می‌کرد.
     */
    public const PENDING_STALE_MINUTES = 45;

    /** حاشیهٔ اطراف هر خط بریده‌شده، نسبت به ارتفاع همان فیلد. */
    public const LINE_PADDING_RATIO = 0.12;

    /* ==================================================================
     | فیلترها و گزینه‌ها
     * ================================================================== */

    public static function defaultFilters(): array
    {
        return [
            'document_type_ids' => [],
            'sources' => [],
            'splits' => [],
            'tag_ids' => [],
            'verified_only' => false,
            'with_boxes_only' => false,
        ];
    }

    /** ورودی خام (فرم یا فایل متا) را به شکل قابل‌اعتماد در می‌آورد. */
    public static function normalizeFilters(array $input): array
    {
        $ints = static fn ($values) => collect((array) ($values ?? []))
            ->map(static fn ($v) => (int) $v)
            ->filter(static fn ($v) => $v > 0)
            ->unique()->sort()->values()->all();

        $pick = static fn ($values, array $allowed) => collect((array) ($values ?? []))
            ->map(static fn ($v) => (string) $v)
            ->filter(static fn ($v) => in_array($v, $allowed, true))
            ->unique()->values()->all();

        return [
            'document_type_ids' => $ints($input['document_type_ids'] ?? []),
            'sources' => $pick($input['sources'] ?? [], array_keys(self::SOURCES)),
            'splits' => $pick($input['splits'] ?? [], array_keys(self::SPLITS)),
            'tag_ids' => $ints($input['tag_ids'] ?? []),
            'verified_only' => filter_var($input['verified_only'] ?? false, FILTER_VALIDATE_BOOL),
            'with_boxes_only' => filter_var($input['with_boxes_only'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    public static function normalizeOptions(array $input): array
    {
        $format = (string) ($input['format'] ?? 'json');
        if (! array_key_exists($format, self::FORMATS)) {
            $format = 'json';
        }

        $resplit = filter_var($input['resplit'] ?? false, FILTER_VALIDATE_BOOL);

        $ratios = [
            'train' => (int) ($input['train_ratio'] ?? 80),
            'val' => (int) ($input['val_ratio'] ?? 20),
            'test' => (int) ($input['test_ratio'] ?? 0),
        ];

        foreach ($ratios as $key => $value) {
            $ratios[$key] = max(0, min(100, $value));
        }

        if (array_sum($ratios) === 0) {
            $ratios = ['train' => 100, 'val' => 0, 'test' => 0];
        }

        return [
            'format' => $format,
            // قالب tesseract بدون تصویر بی‌معنی است، پس همیشه تصویر دارد.
            'include_images' => $format === 'tesseract'
                ? true
                : filter_var($input['include_images'] ?? true, FILTER_VALIDATE_BOOL),
            'resplit' => $resplit,
            'ratios' => $ratios,
        ];
    }

    /* ==================================================================
     | کوئری نمونه‌ها و پیش‌نمایش
     * ================================================================== */

    public function baseQuery(array $filters): Builder
    {
        $filters = self::normalizeFilters($filters);

        $query = DatasetSample::query();

        if ($filters['document_type_ids'] !== []) {
            $query->whereIn('document_type_id', $filters['document_type_ids']);
        }

        if ($filters['sources'] !== []) {
            $query->whereIn('source', $filters['sources']);
        }

        if ($filters['splits'] !== []) {
            $query->whereIn('split', $filters['splits']);
        }

        if ($filters['verified_only']) {
            $query->where('is_verified', true);
        }

        if ($filters['tag_ids'] !== []) {
            // «هر کدام از تگ‌های انتخاب‌شده» — نه لزوماً همه با هم.
            $query->whereHas('tags', function ($tagQuery) use ($filters): void {
                $tagQuery->whereIn('dataset_tags.id', $filters['tag_ids']);
            });
        }

        if ($filters['with_boxes_only']) {
            $query->whereHas('annotations', function ($annotationQuery): void {
                $annotationQuery->whereNotNull('bbox_w')
                    ->whereNotNull('bbox_h')
                    ->where('bbox_w', '>', 0)
                    ->where('bbox_h', '>', 0);
            });
        }

        return $query;
    }

    /** شمارش زندهٔ نمونه‌های منطبق با فیلتر، به‌تفکیک نوع مدرک. */
    public function preview(array $filters): array
    {
        $filters = self::normalizeFilters($filters);

        $perType = $this->baseQuery($filters)
            ->getQuery()
            ->select('document_type_id')
            ->selectRaw('COUNT(*) AS aggregate_total')
            ->groupBy('document_type_id')
            ->pluck('aggregate_total', 'document_type_id');

        $types = DocumentType::query()->orderBy('sort')->get(['id', 'key', 'label_fa']);

        $byType = [];
        foreach ($types as $type) {
            $count = (int) ($perType[$type->id] ?? 0);
            if ($count > 0) {
                $byType[] = ['key' => $type->key, 'label' => $type->label_fa, 'count' => $count];
            }
        }

        $total = (int) $perType->sum();

        $withBoxes = (int) $this->baseQuery($filters)
            ->whereHas('annotations', function ($annotationQuery): void {
                $annotationQuery->whereNotNull('bbox_w')->where('bbox_w', '>', 0);
            })
            ->count();

        $verified = (int) $this->baseQuery($filters)->where('is_verified', true)->count();

        return [
            'total' => $total,
            'capped' => min($total, self::MAX_SAMPLES),
            'max' => self::MAX_SAMPLES,
            'with_boxes' => $withBoxes,
            'verified' => $verified,
            'by_type' => $byType,
        ];
    }

    /** توضیح فارسی فیلترها برای README و فهرست خروجی‌ها. */
    public static function describeFilters(array $filters, array $options): array
    {
        $filters = self::normalizeFilters($filters);
        $options = self::normalizeOptions($options);
        $lines = [];

        $typeLabels = $filters['document_type_ids'] === []
            ? 'همهٔ انواع مدرک'
            : DocumentType::query()->whereIn('id', $filters['document_type_ids'])
                ->orderBy('sort')->pluck('label_fa')->implode('، ');
        $lines[] = 'نوع مدرک: '.($typeLabels !== '' ? $typeLabels : 'همهٔ انواع مدرک');

        $lines[] = 'منبع نمونه: '.($filters['sources'] === []
            ? 'همهٔ منبع‌ها'
            : collect($filters['sources'])->map(fn ($s) => self::SOURCES[$s])->implode('، '));

        $lines[] = 'بخش دیتاست: '.($filters['splits'] === []
            ? 'همهٔ بخش‌ها'
            : collect($filters['splits'])->map(fn ($s) => self::SPLITS[$s])->implode('، '));

        $tagLabels = $filters['tag_ids'] === []
            ? 'بدون فیلتر تگ'
            : DatasetTag::query()->whereIn('id', $filters['tag_ids'])->pluck('name')->implode('، ');
        $lines[] = 'تگ‌ها: '.($tagLabels !== '' ? $tagLabels : 'بدون فیلتر تگ');

        $lines[] = 'فقط نمونه‌های تاییدشده: '.($filters['verified_only'] ? 'بله' : 'خیر');
        $lines[] = 'فقط نمونه‌های دارای کادر: '.($filters['with_boxes_only'] ? 'بله' : 'خیر');
        $lines[] = 'قالب خروجی: '.self::FORMATS[$options['format']];
        $lines[] = 'شامل تصویرها: '.($options['include_images'] ? 'بله' : 'خیر');

        if ($options['resplit']) {
            $lines[] = sprintf(
                'بازتقسیم train/val/test: %d/%d/%d درصد (فقط در همین خروجی، رکورد دیتابیس تغییر نمی‌کند)',
                $options['ratios']['train'],
                $options['ratios']['val'],
                $options['ratios']['test'],
            );
        } else {
            $lines[] = 'بازتقسیم train/val/test: خیر (همان مقدار ثبت‌شدهٔ هر نمونه)';
        }

        return $lines;
    }

    /* ==================================================================
     | فایل‌های وضعیت روی دیسک exports
     * ================================================================== */

    public static function newToken(): string
    {
        do {
            $token = 'export-'.Carbon::now()->format('Ymd-His').'-'.bin2hex(random_bytes(3));
        } while (Storage::disk(self::DISK)->exists(self::metaPath($token)));

        return $token;
    }

    public static function isValidToken(string $token): bool
    {
        return (bool) preg_match(self::TOKEN_REGEX, $token);
    }

    public static function metaPath(string $token): string
    {
        return self::DIR.'/'.$token.'.json';
    }

    public static function zipPath(string $token): string
    {
        return self::DIR.'/'.$token.'.zip';
    }

    public static function writeMeta(string $token, array $meta): void
    {
        if (! self::isValidToken($token)) {
            throw new RuntimeException('شناسهٔ خروجی معتبر نیست.');
        }

        Storage::disk(self::DISK)->put(
            self::metaPath($token),
            json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );
    }

    public static function readMeta(string $token): ?array
    {
        if (! self::isValidToken($token)) {
            return null;
        }

        $disk = Storage::disk(self::DISK);
        $path = self::metaPath($token);

        if (! $disk->exists($path)) {
            return null;
        }

        $decoded = json_decode((string) $disk->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** فهرست همهٔ خروجی‌ها (تازه‌ترین اول) همراه با اندازهٔ فایل zip. */
    public static function listAll(): array
    {
        $disk = Storage::disk(self::DISK);
        $rows = [];

        foreach ($disk->files(self::DIR) as $file) {
            if (! str_ends_with($file, '.json')) {
                continue;
            }

            $token = basename($file, '.json');
            $meta = self::readMeta($token);

            if ($meta === null) {
                continue;
            }

            $zipPath = self::zipPath($token);
            $hasZip = $disk->exists($zipPath);

            $meta['token'] = $token;
            $meta['has_zip'] = $hasZip;
            $meta['size'] = $hasZip ? (int) $disk->size($zipPath) : 0;
            $meta['size_human'] = self::humanSize($meta['size']);
            $meta['status'] = $meta['status'] ?? 'queued';
            $meta['status_label'] = self::STATUS_LABELS[$meta['status']] ?? $meta['status'];
            $meta['status_tone'] = self::STATUS_TONES[$meta['status']] ?? 'info';
            $meta['is_pending'] = in_array($meta['status'], ['queued', 'running'], true);

            $rows[] = $meta;
        }

        usort($rows, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return $rows;
    }

    /** حذف کامل یک خروجی: zip، فایل نیمه‌کاره و فایل وضعیت. */
    public static function forget(string $token): bool
    {
        if (! self::isValidToken($token)) {
            return false;
        }

        $disk = Storage::disk(self::DISK);
        $removed = false;

        foreach ([self::zipPath($token), self::zipPath($token).'.part', self::metaPath($token)] as $path) {
            if ($disk->exists($path)) {
                $disk->delete($path);
                $removed = true;
            }
        }

        return $removed;
    }

    /**
     * بستهٔ در حال ساخت (در صف یا در حال اجرا) متعلق به همین کاربر.
     * هر کاربر هم‌زمان فقط یک بسته می‌سازد تا صف و دیسک را قرق نکند.
     *
     * اگر فهرست بسته‌ها را همین حالا خوانده‌اید، همان را پاس بدهید تا دیسک
     * دو بار خوانده نشود.
     */
    public static function pendingForUser(?int $userId, ?array $rows = null): ?array
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }

        foreach ($rows ?? self::listAll() as $row) {
            if (! ($row['is_pending'] ?? false) || self::isStalePending($row)) {
                continue;
            }

            if ((int) ($row['created_by']['id'] ?? 0) === $userId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * پاک‌سازی خودکار بسته‌ها: فقط KEEP_LAST بستهٔ تازه‌تر می‌ماند، هر بستهٔ
     * قدیمی‌تر از KEEP_DAYS روز حذف می‌شود و در پایان مجموع حجم هم به زیر
     * KEEP_BYTES می‌آید. بسته‌های در حال ساخت هرگز حذف نمی‌شوند.
     * خروجی: فهرست شناسهٔ بسته‌های حذف‌شده.
     *
     * @return array<int, string>
     */
    public static function prune(): array
    {
        $rows = self::listAll(); // تازه‌ترین اول
        $cutoff = Carbon::now()->subDays(self::KEEP_DAYS);
        $removed = [];

        foreach (array_values($rows) as $index => $row) {
            if ($row['is_pending'] ?? false) {
                // کارگر وسط کار کشته شده: وضعیت را واقعی کن تا نه صف را قفل
                // کند نه از پاک‌سازی دور بماند.
                if (self::isStalePending($row)) {
                    $row['status'] = 'failed';
                    $row['finished_at'] = Carbon::now()->toIso8601String();
                    $row['error'] = 'ساخت این بسته نیمه‌کاره ماند و بیش از حد طول کشید.';
                    unset($row['is_pending'], $row['status_label'], $row['status_tone'], $row['size_human'], $row['has_zip'], $row['size']);
                    self::writeMeta((string) $row['token'], $row);
                }

                continue;
            }

            $tooMany = $index >= self::KEEP_LAST;
            $tooOld = false;

            $createdAt = $row['created_at'] ?? null;
            if (is_string($createdAt) && $createdAt !== '') {
                try {
                    $tooOld = Carbon::parse($createdAt)->lt($cutoff);
                } catch (\Throwable) {
                    $tooOld = false;
                }
            }

            if (($tooMany || $tooOld) && self::forget((string) $row['token'])) {
                $removed[] = (string) $row['token'];
            }
        }

        return array_merge($removed, self::pruneBySize());
    }

    /**
     * تا وقتی مجموع حجم بسته‌ها از KEEP_BYTES بیشتر است، قدیمی‌ترین بستهٔ
     * آماده حذف می‌شود. بسته‌های در حال ساخت دست‌نخورده می‌مانند.
     *
     * @return array<int, string>
     */
    private static function pruneBySize(): array
    {
        $rows = self::listAll(); // تازه‌ترین اول
        $total = 0;

        foreach ($rows as $row) {
            $total += (int) ($row['size'] ?? 0);
        }

        $removed = [];

        foreach (array_reverse($rows) as $row) { // از قدیمی‌ترین
            if ($total <= self::KEEP_BYTES) {
                break;
            }

            if ($row['is_pending'] ?? false) {
                continue;
            }

            if (self::forget((string) $row['token'])) {
                $total -= (int) ($row['size'] ?? 0);
                $removed[] = (string) $row['token'];
            }
        }

        return $removed;
    }

    /** بستهٔ در حال ساختی که دیگر کارگری پشتش نیست. */
    private static function isStalePending(array $row): bool
    {
        $moment = $row['started_at'] ?? $row['created_at'] ?? null;

        if (! is_string($moment) || $moment === '') {
            return false;
        }

        try {
            return Carbon::parse($moment)->lt(Carbon::now()->subMinutes(self::PENDING_STALE_MINUTES));
        } catch (\Throwable) {
            return false;
        }
    }

    /** متن فارسی قانون نگهداری برای نمایش در رابط کاربری و README. */
    public static function retentionNote(): string
    {
        return sprintf(
            'نگهداری خودکار: حداکثر %s بستهٔ آخر، حداکثر %s روز و مجموعاً حداکثر %s؛ بسته‌های قدیمی‌تر هنگام ثبت درخواست تازه خودکار حذف می‌شوند.',
            self::faDigits((string) self::KEEP_LAST),
            self::faDigits((string) self::KEEP_DAYS),
            self::humanSize(self::KEEP_BYTES),
        );
    }

    /** متن فارسی سهمیهٔ ساخت برای نمایش در رابط کاربری. */
    public static function quotaNote(): string
    {
        return sprintf(
            'سقف ساخت: %s بسته در هر ساعت برای هر کاربر و هم‌زمان فقط یک بسته.',
            self::faDigits((string) self::RATE_LIMIT_PER_HOUR),
        );
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '۰ بایت';
        }

        $units = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return self::faDigits(number_format($value, $power === 0 ? 0 : 1, '.', ',')).' '.$units[$power];
    }

    public static function faDigits(string $text): string
    {
        return strtr($text, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
            ',' => '٬', '.' => '٫',
        ]);
    }

    /* ==================================================================
     | ساخت بسته
     * ================================================================== */

    /**
     * بستهٔ zip را می‌سازد و فایل وضعیت را به‌روز نگه می‌دارد.
     * فقط از داخل Job صدا زده می‌شود.
     */
    public function build(string $token): array
    {
        $meta = self::readMeta($token);

        if ($meta === null) {
            throw new RuntimeException('فایل وضعیت این خروجی پیدا نشد.');
        }

        $filters = self::normalizeFilters($meta['filters'] ?? []);
        $options = self::normalizeOptions($meta['options'] ?? []);

        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(self::DIR);

        $zipRelative = self::zipPath($token);
        $partRelative = $zipRelative.'.part';
        $partAbsolute = $disk->path($partRelative);

        if (is_file($partAbsolute)) {
            @unlink($partAbsolute);
        }

        $meta['status'] = 'running';
        $meta['started_at'] = Carbon::now()->toIso8601String();
        $meta['error'] = null;
        $meta['progress'] = ['done' => 0, 'total' => 0];
        self::writeMeta($token, $meta);

        $temporaryFiles = [];
        $cropDirectory = $options['format'] === 'tesseract' ? self::makeTempDirectory() : null;

        try {
            $total = min((int) $this->baseQuery($filters)->count(), self::MAX_SAMPLES);
            $meta['progress'] = ['done' => 0, 'total' => $total];
            self::writeMeta($token, $meta);

            $zip = new ZipArchive();
            if ($zip->open($partAbsolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('ساخت فایل zip روی دیسک ممکن نشد.');
            }

            $stats = [
                'samples' => 0,
                'images' => 0,
                'missing_images' => 0,
                'annotations' => 0,
                'boxes' => 0,
                'lines' => 0,
                'samples_without_lines' => 0,
                'by_type' => [],
                'by_split' => ['train' => 0, 'val' => 0, 'test' => 0],
                'by_field' => [],
            ];

            $fieldLabels = $this->fieldLabelMap();

            $samplesStream = null;
            $handle = null;

            if ($options['format'] === 'json') {
                $samplesStream = tempnam(sys_get_temp_dir(), 'hana-samples-');
                if ($samplesStream === false) {
                    throw new RuntimeException('ساخت فایل موقت برای خروجی JSON ممکن نشد.');
                }
                $temporaryFiles[] = $samplesStream;
                $handle = fopen($samplesStream, 'wb');
                if ($handle === false) {
                    throw new RuntimeException('باز کردن فایل موقت خروجی JSON ممکن نشد.');
                }
            }

            $isFirstSample = true;
            $processed = 0;

            // پیمایش نمونه‌ها به‌صورت جریانی؛ چند صد فایل نباید در حافظه جمع شود.
            $query = $this->baseQuery($filters)
                ->with(['documentType:id,key,label_fa', 'tags:id,name', 'annotations'])
                ->orderBy('id');

            foreach ($query->lazyById(100)->take($total) as $sample) {
                $row = $this->collectSample($sample, $options, $token, $fieldLabels, $stats, $cropDirectory);

                if ($options['format'] === 'json') {
                    fwrite($handle, ($isFirstSample ? '' : ",\n").json_encode(
                        $row['json'],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    ));
                    $isFirstSample = false;

                    if ($options['include_images'] && $row['image_absolute'] !== null) {
                        $zip->addFile($row['image_absolute'], $row['image_entry']);
                    }
                } else {
                    // هر «خط» یک سه‌تایی است: تصویر برش‌خورده، متن تک‌خطی و کادر کاراکترها.
                    foreach ($row['lines'] as $line) {
                        $zip->addFile($line['image_path'], $line['image_entry']);
                        $zip->addFromString($line['gt_entry'], $line['gt_text']);
                        $zip->addFromString($line['box_entry'], $line['box_text']);
                        $temporaryFiles[] = $line['image_path'];
                    }
                }

                $processed++;

                if ($processed % 25 === 0) {
                    $meta['progress'] = ['done' => $processed, 'total' => $total];
                    self::writeMeta($token, $meta);
                }
            }

            if ($options['format'] === 'json') {
                fclose($handle);

                $bundle = tempnam(sys_get_temp_dir(), 'hana-dataset-');
                if ($bundle === false) {
                    throw new RuntimeException('ساخت فایل موقت dataset.json ممکن نشد.');
                }
                $temporaryFiles[] = $bundle;
                $bundleHandle = fopen($bundle, 'wb');

                fwrite($bundleHandle, "{\n  \"meta\": ".json_encode(
                    $this->jsonMetaBlock($token, $meta, $filters, $options, $stats),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ).",\n  \"samples\": [\n");

                $source = fopen($samplesStream, 'rb');
                stream_copy_to_stream($source, $bundleHandle);
                fclose($source);

                fwrite($bundleHandle, "\n  ]\n}\n");
                fclose($bundleHandle);

                $zip->addFile($bundle, 'dataset.json');
            }

            $zip->addFromString('README.txt', $this->readmeText($token, $meta, $filters, $options, $stats));

            if ($zip->close() !== true) {
                throw new RuntimeException('بستن فایل zip با خطا روبه‌رو شد.');
            }

            if ($disk->exists($zipRelative)) {
                $disk->delete($zipRelative);
            }

            $disk->move($partRelative, $zipRelative);

            $meta['status'] = 'done';
            $meta['finished_at'] = Carbon::now()->toIso8601String();
            $meta['counts'] = $stats;
            $meta['progress'] = ['done' => $processed, 'total' => $total];
            $meta['zip'] = [
                'name' => $token.'.zip',
                'size' => (int) $disk->size($zipRelative),
            ];
            self::writeMeta($token, $meta);

            return $meta;
        } catch (\Throwable $exception) {
            if (is_file($partAbsolute)) {
                @unlink($partAbsolute);
            }

            $meta['status'] = 'failed';
            $meta['finished_at'] = Carbon::now()->toIso8601String();
            $meta['error'] = $exception->getMessage();
            self::writeMeta($token, $meta);

            throw $exception;
        } finally {
            foreach ($temporaryFiles as $temporaryFile) {
                if (is_string($temporaryFile) && is_file($temporaryFile)) {
                    @unlink($temporaryFile);
                }
            }

            if ($cropDirectory !== null && is_dir($cropDirectory)) {
                foreach ((array) glob($cropDirectory.'/*') as $leftover) {
                    if (is_string($leftover) && is_file($leftover)) {
                        @unlink($leftover);
                    }
                }

                @rmdir($cropDirectory);
            }
        }
    }

    /* ==================================================================
     | کمکی‌های ساخت
     * ================================================================== */

    /** نگاشت [شناسهٔ نوع مدرک][کلید فیلد] => برچسب فارسی، با ترتیب فیلدها. */
    private function fieldLabelMap(): array
    {
        $map = [];
        $order = [];

        foreach (DocumentTypeField::query()->orderBy('sort')->get() as $field) {
            $map[$field->document_type_id][$field->key] = $field->label_fa;
            $order[$field->document_type_id][] = $field->key;
        }

        return ['labels' => $map, 'order' => $order];
    }

    /**
     * یک نمونه را به سطر خروجی تبدیل می‌کند و آمار را به‌روز می‌کند.
     *
     * @return array{json: array, image_absolute: ?string, image_entry: string, lines: array<int, array<string, string>>}
     */
    private function collectSample(DatasetSample $sample, array $options, string $token, array $fieldLabels, array &$stats, ?string $cropDirectory = null): array
    {
        $typeKey = $sample->documentType?->key ?? 'unknown';
        $typeLabel = $sample->documentType?->label_fa ?? 'نامشخص';
        $typeId = (int) $sample->document_type_id;

        $split = $options['resplit']
            ? $this->assignSplit($token, (int) $sample->id, $options['ratios'])
            : (string) $sample->split;

        if (! array_key_exists($split, $stats['by_split'])) {
            $stats['by_split'][$split] = 0;
        }
        $stats['by_split'][$split]++;

        $stats['samples']++;
        if (! isset($stats['by_type'][$typeKey])) {
            $stats['by_type'][$typeKey] = ['label' => $typeLabel, 'count' => 0];
        }
        $stats['by_type'][$typeKey]['count']++;

        // نام فایل داخل zip فقط از دادهٔ دیتابیس ساخته می‌شود، نه از نام اصلی فایل.
        $extension = strtolower((string) pathinfo((string) $sample->path, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'png';
        $extension = substr($extension, 0, 5);
        $base = $typeKey.'-'.$sample->id;

        $imageAbsolute = null;
        try {
            $imageDisk = Storage::disk($sample->disk ?: 'dataset');
            if ($sample->path && $imageDisk->exists($sample->path)) {
                $imageAbsolute = $imageDisk->path($sample->path);
            }
        } catch (\Throwable) {
            $imageAbsolute = null;
        }

        if ($imageAbsolute === null) {
            $stats['missing_images']++;
        }

        $isTesseract = $options['format'] === 'tesseract';

        // در قالب tesseract تصویر تمام‌کارت وارد بسته نمی‌شود؛ به‌جایش برای هر
        // فیلدِ دارای کادر یک تصویر تک‌خطی بریده می‌شود.
        $wantsImage = ! $isTesseract && $options['include_images'] && $imageAbsolute !== null;

        $imageEntry = 'images/'.$base.'.'.$extension;

        if ($wantsImage) {
            $stats['images']++;
        }

        [$width, $height] = $this->dimensions($sample, $imageAbsolute);

        // فیلدها به ترتیب تعریف‌شده در نوع مدرک، بعد فیلدهای ناشناخته.
        $annotations = $sample->annotations->keyBy('field_key');
        $ordered = $fieldLabels['order'][$typeId] ?? [];
        $keys = array_values(array_unique(array_merge(
            array_values(array_filter($ordered, fn ($k) => $annotations->has($k))),
            $annotations->keys()->all(),
        )));

        $fields = [];
        $lineSpecs = [];

        foreach ($keys as $key) {
            $annotation = $annotations->get($key);
            if ($annotation === null) {
                continue;
            }

            $label = $fieldLabels['labels'][$typeId][$key] ?? $key;
            $value = (string) ($annotation->value ?? '');
            $hasBox = $annotation->bbox_w !== null && $annotation->bbox_h !== null
                && (float) $annotation->bbox_w > 0 && (float) $annotation->bbox_h > 0;

            $stats['annotations']++;
            $statKey = $typeKey.'/'.$key;
            if (! isset($stats['by_field'][$statKey])) {
                $stats['by_field'][$statKey] = [
                    'label' => $typeLabel.' / '.$label,
                    'values' => 0,
                    'boxes' => 0,
                ];
            }
            $stats['by_field'][$statKey]['values']++;

            if ($hasBox) {
                $stats['boxes']++;
                $stats['by_field'][$statKey]['boxes']++;
            }

            $fields[] = [
                'key' => $key,
                'label' => $label,
                'value' => $value,
                'source' => (string) $annotation->source,
                'bbox' => $hasBox ? [
                    'x' => round((float) $annotation->bbox_x, 6),
                    'y' => round((float) $annotation->bbox_y, 6),
                    'w' => round((float) $annotation->bbox_w, 6),
                    'h' => round((float) $annotation->bbox_h, 6),
                ] : null,
            ];

            // متن tesstrain باید تک‌خطی باشد؛ هر فاصلهٔ اضافه یا شکست خط یکسان می‌شود.
            $lineValue = trim((string) preg_replace('/\s+/u', ' ', $value));

            if ($isTesseract && $hasBox && $lineValue !== '') {
                $lineSpecs[] = ['key' => $key, 'value' => $lineValue, 'annotation' => $annotation];
            }
        }

        $lines = $isTesseract
            ? $this->lineFiles($lineSpecs, $imageAbsolute, $split, $base, $cropDirectory, $stats)
            : [];

        return [
            'json' => [
                'id' => (int) $sample->id,
                'document_type' => $typeKey,
                'document_type_label' => $typeLabel,
                'image' => $wantsImage ? $imageEntry : null,
                'source_path' => $sample->disk.'/'.$sample->path,
                'width' => $width ?: null,
                'height' => $height ?: null,
                'split' => $split,
                'source' => (string) $sample->source,
                'is_verified' => (bool) $sample->is_verified,
                'augmentation' => $sample->augmentation,
                'augmentation_params' => $sample->augmentation_params,
                'tags' => $sample->tags->pluck('name')->values()->all(),
                'fields' => $fields,
            ],
            'image_absolute' => $imageAbsolute,
            'image_entry' => $imageEntry,
            'lines' => $lines,
        ];
    }

    /** پوشهٔ موقت برش‌ها؛ در پایان ساخت کامل پاک می‌شود. */
    private static function makeTempDirectory(): string
    {
        $path = sys_get_temp_dir().'/hana-lines-'.bin2hex(random_bytes(6));

        if (! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('ساخت پوشهٔ موقت برای بریدن خط‌ها ممکن نشد.');
        }

        return $path;
    }

    /**
     * تبدیل هر فیلدِ دارای کادر به یک نمونهٔ آموزشی tesstrain:
     * تصویر بریده‌شدهٔ همان کادر + یک .gt.txt تک‌خطی + یک .box با مختصات
     * کاراکترها *داخل همان برش*. فیلد بدون کادر یا نمونهٔ بدون تصویر خروجی
     * ندارد؛ شمارشش در آمار می‌آید.
     *
     * @param  array<int, array{key: string, value: string, annotation: mixed}>  $specs
     * @return array<int, array<string, string>>
     */
    private function lineFiles(array $specs, ?string $imageAbsolute, string $split, string $base, ?string $cropDirectory, array &$stats): array
    {
        if ($specs === [] || $imageAbsolute === null || $cropDirectory === null) {
            $stats['samples_without_lines']++;

            return [];
        }

        $source = @imagecreatefromstring((string) @file_get_contents($imageAbsolute));

        if ($source === false) {
            $stats['samples_without_lines']++;

            return [];
        }

        $imageWidth = imagesx($source);
        $imageHeight = imagesy($source);
        $lines = [];

        try {
            foreach (array_values($specs) as $index => $spec) {
                $annotation = $spec['annotation'];

                $fieldLeft = (float) $annotation->bbox_x * $imageWidth;
                $fieldTop = (float) $annotation->bbox_y * $imageHeight;
                $fieldWidth = (float) $annotation->bbox_w * $imageWidth;
                $fieldHeight = (float) $annotation->bbox_h * $imageHeight;

                $padding = max(2, (int) round($fieldHeight * self::LINE_PADDING_RATIO));

                $cropLeft = max(0, (int) floor($fieldLeft) - $padding);
                $cropTop = max(0, (int) floor($fieldTop) - $padding);
                $cropWidth = min((int) ceil($fieldWidth) + (2 * $padding), $imageWidth - $cropLeft);
                $cropHeight = min((int) ceil($fieldHeight) + (2 * $padding), $imageHeight - $cropTop);

                // برش خیلی کوچک به درد آموزش نمی‌خورد.
                if ($cropWidth < 4 || $cropHeight < 4) {
                    continue;
                }

                $crop = imagecrop($source, [
                    'x' => $cropLeft,
                    'y' => $cropTop,
                    'width' => $cropWidth,
                    'height' => $cropHeight,
                ]);

                if ($crop === false) {
                    continue;
                }

                // نام فایل فقط از کلید فیلد و شناسهٔ نمونه ساخته می‌شود.
                $safeKey = (string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) $spec['key']);
                $name = sprintf('%s-%02d-%s', $base, $index + 1, $safeKey !== '' ? $safeKey : 'field');
                $path = $cropDirectory.'/'.$name.'.png';

                $written = @imagepng($crop, $path, 6);
                imagedestroy($crop);

                if ($written !== true || ! is_file($path)) {
                    continue;
                }

                $boxLines = $this->charBoxes(
                    $spec['value'],
                    $fieldLeft - $cropLeft,
                    $fieldTop - $cropTop,
                    $fieldWidth,
                    $fieldHeight,
                    $cropWidth,
                    $cropHeight,
                );

                $stats['lines']++;
                $stats['images']++;

                $lines[] = [
                    'image_path' => $path,
                    'image_entry' => $split.'/'.$name.'.png',
                    'gt_entry' => $split.'/'.$name.'.gt.txt',
                    'gt_text' => $spec['value']."\n",
                    'box_entry' => $split.'/'.$name.'.box',
                    'box_text' => $boxLines === [] ? '' : implode("\n", $boxLines)."\n",
                ];
            }
        } finally {
            imagedestroy($source);
        }

        if ($lines === []) {
            $stats['samples_without_lines']++;
        }

        return $lines;
    }

    /** ابعاد تصویر: اول از دیتابیس، اگر نبود از خود فایل. */
    private function dimensions(DatasetSample $sample, ?string $absolute): array
    {
        $width = (int) ($sample->width ?? 0);
        $height = (int) ($sample->height ?? 0);

        if (($width <= 0 || $height <= 0) && $absolute !== null) {
            $size = @getimagesize($absolute);
            if (is_array($size)) {
                $width = (int) $size[0];
                $height = (int) $size[1];
            }
        }

        return [$width, $height];
    }

    /**
     * کادر کاراکترها به فرمت tesseract: «کاراکتر چپ پایین راست بالا صفحه».
     * مختصات ورودی پیکسلی و نسبت به گوشهٔ بالا-چپ همان تصویری است که فایل
     * .box کنارش می‌نشیند (یعنی برشِ تک‌خطی)، اما مبدأ tesseract پایین-چپ
     * است؛ پس محور y برعکس می‌شود.
     */
    private function charBoxes(string $value, float $left, float $top, float $boxWidth, float $boxHeight, int $width, int $height): array
    {
        $characters = mb_str_split($value);
        $count = count($characters);

        if ($count === 0 || $width <= 0 || $height <= 0 || $boxWidth <= 0 || $boxHeight <= 0) {
            return [];
        }

        // جهت نوشتار: اگر بعد از حذف ارقام فارسی/عربی حرفی از خط عربی بماند،
        // متن راست‌چین است و کاراکتر اول در سمت راست کادر می‌نشیند.
        $withoutDigits = preg_replace('/[\x{06F0}-\x{06F9}\x{0660}-\x{0669}]/u', '', $value);
        $isRtl = (bool) preg_match('/\p{Arabic}/u', (string) $withoutDigits);

        $step = $boxWidth / $count;
        $bottomPixel = (int) round($height - ($top + $boxHeight));
        $topPixel = (int) round($height - $top);

        $bottomPixel = max(0, min($height, $bottomPixel));
        $topPixel = max(0, min($height, $topPixel));

        if ($topPixel <= $bottomPixel) {
            $topPixel = min($height, $bottomPixel + 1);
        }

        $lines = [];

        foreach ($characters as $index => $character) {
            // فاصله در فایل box نوشته نمی‌شود، ولی جای آن حفظ می‌شود.
            if (trim($character) === '') {
                continue;
            }

            if ($isRtl) {
                $right = $left + $boxWidth - ($index * $step);
                $charLeft = $right - $step;
            } else {
                $charLeft = $left + ($index * $step);
                $right = $charLeft + $step;
            }

            $x1 = max(0, min($width, (int) round($charLeft)));
            $x2 = max(0, min($width, (int) round($right)));

            if ($x2 <= $x1) {
                $x2 = min($width, $x1 + 1);
            }

            $lines[] = $character.' '.$x1.' '.$bottomPixel.' '.$x2.' '.$topPixel.' 0';
        }

        return $lines;
    }

    /** بازتقسیم قطعی (بدون تصادف) بر پایهٔ شناسهٔ خروجی و شناسهٔ نمونه. */
    private function assignSplit(string $token, int $sampleId, array $ratios): string
    {
        $sum = array_sum($ratios);
        if ($sum <= 0) {
            return 'train';
        }

        $bucket = crc32($token.':'.$sampleId) % 1000;
        $trainEdge = (int) round(1000 * $ratios['train'] / $sum);
        $valEdge = $trainEdge + (int) round(1000 * $ratios['val'] / $sum);

        if ($bucket < $trainEdge) {
            return 'train';
        }

        return $bucket < $valEdge ? 'val' : 'test';
    }

    private function jsonMetaBlock(string $token, array $meta, array $filters, array $options, array $stats): array
    {
        return [
            'export_token' => $token,
            'generator' => 'سامانه هانا — خروجی دیتاست',
            'format' => $options['format'],
            'created_at' => $meta['created_at'] ?? Carbon::now()->toIso8601String(),
            'built_at' => Carbon::now()->toIso8601String(),
            'created_by' => $meta['created_by'] ?? null,
            'bbox_space' => 'نسبی ۰ تا ۱ نسبت به گوشهٔ بالا-چپ تصویر',
            'images_included' => $options['include_images'],
            'resplit' => $options['resplit'] ? $options['ratios'] : false,
            'filters' => $filters,
            'filters_fa' => self::describeFilters($filters, $options),
            'counts' => $stats,
        ];
    }

    private function readmeText(string $token, array $meta, array $filters, array $options, array $stats): string
    {
        $fa = static fn ($value) => self::faDigits((string) $value);
        $now = Carbon::now()->timezone(config('panel_menu.timezone', 'Asia/Tehran'));

        $lines = [];
        $lines[] = 'گزارش خروجی دیتاست — سامانه هانا';
        $lines[] = str_repeat('=', 42);
        $lines[] = '';
        $lines[] = 'شناسهٔ خروجی : '.$token;
        $lines[] = 'قالب خروجی   : '.self::FORMATS[$options['format']];
        $lines[] = 'تاریخ ساخت   : '.$fa(self::jalaliDate($now)).' ساعت '.$fa($now->format('H:i')).' (به وقت تهران)';
        $lines[] = 'سازنده       : '.($meta['created_by']['name'] ?? 'نامشخص');
        $lines[] = '';
        $lines[] = 'فیلترهای اعمال‌شده';
        $lines[] = str_repeat('-', 42);
        foreach (self::describeFilters($filters, $options) as $line) {
            $lines[] = '  • '.$line;
        }
        $lines[] = '';
        $lines[] = 'خلاصهٔ آمار';
        $lines[] = str_repeat('-', 42);
        $lines[] = '  تعداد کل نمونه‌ها      : '.$fa($stats['samples']);
        $lines[] = '  تعداد تصویرهای بسته    : '.$fa($stats['images']);
        $lines[] = '  تعداد برچسب فیلدها     : '.$fa($stats['annotations']);
        $lines[] = '  تعداد کادرهای موجود    : '.$fa($stats['boxes']);

        if ($options['format'] === 'tesseract') {
            $lines[] = '  تعداد خط‌های بریده‌شده  : '.$fa($stats['lines'] ?? 0);

            if (($stats['samples_without_lines'] ?? 0) > 0) {
                $lines[] = '  نمونه‌های بدون خط      : '.$fa($stats['samples_without_lines']).' (کادر یا تصویر نداشتند)';
            }
        }

        if ($stats['missing_images'] > 0) {
            $lines[] = '  تصویرهای گم‌شده روی دیسک: '.$fa($stats['missing_images']);
        }

        $lines[] = '';
        $lines[] = 'به تفکیک نوع مدرک';
        $lines[] = str_repeat('-', 42);
        if ($stats['by_type'] === []) {
            $lines[] = '  (هیچ نمونه‌ای با این فیلتر پیدا نشد)';
        } else {
            foreach ($stats['by_type'] as $key => $row) {
                $lines[] = '  • '.$row['label'].' ('.$key.'): '.$fa($row['count']).' نمونه';
            }
        }

        $lines[] = '';
        $lines[] = 'به تفکیک بخش دیتاست';
        $lines[] = str_repeat('-', 42);
        foreach ($stats['by_split'] as $split => $count) {
            $lines[] = '  • '.(self::SPLITS[$split] ?? $split).': '.$fa($count).' نمونه';
        }

        $lines[] = '';
        $lines[] = 'به تفکیک فیلد';
        $lines[] = str_repeat('-', 42);
        if ($stats['by_field'] === []) {
            $lines[] = '  (هیچ برچسبی در این بسته نیست)';
        } else {
            foreach ($stats['by_field'] as $row) {
                $lines[] = '  • '.$row['label'].': '.$fa($row['values']).' مقدار، '.$fa($row['boxes']).' کادر';
            }
        }

        $lines[] = '';
        $lines[] = 'ساختار فایل‌ها';
        $lines[] = str_repeat('-', 42);

        if ($options['format'] === 'json') {
            $lines[] = '  README.txt    همین گزارش';
            $lines[] = '  dataset.json  {meta, samples[]} — هر نمونه: id، document_type، image،';
            $lines[] = '                width، height، split، augmentation، tags و fields[]';
            $lines[] = '                هر فیلد: key، label، value و bbox {x, y, w, h}';
            $lines[] = '                مقدار bbox نسبی (۰ تا ۱) نسبت به گوشهٔ بالا-چپ تصویر است.';
            if ($options['include_images']) {
                $lines[] = '  images/       تصویر هر نمونه؛ کلید image در dataset.json به همین‌جا اشاره می‌کند.';
            } else {
                $lines[] = '  (تصویرها در این بسته نیستند؛ کلید image برابر null است و مسیر اصلی';
                $lines[] = '   در کلید source_path آمده است.)';
            }
        } else {
            $lines[] = '  README.txt          همین گزارش';
            $lines[] = '  train/ val/ test/   بر اساس بخش هر نمونه';
            $lines[] = '    <نوع>-<شناسه>-<شماره>-<فیلد>.png     تصویرِ بریده‌شدهٔ همان فیلد (یک خط)';
            $lines[] = '    <نوع>-<شناسه>-<شماره>-<فیلد>.gt.txt  متن درست همان خط، دقیقاً یک خط';
            $lines[] = '    <نوع>-<شناسه>-<شماره>-<فیلد>.box     کادر کاراکترها به فرمت tesseract';
            $lines[] = '';
            $lines[] = '  هر سه فایل هم‌نام‌اند؛ همین چیزی است که tesstrain می‌خواهد: تصویر تک‌خطی';
            $lines[] = '  در کنار gt تک‌خطی. برای آموزش، پوشهٔ train را به GROUND_TRUTH_DIR بدهید';
            $lines[] = '  (یا پوشه‌ها را کنار هم بریزید) و بعد:';
            $lines[] = '      make training MODEL_NAME=hana START_MODEL=fas GROUND_TRUTH_DIR=./train';
            $lines[] = '';
            $lines[] = '  برش با حاشیهٔ '.$fa((int) round(self::LINE_PADDING_RATIO * 100)).'٪ ارتفاع فیلد (دست‌کم ۲ پیکسل) انجام می‌شود.';
            $lines[] = '  فایل .box: «کاراکتر چپ پایین راست بالا صفحه» — مبدأ پایین-چپِ همان برش،';
            $lines[] = '  واحد پیکسل. کادر هر کاراکتر با تقسیم مساوی عرض فیلد به‌دست می‌آید و برای';
            $lines[] = '  متن فارسی از راست به چپ چیده می‌شود؛ پس تقریبی است و برای LSTM لازم نیست.';
            $lines[] = '  فیلدهای بدون کادر و نمونه‌های بدون تصویر در این قالب خروجی ندارند.';
            $lines[] = '  تصویر تمام‌کارت در این بسته نیست؛ برای آن از قالب «JSON یکپارچه» استفاده کنید.';
        }

        $lines[] = '';
        $lines[] = self::retentionNote();
        $lines[] = 'همهٔ داده‌های این بسته ساختگی و خروجی ژنراتور سامانه است.';
        $lines[] = '';

        return implode("\r\n", $lines);
    }

    /** تبدیل میلادی به شمسی برای متن README (الگوریتم استاندارد jdf). */
    public static function jalaliDate(Carbon $moment): string
    {
        [$gy, $gm, $gd] = [(int) $moment->year, (int) $moment->month, (int) $moment->day];

        $gDayOfMonth = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400) + $gd + $gDayOfMonth[$gm - 1];

        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }
}
