<?php

namespace App\Jobs;

use App\Models\PermitCase;
use App\Services\Cases\CasePipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * پردازش یک پروندهٔ مجوز روی صف: OCR → استخراج فیلد → اعتبارسنجی → امتیازدهی.
 *
 * چرا صف: هر مدرک یک تماس با موتور پایتون است و هر تماس یک پروسهٔ تازه
 * (حدود ۱۷۰ میلی‌ثانیه فقط راه‌اندازی، به‌علاوهٔ چند ثانیه خودِ OCR). یک
 * پروندهٔ تمدید با چهار مدرک به‌راحتی چند ده ثانیه می‌شود؛ چنین کاری هرگز
 * داخل چرخهٔ درخواست وب نمی‌رود (قانون طلایی پروژه).
 *
 * صف `ocr` عمدی است: کارگر با `--queue=ocr,generate,default` بالاست، پس
 * پروندهٔ کاربر پشت یک دستهٔ هزارتاییِ تولید دیتاست گیر نمی‌کند.
 *
 * زمان‌ها: کارگر با `--timeout=600` اجرا می‌شود و `retry_after` صف redis
 * برابر ۱۲۰۰ ثانیه است؛ پس timeout این Job زیر هر دو نگه داشته شده تا
 * نه کارگر وسط کار قطعش کند و نه صف نسخهٔ دومی از همان Job تحویل بدهد.
 */
class ProcessCase implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** نام صف — کنترلر و تست هر دو از همین می‌خوانند، نه از رشتهٔ تکراری. */
    public const QUEUE = 'ocr';

    /**
     * دو تلاش: پایپ‌لاین idempotent است، پس تلاش دوم داده را دوتا نمی‌کند و
     * یک خطای گذرای موتور (مثلاً کمبود موقت حافظه) پرونده را نمی‌سوزاند.
     */
    public int $tries = 2;

    /** فاصلهٔ تلاش دوم (ثانیه) — موتور فرصت نفس‌کشیدن داشته باشد. */
    public int $backoff = 20;

    /** سقف زمان یک اجرا؛ زیر ۶۰۰ ثانیهٔ کارگر. */
    public int $timeout = 540;

    public function __construct(public int $caseId)
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * تنها راه درستِ شروع پردازش: پرونده «در حال پردازش» علامت می‌خورد،
     * مدارکش «در صف»، و بعد کار به صف سپرده می‌شود.
     *
     * نامش عمداً `queue` نیست: لاراول اگر روی یک Job متد `queue()` ببیند آن را
     * «قلاب صف‌گذاری سفارشی» فرض می‌کند و هنگام dispatch خودش صدایش می‌زند.
     */
    public static function start(PermitCase $case): void
    {
        CasePipeline::markQueued($case);

        self::dispatch($case->id);
    }

    public function handle(CasePipeline $pipeline): void
    {
        $case = PermitCase::query()->find($this->caseId);

        if ($case === null) {
            Log::warning('پردازش پرونده: پرونده پیدا نشد و کار رها شد.', ['case_id' => $this->caseId]);

            return;
        }

        $pipeline->run($case);
    }

    /**
     * حتی اگر Job به timeout بخورد یا پروسه کرش کند، پرونده نباید در وضعیت
     * «در حال پردازش» گیر بماند: به «نیاز به بررسی» می‌رود و مدارک نیمه‌کاره
     * «ناموفق» علامت می‌خورند.
     */
    public function failed(?Throwable $exception): void
    {
        $case = PermitCase::query()->find($this->caseId);

        if ($case === null) {
            return;
        }

        Log::error('پردازش پرونده شکست خورد.', [
            'case_id' => $this->caseId,
            'exception' => $exception?->getMessage(),
        ]);

        CasePipeline::markStalled(
            $case,
            'پردازش خودکار پرونده ناتمام ماند (خطای سامانه)؛ لطفاً کارشناس آن را دستی بررسی کند.',
        );
    }
}
