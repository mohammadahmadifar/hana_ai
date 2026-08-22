<?php

namespace App\Console\Commands;

use App\Support\PersianValue;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * پاک‌سازی تصویرهای موقتی که موتور پشت سرش جا می‌گذارد.
 *
 * هر تماس `ocr_document` یک PNG پیش‌پردازش‌شده می‌نویسد تا Tesseract و مسیر
 * ویژهٔ کارت خودرو بتوانند از روی **مسیر** بخوانندش (هیچ‌کدام آرایهٔ تصویر
 * نمی‌گیرند). تا امروز هیچ‌چیز آن‌ها را پاک نمی‌کرد: نه زمان‌بندی‌ای بود نه
 * دستوری، و پوشه تا ۸۴ مگابایت بالا آمده بود.
 *
 * این فقط مسئلهٔ دیسک نیست — محتوای آن فایل‌ها تصویر کارت ملی و گواهینامهٔ
 * کاربران است. نگه‌داشتن بی‌پایانِ نسخهٔ دومِ یک مدرک هویتی، بیرون از مسیر
 * محافظت‌شدهٔ `documents`، خودش یک ریسک است (قانون ۱۲۹).
 *
 * ### چه چیزی پاک می‌شود و چه چیزی نه
 * فقط پوشه‌های «خروجی موقت» فهرست‌شده در `config/hana.php` کلید `prune_roots`.
 * مدارک پرونده (`storage/app/private/documents`)، دیتاست، تصویرهای تستیِ خودِ
 * کاربر و بسته‌های خروجی هرگز در آن فهرست نیستند و این دستور به آن‌ها کاری
 * ندارد — تستش هم دقیقاً همین را تضمین می‌کند.
 *
 * ### چرا سن‌محور و نه «همه را پاک کن»
 * فایلِ همین لحظه ممکن است وسط یک اجرای OCR باشد. سن پیش‌فرض هفت روز است تا
 * اگر کارشناسی داشت روی نتیجهٔ یک پرونده کار می‌کرد، تصویر پیش‌پردازش‌شده‌اش
 * زیر پایش کشیده نشود.
 */
class PruneEngineFiles extends Command
{
    protected $signature = 'hana:prune-engine-files
                            {--days= : فایل‌های قدیمی‌تر از این تعداد روز پاک شوند (پیش‌فرض از config/hana.php)}
                            {--dry-run : فقط گزارش بده، چیزی پاک نکن}';

    protected $description = 'پاک‌سازی تصویرهای موقتی موتور (پیش‌پردازش، بررسی سلامت، پیش‌نمایش تصویر تستی)';

    public function handle(): int
    {
        // ریشه و فهرست پوشه‌ها از config می‌آید نه ثابتِ کلاس، تا تست بتواند
        // دستور را روی یک پوشهٔ موقت اجرا کند. تستی که با days=0 روی storage
        // واقعی اجرا شود، خروجی موتور همان لحظه را هم می‌برد.
        $base = rtrim((string) config('hana.prune_base', storage_path('app/private')), '/');
        $roots = (array) config('hana.prune_roots', []);

        $days = max(0, (int) ($this->option('days') ?? config('hana.prune_days', 7)));
        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days)->getTimestamp();

        $deleted = 0;
        $bytes = 0;
        $kept = 0;

        foreach ($roots as $relative) {
            $root = $base.'/'.trim((string) $relative, '/');

            if (! is_dir($root)) {
                continue;
            }

            foreach ($this->files($root) as $file) {
                if ($file->getMTime() >= $cutoff) {
                    $kept++;

                    continue;
                }

                $size = (int) $file->getSize();

                if ($dry || @unlink($file->getPathname())) {
                    $deleted++;
                    $bytes += $size;
                }
            }

            if (! $dry) {
                $this->removeEmptyDirectories($root);
            }
        }

        $this->components->info(sprintf(
            '%s%s فایل قدیمی‌تر از %s روز، %s آزاد شد. %s فایل تازه دست‌نخورده ماند.',
            $dry ? '[فقط گزارش] ' : '',
            PersianValue::toPersianDigits((string) $deleted),
            PersianValue::toPersianDigits((string) $days),
            $this->humanBytes($bytes),
            PersianValue::toPersianDigits((string) $kept),
        ));

        return self::SUCCESS;
    }

    /**
     * همهٔ فایل‌های زیر یک پوشه، بدون دنبال‌کردن symlink.
     *
     * @return iterable<SplFileInfo>
     */
    private function files(string $root): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && ! $file->isLink()) {
                yield $file;
            }
        }
    }

    /** پوشه‌های خالیِ باقی‌مانده؛ خودِ ریشه دست‌نخورده می‌ماند. */
    private function removeEmptyDirectories(string $root): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isDir() && ! $entry->isLink()) {
                @rmdir($entry->getPathname());
            }
        }
    }

    private function humanBytes(int $bytes): string
    {
        $megabytes = $bytes / (1024 * 1024);

        return $megabytes >= 1
            ? PersianValue::decimal($megabytes, 1).' مگابایت'
            : PersianValue::toPersianDigits((string) (int) round($bytes / 1024)).' کیلوبایت';
    }
}
