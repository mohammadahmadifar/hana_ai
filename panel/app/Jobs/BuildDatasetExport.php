<?php

namespace App\Jobs;

use App\Support\DatasetExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ساخت بستهٔ zip خروجی دیتاست روی صف.
 *
 * چون یک بسته می‌تواند چند صد فایل داشته باشد، ساختش هرگز داخل درخواست وب
 * انجام نمی‌شود. وضعیت کار در فایل هم‌نام zip روی دیسک «exports» نگه‌داری
 * می‌شود؛ جدول جدیدی در کار نیست.
 */
class BuildDatasetExport implements ShouldQueue
{
    use Queueable;

    /** یک بار تلاش: خطا در فایل وضعیت ثبت می‌شود تا کاربر ببیند. */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $token)
    {
        $this->onQueue('default');
    }

    public function handle(DatasetExporter $exporter): void
    {
        if (! DatasetExporter::isValidToken($this->token)) {
            Log::warning('خروجی دیتاست: شناسهٔ نامعتبر به صف رسید.', ['token' => $this->token]);

            return;
        }

        $exporter->build($this->token);
    }

    /** اگر کار به هر دلیلی شکست خورد (مثلاً timeout) وضعیت «ناموفق» ثبت شود. */
    public function failed(?Throwable $exception): void
    {
        $meta = DatasetExporter::readMeta($this->token);

        if ($meta === null) {
            return;
        }

        $meta['status'] = 'failed';
        $meta['finished_at'] = now()->toIso8601String();
        $meta['error'] = $exception?->getMessage() ?: 'ساخت خروجی ناتمام ماند.';

        DatasetExporter::writeMeta($this->token, $meta);
    }
}
