<?php

// روت‌های ناحیه «تولید انبوه نمونه دیتاست» — مالک این فایل همین بخش است.
// routes/web.php همه فایل‌های routes/areas/*.php را خودکار require می‌کند.

use App\Http\Controllers\Dataset\GenerateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:admin,data'])
    ->prefix('dataset/generate')
    ->name('dataset.generate.')
    ->group(function (): void {
        // فرم تولید + فهرست دسته‌های اخیر
        Route::get('/', [GenerateController::class, 'create'])->name('create');

        // ساخت دسته و سپردن کار به صف
        Route::post('/', [GenerateController::class, 'store'])->name('store');

        // صفحه پیشرفت یک دسته
        Route::get('/{batch}', [GenerateController::class, 'show'])
            ->whereNumber('batch')
            ->name('show');

        // وضعیت زنده (JSON) — صفحه پیشرفت هر ۳ ثانیه این را می‌خواند
        Route::get('/{batch}/status', [GenerateController::class, 'status'])
            ->whereNumber('batch')
            ->name('status');
    });
