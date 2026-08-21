<?php

namespace App\Services\Cases;

use App\Models\DocumentTypeField;
use App\Models\ExtractedField;
use App\Models\PermitCase;
use App\Models\ScoreComponent;
use App\Models\Setting;
use App\Support\PersianValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * امتیازدهی هوشمند پرونده و تصمیم نهایی — مرحلهٔ آخر فلوچارت (تسک ۶۳۴).
 *
 * ورودی این کلاس فقط ردیف‌های دیتابیسِ مرحله‌های قبل است:
 *   extracted_fields   ← تسک ۶۳۲ (کیفیت OCR هر فیلد)
 *   validation_results ← تسک ۶۳۰/۶۳۳ (سه scope: file, document, cross)
 *   case_documents     ← کامل بودن مدارکِ همان نوع خدمت
 * هیچ کلاس دیگری از پایپ‌لاین صدا زده نمی‌شود؛ اگر جدولی خالی باشد مؤلفه با
 * مقدار صفر و یک یادداشت روشن ثبت می‌شود و چیزی کرش نمی‌کند.
 *
 * خروجی: سه ردیف score_components (وزن، مقدار، سهم، «چرا این عدد») به‌علاوهٔ
 * confidence_score و decision و decision_reason و status روی خود پرونده.
 * کارشناس باید فقط با نگاه به این سه ردیف بفهمد امتیاز از کجا آمد.
 *
 * وزن‌ها و آستانه‌ها هاردکد نیستند: از Setting خوانده می‌شوند و ثابت‌های این
 * کلاس فقط «مقدار امنِ پیش‌فرض» هستند برای وقتی هنوز seeder اجرا نشده است.
 */
final class CaseScorer
{
    /** برچسب فارسی هر مؤلفه — صفحهٔ تنظیمات هم از همین می‌خواند. */
    public const COMPONENT_LABELS = [
        'ocr_quality' => 'کیفیت تشخیص متن (OCR)',
        'validation' => 'نتیجهٔ بررسی‌های اعتبارسنجی',
        'completeness' => 'کامل بودن مدارک',
    ];

    /** پیش‌فرض امن وزن‌ها (جمع = ۱۰۰) اگر settings خالی یا خراب باشد. */
    public const DEFAULT_WEIGHTS = [
        'ocr_quality' => 40.0,
        'validation' => 40.0,
        'completeness' => 20.0,
    ];

    /**
     * پیش‌فرض امن آستانه‌ها.
     *
     * cross_fail_rejects: آیا یک بررسیِ ناموفق در scope=cross (ناهمخوانی کد ملی
     * یا نام بین مدارک) به‌تنهایی پرونده را رد کند؟ پیش‌فرض «بله» — دلیلش در
     * توضیح متد decide() آمده. این هم مثل بقیه از تنظیمات قابل تغییر است.
     */
    public const DEFAULT_THRESHOLDS = [
        'approve_at' => 80.0,
        'reject_below' => 45.0,
        'cross_fail_rejects' => true,
    ];

    /**
     * پیش‌فرض امن جریمهٔ هر ایراد اعتبارسنجی (از ۱۰۰).
     *
     * چهار ایراد جدی مؤلفهٔ اعتبارسنجی را صفر می‌کند؛ هشدار یک‌سومِ آن وزن دارد.
     * این دو عدد هم در settings قابل تغییرند (کلید scoring.penalties).
     */
    public const DEFAULT_PENALTIES = [
        'failed' => 25.0,
        'warning' => 8.0,
    ];

    /** نگاشت تصمیم به وضعیت پرونده. */
    private const DECISION_STATUS = [
        'approved' => 'approved',
        'rejected' => 'rejected',
        'needs_review' => 'needs_review',
    ];

    /**
     * امتیاز و تصمیم پرونده را محاسبه و ذخیره می‌کند.
     *
     * idempotent است: اجرای دوباره ردیف تکراری نمی‌سازد، فقط همان سه ردیف را
     * به‌روز می‌کند.
     */
    public function score(PermitCase $case): void
    {
        $case->loadMissing([
            'documents',
            'extractedFields',
            'validationResults',
            'serviceType.documentTypes.fields',
        ]);

        $weights = self::weights();
        $thresholds = self::thresholds();

        $measured = [
            $this->ocrQuality($case),
            $this->validationHealth($case),
            $this->completeness($case),
        ];

        $rows = [];
        $total = 0.0;

        foreach ($measured as $part) {
            $weight = round((float) ($weights[$part['key']] ?? 0.0), 2);
            $value = self::clamp($part['value']);
            $contribution = round($weight * $value / 100, 2);
            $total += $contribution;

            $rows[] = [
                'component_key' => $part['key'],
                'label_fa' => self::COMPONENT_LABELS[$part['key']] ?? $part['key'],
                'weight' => $weight,
                'value' => $value,
                'contribution' => $contribution,
                'note_fa' => $part['note'],
            ];
        }

        $score = self::clamp($total);

        [$decision, $reason] = $this->decide($case, $score, $thresholds, $rows);

        DB::transaction(function () use ($case, $rows, $score, $decision, $reason): void {
            foreach ($rows as $row) {
                ScoreComponent::query()->updateOrCreate(
                    ['case_id' => $case->id, 'component_key' => $row['component_key']],
                    $row,
                );
            }

            // ردیف‌های قدیمیِ مؤلفه‌هایی که دیگر وجود ندارند (مثلاً بعد از تغییر
            // فهرست وزن‌ها) نباید در صفحهٔ نتیجه باقی بمانند.
            ScoreComponent::query()
                ->where('case_id', $case->id)
                ->whereNotIn('component_key', array_column($rows, 'component_key'))
                ->delete();

            $case->confidence_score = $score;
            $case->processed_at = now();

            // تصمیم دستیِ کارشناس (تسک ۶۳۵) نباید با اجرای دوبارهٔ پایپ‌لاین
            // بازنویسی شود؛ امتیاز به‌روز می‌شود ولی تصمیم انسانی سر جایش می‌ماند.
            if (! $case->decision_is_manual) {
                $case->decision = $decision;
                $case->decision_reason = $reason;
                $case->status = self::DECISION_STATUS[$decision];
            }

            $case->save();
        });

        $case->setRelation(
            'scoreComponents',
            ScoreComponent::query()->where('case_id', $case->id)->orderBy('id')->get(),
        );
    }

    /**
     * وزن سه مؤلفه از تنظیمات.
     *
     * اگر جمع وزن‌ها ۱۰۰ نباشد (دادهٔ دستی خراب در دیتابیس) به‌جای کرش، وزن‌ها
     * متناسب مقیاس می‌شوند تا امتیاز نهایی همچنان بین ۰ و ۱۰۰ بماند.
     *
     * @return array<string, float>
     */
    public static function weights(): array
    {
        $stored = self::setting('scoring.weights');
        $weights = [];

        foreach (self::DEFAULT_WEIGHTS as $key => $fallback) {
            $value = is_array($stored) && isset($stored[$key]) && is_numeric($stored[$key])
                ? (float) $stored[$key]
                : null;

            $weights[$key] = $value === null ? $fallback : max(0.0, min(100.0, $value));
        }

        $sum = array_sum($weights);

        if ($sum <= 0.0) {
            return self::DEFAULT_WEIGHTS;
        }

        if (abs($sum - 100.0) > 0.01) {
            foreach ($weights as $key => $value) {
                $weights[$key] = round($value * 100 / $sum, 2);
            }
        }

        return $weights;
    }

    /**
     * آستانه‌های تصمیم از تنظیمات.
     *
     * @return array{approve_at: float, reject_below: float, cross_fail_rejects: bool}
     */
    public static function thresholds(): array
    {
        $stored = self::setting('scoring.thresholds');
        $stored = is_array($stored) ? $stored : [];

        $approve = isset($stored['approve_at']) && is_numeric($stored['approve_at'])
            ? self::clamp((float) $stored['approve_at'])
            : (float) self::DEFAULT_THRESHOLDS['approve_at'];

        $reject = isset($stored['reject_below']) && is_numeric($stored['reject_below'])
            ? self::clamp((float) $stored['reject_below'])
            : (float) self::DEFAULT_THRESHOLDS['reject_below'];

        // دادهٔ خراب نباید بازهٔ «نیاز به بررسی» را وارونه کند.
        $reject = min($reject, $approve);

        $crossVeto = array_key_exists('cross_fail_rejects', $stored)
            ? filter_var($stored['cross_fail_rejects'], FILTER_VALIDATE_BOOLEAN)
            : (bool) self::DEFAULT_THRESHOLDS['cross_fail_rejects'];

        return [
            'approve_at' => $approve,
            'reject_below' => $reject,
            'cross_fail_rejects' => $crossVeto,
        ];
    }

    /**
     * جریمهٔ هر ایراد اعتبارسنجی از تنظیمات.
     *
     * @return array{failed: float, warning: float}
     */
    public static function penalties(): array
    {
        $stored = self::setting('scoring.penalties');
        $stored = is_array($stored) ? $stored : [];

        $read = static fn (string $key): float => isset($stored[$key]) && is_numeric($stored[$key])
            ? max(0.0, min(100.0, (float) $stored[$key]))
            : (float) self::DEFAULT_PENALTIES[$key];

        return [
            'failed' => $read('failed'),
            'warning' => $read('warning'),
        ];
    }

    /**
     * آیا مرحله‌های قبلیِ پایپ‌لاین روی این پرونده اجرا شده‌اند؟
     *
     * لازم است چون «هیچ ایرادی ثبت نشده» با «هنوز هیچ بررسی‌ای اجرا نشده» در
     * قرارداد ValidationResult یک شکل دارند: هر دو یعنی جدول خالی است.
     */
    private function pipelineRan(PermitCase $case): bool
    {
        return $case->extractedFields->isNotEmpty()
            || $case->validationResults->isNotEmpty()
            || $case->documents->contains(
                fn ($document) => in_array($document->ocr_status, ['done', 'failed'], true),
            );
    }

    /**
     * مؤلفهٔ یک: کیفیت OCR.
     *
     * میانگین confidence فیلدهای استخراج‌شده. فیلدی که کارشناس دستی اصلاح کرده
     * (source=manual) قطعی است و ۱۰۰ حساب می‌شود، چون دیگر خروجی ماشین نیست.
     *
     * @return array{key: string, value: float, note: string}
     */
    private function ocrQuality(PermitCase $case): array
    {
        $fields = $case->extractedFields;

        if ($fields->isEmpty()) {
            return [
                'key' => 'ocr_quality',
                'value' => 0.0,
                'note' => 'هنوز هیچ فیلدی از مدارک استخراج نشده است (OCR انجام نشده یا نتیجه‌ای نداشته)؛ '
                    .'این مؤلفه صفر در نظر گرفته شد. با اجرای دوبارهٔ پردازش، امتیاز به‌روز می‌شود.',
            ];
        }

        $confidences = $fields->map(
            fn (ExtractedField $field) => $field->source === 'manual'
                ? 100.0
                : self::clamp((float) $field->confidence),
        );

        $average = round($confidences->avg(), 2);
        $weak = $fields->sortBy(fn (ExtractedField $field) => (float) $field->confidence)->first();
        $labels = self::fieldLabels();

        $note = 'میانگین اطمینان OCR روی '.self::fa($fields->count()).' فیلد استخراج‌شده برابر '
            .PersianValue::decimal($average, 1).' از ۱۰۰ است.';

        $lowCount = $confidences->filter(fn (float $c) => $c < 50)->count();

        if ($lowCount > 0 && $weak !== null) {
            $note .= ' '.self::fa($lowCount).' فیلد زیر ۵۰ خوانده شده؛ ضعیف‌ترین: «'
                .($labels[$weak->field_key] ?? $weak->field_key).'» با '
                .PersianValue::decimal((float) $weak->confidence, 1).'.';
        }

        $manual = $fields->where('source', 'manual')->count();

        if ($manual > 0) {
            $note .= ' '.self::fa($manual).' فیلد را کارشناس دستی اصلاح کرده و قطعی حساب شد.';
        }

        return ['key' => 'ocr_quality', 'value' => $average, 'note' => $note];
    }

    /**
     * مؤلفهٔ دو: سلامت بررسی‌های اعتبارسنجی (هر سه scope: file, document, cross).
     *
     * قرارداد سامانه: در validation_results فقط «ایراد» ثبت می‌شود (failed یا
     * warning) و هرگز ردیف passed نوشته نمی‌شود. پس نسبت پاس به کل قابل محاسبه
     * نیست و به‌جایش از ۱۰۰ شروع می‌کنیم و برای هر ایراد کسر می‌کنیم. مقدار کسر
     * هم مثل بقیهٔ اعداد هاردکد نیست و از settings می‌آید.
     *
     * نبودِ ردیف دو معنی دارد و باید از هم جدا شوند: «همه‌چیز سالم بود» در برابر
     * «هنوز چیزی اجرا نشده». علامتِ اجرا شدن، وجود فیلد استخراج‌شده یا مدرکِ
     * OCR‌شده است. ردیف‌های passed/skipped (اگر روزی نوشته شوند) نه امتیاز
     * می‌دهند نه می‌گیرند.
     *
     * @return array{key: string, value: float, note: string}
     */
    private function validationHealth(PermitCase $case): array
    {
        $issues = $case->validationResults->whereIn('status', ['failed', 'warning']);

        if ($issues->isEmpty() && ! $this->pipelineRan($case)) {
            return [
                'key' => 'validation',
                'value' => 0.0,
                'note' => 'بررسی‌های اعتبارسنجی هنوز روی این پرونده اجرا نشده‌اند (نه فیلدی استخراج '
                    .'شده و نه مدرکی OCR شده)؛ این مؤلفه صفر در نظر گرفته شد. با اجرای پردازش، '
                    .'امتیاز به‌روز می‌شود.',
            ];
        }

        $penalties = self::penalties();

        $failed = $issues->where('status', 'failed')->count();
        $warning = $issues->where('status', 'warning')->count();

        $deduction = ($failed * $penalties['failed']) + ($warning * $penalties['warning']);
        $value = self::clamp(100.0 - $deduction);

        if ($issues->isEmpty()) {
            return [
                'key' => 'validation',
                'value' => 100.0,
                'note' => 'هیچ ایرادی در بررسی‌های اعتبارسنجی ثبت نشده است. (در این سامانه فقط ایراد '
                    .'ثبت می‌شود، پس نبودِ ردیف یعنی همهٔ بررسی‌های فایل، تک‌مدرکی و تطابق بین مدارک '
                    .'سالم گذشته‌اند.)',
            ];
        }

        $note = 'از ۱۰۰ شروع شد؛ '.self::fa($failed).' ایراد جدی (هرکدام منهای '
            .PersianValue::decimal($penalties['failed'], 0).') و '.self::fa($warning).' هشدار (هرکدام منهای '
            .PersianValue::decimal($penalties['warning'], 0).') کسر شد و '
            .PersianValue::decimal($value, 0).' ماند.';

        if ($failed > 0) {
            $note .= ' ایرادهای جدی: '.$this->failureSummary($issues->where('status', 'failed'));
        }

        $crossFailed = $issues->where('status', 'failed')->where('scope', 'cross')->count();

        if ($crossFailed > 0) {
            $note .= ' ('.self::fa($crossFailed).' مورد از نوع ناهمخوانی بین مدارک است.)';
        }

        return ['key' => 'validation', 'value' => $value, 'note' => $note];
    }

    /**
     * مؤلفهٔ سه: کامل بودن مدارک همان نوع خدمت.
     *
     * برای هر مدرکِ لازم:
     *   نیامده                       → ۰
     *   آمده ولی اعتبارسنجی اولیه رد → ۰٫۲۵ (هست ولی قابل استفاده نیست)
     *   آمده                          → ۰٫۵ + نیمِ دیگر به نسبت فیلدهای اجباریِ پرشده
     *
     * @return array{key: string, value: float, note: string}
     */
    private function completeness(PermitCase $case): array
    {
        $service = $case->serviceType;
        $requiredTypes = $service
            ? $service->documentTypes->filter(
                fn ($type) => $type->pivot === null || (bool) $type->pivot->is_required,
            )
            : collect();

        if ($requiredTypes->isEmpty()) {
            return [
                'key' => 'completeness',
                'value' => 0.0,
                'note' => 'فهرست مدارک لازم برای نوع خدمت این پرونده در سامانه تعریف نشده است؛ '
                    .'کامل بودن مدارک قابل سنجش نبود و صفر ثبت شد.',
            ];
        }

        $documents = $case->documents->keyBy('document_type_id');
        $fieldsByDocument = $case->extractedFields->groupBy('case_document_id');

        $parts = [];
        $missing = [];
        $unusable = [];
        $filledFields = 0;
        $expectedFields = 0;

        foreach ($requiredTypes as $type) {
            $document = $documents->get($type->id);

            if ($document === null) {
                $parts[] = 0.0;
                $missing[] = $type->label_fa;

                continue;
            }

            if ($document->precheck_status === 'failed') {
                $parts[] = 0.25;
                $unusable[] = $type->label_fa;

                continue;
            }

            $requiredFieldKeys = $type->fields
                ->filter(fn (DocumentTypeField $field) => (bool) $field->is_required)
                ->pluck('key');

            if ($requiredFieldKeys->isEmpty()) {
                $parts[] = 1.0;

                continue;
            }

            $values = ($fieldsByDocument->get($document->id) ?? collect())
                ->filter(fn (ExtractedField $field) => self::hasValue($field))
                ->pluck('field_key')
                ->unique();

            $filled = $requiredFieldKeys->intersect($values)->count();

            $filledFields += $filled;
            $expectedFields += $requiredFieldKeys->count();

            $parts[] = 0.5 + (0.5 * ($filled / $requiredFieldKeys->count()));
        }

        $value = round((array_sum($parts) / count($parts)) * 100, 2);

        $present = $requiredTypes->count() - count($missing);

        $note = self::fa($present).' مدرک از '.self::fa($requiredTypes->count())
            .' مدرکِ لازمِ «'.($service->label_fa ?? 'خدمت').'» بارگذاری شده است.';

        if ($missing !== []) {
            $note .= ' مدارک نیامده: '.implode('، ', $missing).'.';
        }

        if ($unusable !== []) {
            $note .= ' مدارکی که اعتبارسنجی اولیه را رد کردند: '.implode('، ', $unusable).'.';
        }

        if ($expectedFields > 0) {
            $note .= ' فیلدهای اجباریِ خوانده‌شده: '.self::fa($filledFields).' از '
                .self::fa($expectedFields).'.';
        }

        return ['key' => 'completeness', 'value' => $value, 'note' => $note];
    }

    /**
     * تصمیم نهایی روی امتیاز و آستانه‌ها.
     *
     * چرا ناهمخوانی بین مدارک وتوی مستقل دارد: بررسی scope=cross یعنی «کد ملی
     * یا نام روی دو مدرک یکی نیست». این را نمی‌شود با امتیاز خوبِ بقیهٔ مؤلفه‌ها
     * جبران کرد — برعکس، هرچه اطمینان OCR بالاتر باشد، مغایرت واقعی‌تر است، نه
     * خطای خواندن. پس پیش‌فرض «رد» است. اگر مدیر بخواهد چنین پرونده‌ای فقط به
     * صف بررسی انسانی برود، تیک «رد خودکار» را در تنظیمات برمی‌دارد و آن‌وقت
     * ناهمخوانی فقط از راه مؤلفهٔ اعتبارسنجی امتیاز را پایین می‌آورد.
     *
     * @param  array{approve_at: float, reject_below: float, cross_fail_rejects: bool}  $thresholds
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: string, 1: string}
     */
    private function decide(PermitCase $case, float $score, array $thresholds, array $rows): array
    {
        $scoreText = 'امتیاز اطمینان '.PersianValue::decimal($score, 1).' از ۱۰۰';
        $approveText = PersianValue::decimal($thresholds['approve_at'], 0);
        $rejectText = PersianValue::decimal($thresholds['reject_below'], 0);

        $crossFailures = $case->validationResults
            ->where('scope', 'cross')
            ->where('status', 'failed');

        if ($thresholds['cross_fail_rejects'] && $crossFailures->isNotEmpty()) {
            return ['rejected',
                'ناهمخوانی بین مدارک: '.$this->failureSummary($crossFailures)
                .' چنین مغایرتی با کیفیت خوبِ بقیهٔ مؤلفه‌ها جبران نمی‌شود، پس پرونده مستقل از '
                .$scoreText.' رد شد. متقاضی باید مدارک هم‌خوان ارائه کند. '
                .'(این رفتار در «تنظیمات امتیازدهی» قابل تغییر است.)',
            ];
        }

        if ($score >= $thresholds['approve_at']) {
            return ['approved',
                $scoreText.' به دست آمد که از آستانهٔ تایید ('.$approveText.') کمتر نیست، پس '
                .'پرونده تایید شد. '.$this->driverText($rows),
            ];
        }

        if ($score < $thresholds['reject_below']) {
            return ['rejected',
                $scoreText.' به دست آمد که از آستانهٔ رد ('.$rejectText.') کمتر است، پس پرونده رد شد. '
                .$this->driverText($rows),
            ];
        }

        return ['needs_review',
            $scoreText.' به دست آمد؛ بین آستانهٔ رد ('.$rejectText.') و آستانهٔ تایید ('.$approveText
            .') است، پس تصمیم به کارشناس واگذار شد و ممکن است مدارک بیشتری لازم باشد. '
            .$this->driverText($rows),
        ];
    }

    /** ضعیف‌ترین مؤلفه — همان جمله‌ای که کارشناس اول از همه دنبالش می‌گردد. */
    private function driverText(array $rows): string
    {
        $weakest = collect($rows)->sortBy('value')->first();

        if ($weakest === null) {
            return '';
        }

        return 'اثرگذارترین مؤلفه: «'.$weakest['label_fa'].'» با مقدار '
            .PersianValue::decimal((float) $weakest['value'], 1).' از ۱۰۰.';
    }

    /** خلاصهٔ حداکثر سه بررسی ناموفق، برای یادداشت و دلیل تصمیم. */
    private function failureSummary(Collection $failures): string
    {
        $lines = $failures
            ->take(3)
            ->map(fn ($result) => trim((string) ($result->message_fa ?: $result->rule_key)))
            ->filter()
            ->values()
            ->all();

        $text = implode('؛ ', $lines).'.';

        if ($failures->count() > 3) {
            $text .= ' و '.self::fa($failures->count() - 3).' مورد دیگر.';
        }

        return $text;
    }

    /** آیا این فیلد واقعاً مقداری دارد؟ (نرمال‌شده، وگرنه خام) */
    private static function hasValue(ExtractedField $field): bool
    {
        return trim((string) ($field->normalized_value ?? '')) !== ''
            || trim((string) ($field->raw_value ?? '')) !== '';
    }

    /** برچسب فارسی فیلدها بر پایهٔ کلید — برای خوانا شدن یادداشت‌ها. */
    private static function fieldLabels(): array
    {
        try {
            return DocumentTypeField::query()->pluck('label_fa', 'key')->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** خواندن یک کلید تنظیمات بدون امکان کرش (ممکن است seeder اجرا نشده باشد). */
    private static function setting(string $key): mixed
    {
        try {
            return Setting::get($key);
        } catch (Throwable) {
            return null;
        }
    }

    private static function clamp(float $value): float
    {
        return round(max(0.0, min(100.0, $value)), 2);
    }

    private static function fa(string|int|float $value): string
    {
        return PersianValue::toPersianDigits((string) $value);
    }
}
