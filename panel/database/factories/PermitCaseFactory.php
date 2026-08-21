<?php

namespace Database\Factories;

use App\Models\PermitCase;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * پروندهٔ آزمایشی.
 *
 * هیچ دادهٔ هویتی واقعی این‌جا ساخته نمی‌شود (قانون پروژه): نام و کد ملی
 * مقادیر ساختگیِ آشکارند و فقط در تست استفاده می‌شوند.
 *
 * @extends Factory<PermitCase>
 */
class PermitCaseFactory extends Factory
{
    protected $model = PermitCase::class;

    public function definition(): array
    {
        return [
            'code' => 'HA-TEST-'.fake()->unique()->numberBetween(100000, 999999),
            'user_id' => User::factory(),
            'service_type_id' => fn () => ServiceType::query()->where('key', 'issue')->value('id')
                ?? ServiceType::query()->value('id'),
            'applicant_name' => 'متقاضی آزمایشی',
            'applicant_national_id' => null,
            'status' => 'draft',
        ];
    }

    public function ofService(string $key): static
    {
        return $this->state(fn () => [
            'service_type_id' => ServiceType::query()->where('key', $key)->value('id'),
        ]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function submitted(): static
    {
        return $this->state(fn () => ['status' => 'submitted', 'submitted_at' => now()]);
    }
}
