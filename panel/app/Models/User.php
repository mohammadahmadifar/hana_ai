<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    /** نقش‌های سامانه — کلید نقش => برچسب فارسی */
    public const ROLES = [
        'admin' => 'مدیر سامانه',
        'expert' => 'کارشناس بررسی',
        'data' => 'کارشناس داده',
    ];

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** آیا این کاربر به بخش داده و تگ‌گذاری دسترسی دارد؟ */
    public function canManageDataset(): bool
    {
        return in_array($this->role, ['admin', 'data'], true);
    }

    /** آیا این کاربر می‌تواند پرونده بررسی و تصمیم‌گیری کند؟ */
    public function canReviewCases(): bool
    {
        return in_array($this->role, ['admin', 'expert'], true);
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }
}
