<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\PersianValue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * شمارندهٔ کد ملی — تسک ۷۴۰.
     *
     * از وقتی نام کاربری ورود کد ملی است، هر کاربرِ ساخته‌شده باید کد ملیِ
     * یکتا و **معتبر** داشته باشد؛ رشتهٔ تصادفیِ ده‌رقمی رقم کنترلش درست
     * درنمی‌آید و کاربرِ ساختهٔ فکتوری همان اعتبارسنجی‌ای را رد می‌کند که فرم
     * مدیر اعمالش می‌کند.
     */
    protected static int $nationalIdCounter = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'national_id' => self::syntheticNationalId(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * کد ملی مصنوعیِ معتبر و یکتا.
     *
     * پیشوند «۸» عمداً با پیشوند «۹»ِ مهاجرت و «۰۰»ِ حساب‌های دمو فرق دارد،
     * تا کاربر ساختهٔ تست هرگز با آن‌ها برخورد نکند.
     */
    public static function syntheticNationalId(): string
    {
        $nine = '8'.str_pad((string) ++self::$nationalIdCounter, 8, '0', STR_PAD_LEFT);

        for ($check = 0; $check <= 9; $check++) {
            if (PersianValue::isValidNationalId($nine.$check)) {
                return $nine.$check;
            }
        }

        throw new RuntimeException('رقم کنترل کد ملی مصنوعی پیدا نشد: '.$nine);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
