<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک اجرای «ارزیابی دقت» (تسک ۷۲۶).
 *
 * درصدها عمداً ستون ندارند و همیشه از شمارش خام ساخته می‌شوند: یک دسته ممکن
 * است در چند Job پشت سر هم ادامه پیدا کند و درصدِ ذخیره‌شده بین دو تکه بیات
 * می‌شود، در حالی که `fields_correct / fields_total` هر لحظه درست است.
 */
class EvaluationRun extends Model
{
    /**
     * فقط سه چیزی که کاربر واقعاً انتخاب می‌کند.
     *
     * بقیهٔ ستون‌ها — مالک، وضعیت، شمارنده‌ها و نتیجه — را Job با `forceFill`
     * می‌نویسد که اصلاً از این فهرست رد نمی‌شود. باز گذاشتنشان این‌جا فقط
     * منتظر روزی می‌ماند که کسی `create($request->all())` بنویسد و کاربر
     * بتواند مالکیت و نتیجهٔ ارزیابی را خودش تعیین کند.
     */
    protected $fillable = [
        'count_requested', 'document_type_ids', 'augment_mode',
    ];

    protected $casts = [
        'document_type_ids' => 'array',
        'breakdown' => 'array',
        'misses' => 'array',
        'previews' => 'array',
        'confidence_sum' => 'decimal:2',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** چند تصویر از دستهٔ خواسته‌شده پردازش شده‌اند (موفق یا ناموفق). */
    public function processed(): int
    {
        return (int) $this->count_done + (int) $this->count_failed;
    }

    public function progressPercent(): int
    {
        if ((int) $this->count_requested < 1) {
            return 0;
        }

        return (int) min(100, round($this->processed() / (int) $this->count_requested * 100));
    }

    /** دقت کل: درصد فیلدهایی که دقیقاً همان چیزی خوانده شدند که چاپ شده بود. */
    public function accuracy(): ?float
    {
        return (int) $this->fields_total > 0
            ? round(100 * (int) $this->fields_correct / (int) $this->fields_total, 1)
            : null;
    }

    /** میانگین اطمینان خواندن روی همان فیلدهایی که سنجیده شده‌اند. */
    public function averageConfidence(): ?float
    {
        return (int) $this->fields_total > 0
            ? round((float) $this->confidence_sum / (int) $this->fields_total, 1)
            : null;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['done', 'failed'], true);
    }
}
