<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PermitCase extends Model
{
    /** @use HasFactory<\Database\Factories\PermitCaseFactory> */
    use HasFactory;

    protected $table = 'cases';

    protected $fillable = [
        'code', 'user_id', 'service_type_id', 'applicant_name', 'applicant_national_id',
        'status', 'confidence_score', 'decision', 'decision_reason', 'decision_is_manual',
        'decided_by', 'decided_at', 'submitted_at', 'processed_at', 'processing_ms',
    ];

    protected $casts = [
        'confidence_score' => 'decimal:2',
        'decision_is_manual' => 'boolean',
        'decided_at' => 'datetime',
        'submitted_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public const STATUSES = [
        'draft' => 'پیش‌نویس',
        'submitted' => 'ثبت‌شده',
        'processing' => 'در حال پردازش',
        'needs_review' => 'نیاز به بررسی',
        'approved' => 'تایید',
        'rejected' => 'رد',
    ];

    public const DECISIONS = [
        'approved' => 'تایید پرونده',
        'rejected' => 'رد پرونده',
        'needs_review' => 'نیاز به بررسی / مدارک بیشتر',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CaseDocument::class, 'case_id');
    }

    public function extractedFields(): HasMany
    {
        return $this->hasMany(ExtractedField::class, 'case_id');
    }

    public function validationResults(): HasMany
    {
        return $this->hasMany(ValidationResult::class, 'case_id');
    }

    public function scoreComponents(): HasMany
    {
        return $this->hasMany(ScoreComponent::class, 'case_id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
