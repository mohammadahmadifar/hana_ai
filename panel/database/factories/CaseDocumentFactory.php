<?php

namespace Database\Factories;

use App\Models\CaseDocument;
use App\Models\DocumentType;
use App\Models\PermitCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * مدرک یک پرونده.
 *
 * مسیر پیش‌فرض روی دیسک خصوصی «documents» است ولی فایل واقعی ساخته نمی‌شود؛
 * تستی که به فایل نیاز دارد از Tests\Concerns\BuildsCases::fakeDocumentImage()
 * استفاده کند تا یک PNG مصنوعی روی دیسک تستی بسازد.
 *
 * @extends Factory<CaseDocument>
 */
class CaseDocumentFactory extends Factory
{
    protected $model = CaseDocument::class;

    public function definition(): array
    {
        return [
            'case_id' => PermitCase::factory(),
            'document_type_id' => fn () => DocumentType::query()->where('key', 'national_card')->value('id')
                ?? DocumentType::query()->value('id'),
            'disk' => 'documents',
            'path' => 'test/'.fake()->uuid().'.png',
            'original_name' => 'sample.png',
            'mime' => 'image/png',
            'size_bytes' => 240_000,
            'width' => 960,
            'height' => 540,
            'precheck_status' => 'pending',
            'ocr_status' => 'pending',
        ];
    }

    public function ofType(string $key): static
    {
        return $this->state(fn () => [
            'document_type_id' => DocumentType::query()->where('key', $key)->value('id'),
        ]);
    }

    public function prechecked(): static
    {
        return $this->state(fn () => ['precheck_status' => 'passed']);
    }
}
