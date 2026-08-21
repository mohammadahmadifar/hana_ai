<?php

namespace App\Services\Cases;

use App\Models\CaseDocument;
use App\Models\OcrRun;
use App\Models\PermitCase;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * زنجیره‌بندِ فرایند مجوز — پنج مرحلهٔ فلوچارت را پشت سر هم اجرا می‌کند.
 *
 * ```
 * برای هر مدرک:      DocumentOcr::run()  →  FieldExtractor::extract()
 * بعد از همهٔ مدارک:  DocumentValidator::validate()  →  CaseScorer::score()
 * ```
 *
 * اعتبارسنجی اولیهٔ فایل (DocumentPrecheck) این‌جا نیست: آن هنگام آپلود و در
 * چرخهٔ درخواست اجرا شده، چون ارزان است و کاربر باید همان لحظه بفهمد عکسش تار
 * است. این‌جا فقط نتیجه‌اش خوانده می‌شود.
 *
 * ### چرا این کلاس اینقدر try/catch دارد
 * بدترین حالتِ ممکن برای کاربر «پروندهٔ گیرکرده در حال پردازش» است: نه نتیجه‌ای
 * دارد نه خطایی. پس هر مرحله جداگانه محافظت می‌شود — یک مدرکِ خراب بقیهٔ مدارک
 * را زمین نمی‌زند، و اگر امتیازدهی هم بشکند پرونده دست‌کم به «نیاز به بررسی»
 * می‌رسد تا کارشناس ببیندش.
 *
 * ### idempotent بودن
 * اجرای دوباره روی همان پرونده داده را دوتا نمی‌کند: هر مرحله ردیف خودش را
 * به‌روز می‌کند (یک `ocr_runs` برای هر مدرک، `updateOrCreate` در استخراج فیلد و
 * امتیازدهی، پاک‌سازی scope در اعتبارسنجی). اگر کارشناس تصمیم دستی گرفته باشد
 * (`decision_is_manual`) نه این‌جا و نه CaseScorer آن را بازنویسی نمی‌کنند —
 * حتی وضعیت «در حال پردازش» هم روی چنین پرونده‌ای نوشته نمی‌شود.
 *
 * ### زمان‌سنجی
 * `processed_at` و `processing_ms` را همین کلاس می‌نویسد (بعد از CaseScorer)،
 * چون تنها جایی است که «کل پایپ‌لاین» را می‌بیند. CaseScorer عمداً فقط
 * `processed_at` را می‌گذارد و مدت را نمی‌داند.
 */
final class CasePipeline
{
    /** وضعیت‌هایی که یعنی «پرونده هنوز روی میز پردازش است». */
    public const ACTIVE_STATUSES = ['submitted', 'processing'];

    public function __construct(
        private readonly DocumentOcr $ocr,
        private readonly FieldExtractor $extractor,
        private readonly DocumentValidator $validator,
        private readonly CaseScorer $scorer,
    ) {}

    /**
     * اجرای کامل زنجیره روی یک پرونده.
     *
     * @return array{
     *     documents: int, ocr_done: int, ocr_failed: int, fields: int,
     *     duration_ms: int, status: string
     * }
     */
    public function run(PermitCase $case): array
    {
        $startedAt = microtime(true);

        self::markProcessing($case);

        $case->load(['documents.documentType']);

        $ocrDone = 0;
        $ocrFailed = 0;
        $fields = 0;

        foreach ($case->documents as $document) {
            $run = $this->ocrOne($document);

            if ($run === null || $run->status !== 'done') {
                $ocrFailed++;

                continue;
            }

            $ocrDone++;
            $fields += $this->extractOne($document, $run);
        }

        $this->step('اعتبارسنجی اسناد', $case, fn () => $this->validator->validate($case));

        $scored = $this->step('امتیازدهی', $case, fn () => $this->scorer->score($case));

        if (! $scored) {
            $this->markStalled(
                $case,
                'محاسبهٔ امتیاز اطمینان این پرونده ناتمام ماند؛ برای تصمیم‌گیری به بررسی کارشناس نیاز است.',
            );
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        // CaseScorer وضعیت و تصمیم را ست کرده است؛ این‌جا فقط زمان‌سنجی نوشته
        // می‌شود و چون فقط همین دو ستون dirty هستند، تصمیم دست‌نخورده می‌ماند.
        $case->forceFill([
            'processed_at' => now(),
            'processing_ms' => $durationMs,
        ])->save();

        Log::info('پایپ‌لاین پرونده تمام شد.', [
            'case_id' => $case->id,
            'ocr_done' => $ocrDone,
            'ocr_failed' => $ocrFailed,
            'fields' => $fields,
            'duration_ms' => $durationMs,
            'status' => $case->status,
        ]);

        return [
            'documents' => $case->documents->count(),
            'ocr_done' => $ocrDone,
            'ocr_failed' => $ocrFailed,
            'fields' => $fields,
            'duration_ms' => $durationMs,
            'status' => (string) $case->status,
        ];
    }

    // ------------------------------------------------------------------
    // نشانه‌گذاری وضعیت — از Job هم صدا زده می‌شود
    // ------------------------------------------------------------------

    /**
     * «پرونده به صف رفت»: وضعیت پرونده «در حال پردازش» و مدارکش «در صف».
     *
     * هنگام dispatch صدا زده می‌شود تا کاربر بلافاصله بعد از ثبت، صفحهٔ
     * «در حال پردازش» را ببیند و منتظر بیدارشدن کارگر صف نماند.
     */
    public static function markQueued(PermitCase $case): void
    {
        self::markProcessing($case);

        CaseDocument::query()
            ->where('case_id', $case->id)
            ->whereIn('ocr_status', ['pending', 'done', 'failed'])
            ->update(['ocr_status' => 'queued']);
    }

    /** وضعیت پرونده را «در حال پردازش» می‌کند مگر تصمیمش دستی باشد. */
    public static function markProcessing(PermitCase $case): void
    {
        if ($case->decision_is_manual || $case->status === 'processing') {
            return;
        }

        $case->forceFill(['status' => 'processing'])->save();
    }

    /**
     * پاک‌سازی پروندهٔ نیمه‌کاره — از `ProcessCase::failed()` هم صدا زده می‌شود.
     *
     * قاعده: پرونده هرگز نباید در «در حال پردازش» بماند. اگر پردازش شکست،
     * پرونده به «نیاز به بررسی» می‌رود تا کارشناس ببیندش، و هر مدرکی که
     * وسط راه مانده «ناموفق» علامت می‌خورد.
     */
    public static function markStalled(PermitCase $case, ?string $reason = null): void
    {
        $reason = trim((string) $reason) !== ''
            ? (string) $reason
            : 'پردازش خودکار این پرونده ناتمام ماند؛ لطفاً کارشناس آن را دستی بررسی کند.';

        $stuck = CaseDocument::query()
            ->where('case_id', $case->id)
            ->whereIn('ocr_status', ['pending', 'queued', 'running'])
            ->get();

        foreach ($stuck as $document) {
            $document->forceFill(['ocr_status' => 'failed'])->save();

            OcrRun::query()->updateOrCreate(
                ['subject_type' => $document->getMorphClass(), 'subject_id' => $document->getKey()],
                ['status' => 'failed', 'error' => $reason],
            );
        }

        $case->refresh();

        if ($case->decision_is_manual || ! in_array($case->status, self::ACTIVE_STATUSES, true)) {
            // تصمیم انسانی یا نتیجهٔ نهاییِ رسیده دست نمی‌خورد.
            $case->forceFill(['processed_at' => $case->processed_at ?? now()])->save();

            return;
        }

        $case->forceFill([
            'status' => 'needs_review',
            'decision' => 'needs_review',
            'decision_reason' => mb_substr($reason, 0, 500),
            'processed_at' => now(),
        ])->save();
    }

    // ------------------------------------------------------------------
    // مرحله‌ها
    // ------------------------------------------------------------------

    private function ocrOne(CaseDocument $document): ?OcrRun
    {
        try {
            return $this->ocr->run($document);
        } catch (Throwable $exception) {
            // DocumentOcr خودش استثنا نمی‌دهد؛ این تور ایمنی برای خطای
            // پیش‌بینی‌نشدهٔ دیتابیس است تا بقیهٔ مدارک پردازش شوند.
            Log::error('مرحلهٔ OCR یک مدرک شکست.', [
                'case_document_id' => $document->id,
                'exception' => $exception::class.': '.$exception->getMessage(),
            ]);

            $document->forceFill(['ocr_status' => 'failed'])->save();

            return null;
        }
    }

    private function extractOne(CaseDocument $document, OcrRun $run): int
    {
        try {
            return $this->extractor->extract($document, $run);
        } catch (Throwable $exception) {
            Log::error('مرحلهٔ استخراج فیلد یک مدرک شکست.', [
                'case_document_id' => $document->id,
                'exception' => $exception::class.': '.$exception->getMessage(),
            ]);

            return 0;
        }
    }

    /** اجرای یک مرحلهٔ کل‌پرونده‌ای با لاگ خطا. true یعنی بدون استثنا تمام شد. */
    private function step(string $label, PermitCase $case, callable $callback): bool
    {
        try {
            $callback();

            return true;
        } catch (Throwable $exception) {
            Log::error('مرحلهٔ «'.$label.'» پرونده شکست.', [
                'case_id' => $case->id,
                'exception' => $exception::class.': '.$exception->getMessage(),
            ]);

            return false;
        }
    }
}
