<?php

namespace App\Models;

use App\Support\Jalali;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use RuntimeException;

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

    /** بیشترین تلاش برای ساختن کد یکتای پرونده پیش از تسلیم‌شدن. */
    private const CODE_ATTEMPTS = 10;

    // ==================================================================
    // ساخت پروندهٔ پیش‌نویس
    // ==================================================================

    /**
     * یک پروندهٔ «پیش‌نویس» تازه با کد یکتا باز می‌کند.
     *
     * چرا روی مدل و نه داخل کنترلر: دو نقطه پرونده باز می‌کنند — ویزارد
     * درخواست خدمت (CaseController) و دکمهٔ «بفرست به فرایند بررسی» در صفحهٔ
     * تصویر تستی (CaseFromTestImageController). اگر الگوی کد دو جا نوشته شود،
     * روزی که عوض شود یکی‌شان جا می‌ماند و کد تکراری می‌سازد.
     *
     * تکراری‌شدن کد در شرایط مسابقه ممکن است (دو درخواست هم‌زمان بیشینهٔ یکسانی
     * می‌بینند)، پس روی خطای نقض یکتایی دوباره تلاش می‌شود؛ از تلاش چهارم به بعد
     * شماره تصادفی می‌شود تا دو درخواستِ گیرکرده روی هم قفل نمانند.
     */
    public static function openDraft(
        int $userId,
        int $serviceTypeId,
        ?string $applicantName = null,
        ?string $applicantNationalId = null,
    ): self {
        $year = self::currentJalaliYear();

        for ($attempt = 1; $attempt <= self::CODE_ATTEMPTS; $attempt++) {
            try {
                return self::create([
                    'code' => self::candidateCode($year, $attempt),
                    'user_id' => $userId,
                    'service_type_id' => $serviceTypeId,
                    'applicant_name' => $applicantName,
                    'applicant_national_id' => $applicantNationalId,
                    'status' => 'draft',
                ]);
            } catch (QueryException $exception) {
                if ($attempt === self::CODE_ATTEMPTS || ! self::isDuplicateKey($exception)) {
                    throw $exception;
                }
            }
        }

        // عملاً دست‌نیافتنی؛ فقط برای اینکه تحلیل ایستا خروجی قطعی ببیند.
        throw new RuntimeException('ساخت کد یکتای پرونده ممکن نشد.');
    }

    /** الگوی کد: HA-{سال شمسی}-{شش رقم} */
    private static function candidateCode(int $year, int $attempt): string
    {
        $prefix = 'HA-'.$year.'-';

        if ($attempt <= 3) {
            // کدها صفرِ ابتدایی دارند، پس بزرگ‌ترین رشته همان بزرگ‌ترین عدد است.
            $last = self::query()
                ->where('code', 'like', $prefix.'%')
                ->max('code');

            $serial = is_string($last)
                ? ((int) mb_substr($last, mb_strlen($prefix))) + 1
                : 1;
        } else {
            $serial = random_int(1, 999_999);
        }

        $serial = max(1, min($serial, 999_999));

        return $prefix.str_pad((string) $serial, 6, '0', STR_PAD_LEFT);
    }

    private static function currentJalaliYear(): int
    {
        $now = now(config('panel_menu.timezone', config('app.timezone')));

        return Jalali::fromGregorian((int) $now->year, (int) $now->month, (int) $now->day)[0];
    }

    /** آیا این خطا نقض قید یکتایی است (نه یک خرابی واقعی دیتابیس)؟ */
    private static function isDuplicateKey(QueryException $exception): bool
    {
        return (string) $exception->getCode() === '23000';
    }

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
