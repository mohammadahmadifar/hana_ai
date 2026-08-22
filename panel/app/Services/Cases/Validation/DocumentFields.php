<?php

namespace App\Services\Cases\Validation;

use App\Models\CaseDocument;
use App\Models\DocumentType;
use App\Models\DocumentTypeField;
use App\Models\ExtractedField;
use Illuminate\Support\Collection;

/**
 * نمای «یک مدرک پرونده» برای اعتبارسنجی.
 *
 * سه چیز را کنار هم می‌گذارد که در سه جدول پخش‌اند:
 *   ۱) تعریف نوع مدرک و فیلدهایش (document_type_fields — اجباری/تطابقی/نوع مقدار)
 *   ۲) خودِ مدرک بارگذاری‌شده (case_documents) — که ممکن است اصلاً نیامده باشد
 *   ۳) مقادیر خوانده‌شده (extracted_fields)
 *
 * هیچ قضاوتی این‌جا انجام نمی‌شود؛ فقط «چه داریم». تصمیم پاس/رد/مشکوک کار
 * DocumentValidator است.
 */
final class DocumentFields
{
    /**
     * @param  array<string, DocumentTypeField>  $fields  تعریف فیلدها بر پایهٔ کلید
     * @param  array<string, FieldValue>  $values  فقط مقادیر ناخالی
     */
    private function __construct(
        public readonly DocumentType $type,
        public readonly ?CaseDocument $document,
        private readonly array $fields,
        private readonly array $values,
        private readonly int $extractedRows,
    ) {}

    /**
     * @param  Collection<int, ExtractedField>  $extracted  ردیف‌های همین مدرک
     */
    public static function make(DocumentType $type, ?CaseDocument $document, Collection $extracted): self
    {
        /** @var array<string, DocumentTypeField> $fields */
        $fields = [];

        foreach ($type->fields as $field) {
            $fields[$field->key] = $field;
        }

        // اگر برای یک فیلد چند ردیف باشد (اصلاح دستی کارشناس روی خوانده‌شدهٔ OCR)،
        // دست‌نویس کارشناس مقدم است، بعد اطمینان بالاتر، بعد تازه‌ترین ردیف.
        $ordered = $extracted->sortByDesc(static fn ($row): array => [
            $row->source === 'manual' ? 1 : 0,
            (float) $row->confidence,
            (int) $row->id,
        ]);

        /** @var array<string, FieldValue> $values */
        $values = [];

        foreach ($ordered as $row) {
            $field = $fields[$row->field_key] ?? null;

            if ($field === null || isset($values[$field->key])) {
                continue; // فیلدی که در تعریف نوع مدرک نیست، به اعتبارسنجی وارد نمی‌شود
            }

            $value = FieldValue::make(
                $type->key,
                $type->label_fa,
                $document?->id,
                $field->key,
                $field->label_fa,
                $field->value_type,
                $row->normalized_value ?? $row->raw_value,
                (float) $row->confidence,
                (string) $row->source,
            );

            if (! $value->isEmpty()) {
                $values[$field->key] = $value;
            }
        }

        return new self($type, $document, $fields, $values, $extracted->count());
    }

    public function key(): string
    {
        return $this->type->key;
    }

    public function label(): string
    {
        return (string) $this->type->label_fa;
    }

    public function isUploaded(): bool
    {
        return $this->document !== null;
    }

    /** آیا اصلاً چیزی از این مدرک خوانده شده؟ اگر نه، دربارهٔ محتوایش نمی‌شود قضاوت کرد. */
    public function hasExtraction(): bool
    {
        return $this->values !== [];
    }

    public function extractedRowCount(): int
    {
        return $this->extractedRows;
    }

    public function value(string $fieldKey): ?FieldValue
    {
        return $this->values[$fieldKey] ?? null;
    }

    public function field(string $fieldKey): ?DocumentTypeField
    {
        return $this->fields[$fieldKey] ?? null;
    }

    /** @return array<string, DocumentTypeField> */
    public function allFields(): array
    {
        return $this->fields;
    }

    /** فیلدهای اجباریِ خالی — پایهٔ بررسی «کامل بودن اطلاعات». @return list<DocumentTypeField> */
    public function missingRequired(): array
    {
        $missing = [];

        foreach ($this->fields as $key => $field) {
            if ($field->is_required && ! isset($this->values[$key])) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /** همهٔ فیلدهای تاریخ‌دار این نوع مدرک. @return list<DocumentTypeField> */
    public function dateFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            static fn (DocumentTypeField $f): bool => $f->value_type === 'jalali_date',
        ));
    }

    /**
     * میانگین اطمینان مقادیر خوانده‌شده — معیار «آیا خواندنِ این مدرک قابل اتکا بود؟».
     * مقدار دست‌نویس کارشناس ۱۰۰ حساب می‌شود.
     */
    public function averageConfidence(): float
    {
        if ($this->values === []) {
            return 0.0;
        }

        $sum = 0.0;

        foreach ($this->values as $value) {
            $sum += $value->source === 'manual' ? 100.0 : $value->confidence;
        }

        return $sum / count($this->values);
    }

    /**
     * فیلدهایی که موتور برایشان چیزی خواند ولی مقدارش شکل معتبری نداشت.
     *
     * تفاوتش با «خالی» مهم است و از روی extracted_fields دیده نمی‌شود: هر دو
     * حالت یک ردیفِ نبوده‌اند. ولی «مدرک را نداریم» و «مدرک هست و ما نتوانستیم
     * بخوانیمش» دو حرف کاملاً متفاوت به متقاضی می‌زنند و نباید یک وزن بگیرند.
     *
     * تنها ردپای موجود از این تفاوت، کلیدهای `extra` اجرای OCR است: مسیر ویژهٔ
     * کارت خودرو شماره شاسی و پلاک را با برشِ خودش می‌خواند و هرچه خوانده —
     * حتی اگر آشغال باشد — همان‌جا می‌ماند. اگر آن مقدار آمده باشد ولی
     * FieldExtractor نتوانسته باشد از آن مقداری معتبر بسازد (رقم کنترل، ۱۷
     * نویسهٔ VIN، الگوی پلاک)، یعنی «خوانده شد ولی خوانا نبود».
     *
     * @return array<string, string> کلید فیلد ← همان چیزی که موتور خوانده بود
     */
    public function unreadable(): array
    {
        $run = $this->document?->latestOcrRun;

        // اجرای ناموفق یا نیمه‌کاره شاهد نیست: CasePipeline::markStalled فقط
        // status و error را عوض می‌کند و `extra` اجرای قبلی سر جایش می‌ماند،
        // پس بدون این شرط یک ردپای بیات می‌توانست تخفیف بگیرد.
        if ($run === null || $run->status !== 'done' || ! is_array($run->extra)) {
            return [];
        }

        $attempted = [];

        foreach (['vin', 'plate'] as $key) {
            $value = is_string($run->extra[$key] ?? null) ? trim($run->extra[$key]) : '';

            if ($value !== '') {
                // مقدار خامِ موتور است و طولش تضمینی ندارد؛ در details ذخیره
                // می‌شود پس همان‌جا کوتاه می‌شود.
                $attempted[$key] = mb_substr($value, 0, 120);
            }
        }

        if ($attempted === []) {
            return [];
        }

        $out = [];

        // فقط فیلد **اجباریِ خالی**: فیلد اختیاری (مثل پلاک روی «مجوز قبلی»)
        // نه در missingRequired() می‌آید نه باید در این فهرست بیاید، وگرنه
        // payload نامی را نام می‌برد که اصلاً جامانده نبوده.
        foreach ($this->fields as $key => $field) {
            $raw = $attempted[$field->value_type] ?? null;

            if ($raw !== null && (bool) $field->is_required && ! isset($this->values[$key])) {
                $out[$key] = $raw;
            }
        }

        return $out;
    }

    /** خواندن این مدرک آن‌قدر خوب بوده که نبودِ یک فیلد را بشود «واقعاً نیست» دانست. */
    public function isReliable(float $minConfidence): bool
    {
        return $this->hasExtraction() && $this->averageConfidence() >= $minConfidence;
    }
}
