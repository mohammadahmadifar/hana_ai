<?php

namespace App\Http\Controllers\Cases;

use App\Http\Controllers\Controller;
use App\Models\CaseDocument;
use App\Models\PermitCase;
use App\Services\Cases\CasePipeline;
use App\Services\Cases\DocumentOcr;
use App\Support\PersianValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * وضعیت زندهٔ پردازش یک پرونده (JSON).
 *
 * صفحهٔ نتیجهٔ پرونده بعد از ثبت، تا وقتی پردازش تمام نشده هر چند ثانیه این
 * اندپوینت را می‌خواند و «در حال پردازش» را به‌روز نگه می‌دارد؛ همان الگویی
 * که صفحهٔ پیشرفت تولید دیتاست دارد.
 *
 * این‌جا هیچ کاری شروع نمی‌شود — فقط گزارش وضعیت است.
 */
class CaseProcessingController extends Controller
{
    public function status(Request $request, PermitCase $case): JsonResponse
    {
        abort_unless(
            $request->user()->isAdmin() || $case->user_id === $request->user()->id,
            403,
            'این پرونده متعلق به کاربر دیگری است و شما اجازهٔ دیدنش را ندارید.',
        );

        $case->load(['documents.documentType', 'documents.latestOcrRun']);

        $documents = $case->documents->map(
            fn (CaseDocument $document): array => $this->documentRow($document),
        )->values()->all();

        $total = count($documents);
        $done = count(array_filter($documents, static fn (array $row): bool => $row['ocr_status'] === 'done'));
        $failed = count(array_filter($documents, static fn (array $row): bool => $row['ocr_status'] === 'failed'));

        $processing = in_array($case->status, CasePipeline::ACTIVE_STATUSES, true);

        return response()->json([
            'case_id' => (int) $case->id,
            'code' => (string) $case->code,

            // وضعیت پرونده
            'status' => (string) $case->status,
            'status_label' => $case->statusLabel(),
            'processing' => $processing,
            'finished' => ! $processing,

            // نتیجه (تا وقتی پردازش تمام نشده null است)
            'decision' => $case->decision,
            'decision_label' => $case->decision ? (PermitCase::DECISIONS[$case->decision] ?? $case->decision) : null,
            'decision_reason' => $case->decision_reason,
            'confidence_score' => $case->confidence_score === null ? null : (float) $case->confidence_score,
            'processing_ms' => $case->processing_ms === null ? null : (int) $case->processing_ms,
            'processed_at' => $case->processed_at?->toIso8601String(),

            // پیشرفت متن‌خوانی
            'counts' => [
                'total' => $total,
                'done' => $done,
                'failed' => $failed,
                'waiting' => max(0, $total - $done - $failed),
            ],
            'percent' => $total === 0 ? 0 : (int) round(($done + $failed) * 100 / $total),
            'documents' => $documents,

            // یک جملهٔ آمادهٔ نمایش، تا هر صفحه‌ای متن خودش را نسازد
            'message_fa' => $this->message($case, $total, $done, $failed, $processing),
        ]);
    }

    /** @return array<string, mixed> */
    private function documentRow(CaseDocument $document): array
    {
        $run = $document->latestOcrRun;
        $rawText = (string) ($run->raw_text ?? '');
        $extra = is_array($run?->extra) ? $run->extra : [];

        return [
            'id' => (int) $document->id,
            'type_key' => $document->documentType?->key,
            'label_fa' => $document->documentType?->label_fa ?? 'مدرک',
            'precheck_status' => (string) $document->precheck_status,
            'ocr_status' => (string) $document->ocr_status,
            'ocr_status_label' => DocumentOcr::STATUS_LABELS[$document->ocr_status] ?? 'نامشخص',
            'has_text' => trim($rawText) !== '',
            'char_count' => mb_strlen($rawText),
            'duration_ms' => $run?->duration_ms === null ? null : (int) $run->duration_ms,
            'engine_version' => $run?->engine_version,
            'error' => $run?->error,
            // مسیر ویژهٔ کارت خودرو؛ برای بقیهٔ مدارک null است.
            'vin' => $extra['vin'] ?? null,
            'plate' => $extra['plate'] ?? null,
        ];
    }

    private function message(PermitCase $case, int $total, int $done, int $failed, bool $processing): string
    {
        if ($processing) {
            return 'پرونده در حال پردازش است؛ متن‌خوانی '
                .PersianValue::toPersianDigits((string) ($done + $failed)).' مدرک از '
                .PersianValue::toPersianDigits((string) $total).' مدرک انجام شده است. '
                .'این صفحه خودش به‌روز می‌شود.';
        }

        if ($failed > 0 && $done === 0) {
            return 'متن هیچ‌کدام از مدارک خوانده نشد؛ نتیجهٔ پرونده «'
                .$case->statusLabel().'» ثبت شد و به بررسی کارشناس نیاز دارد.';
        }

        $suffix = $failed > 0
            ? ' (متن '.PersianValue::toPersianDigits((string) $failed).' مدرک خوانده نشد)'
            : '';

        return 'پردازش پرونده تمام شد؛ وضعیت فعلی «'.$case->statusLabel().'» است'.$suffix.'.';
    }
}
