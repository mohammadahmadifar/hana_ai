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

    /** خواندن این مدرک آن‌قدر خوب بوده که نبودِ یک فیلد را بشود «واقعاً نیست» دانست. */
    public function isReliable(float $minConfidence): bool
    {
        return $this->hasExtraction() && $this->averageConfidence() >= $minConfidence;
    }
}
