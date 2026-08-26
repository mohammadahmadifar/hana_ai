<?php

// روت‌های ناحیه «ارزیابی دقت» (تسک ۷۲۶) — مالک این فایل همین بخش است.
// routes/web.php همه فایل‌های routes/areas/*.php را خودکار require می‌کند.

use App\Http\Controllers\Evaluation\EvaluationController;
use Illuminate\Support\Facades\Route;

// فقط مدیر سامانه: هر اجرا تا هزار تصویر می‌سازد و صف را چند دقیقه مشغول
// می‌کند؛ این ابزار اندازه‌گیری است، نه بخشی از کار روزمرهٔ کارشناس.
Route::middleware(['auth', 'role:admin'])
    ->prefix('evaluation')
    ->name('evaluation.')
    ->group(function (): void {
        // فرم اجرای تازه + فهرست اجراهای اخیر
        Route::get('/', [EvaluationController::class, 'create'])->name('create');

        // ساخت اجرا و سپردن کار به صف
        Route::post('/', [EvaluationController::class, 'store'])->name('store');

        // صفحهٔ پیشرفت و نتیجه
        Route::get('/{run}', [EvaluationController::class, 'show'])
            ->whereNumber('run')
            ->name('show');

        // وضعیت زنده (JSON) — صفحهٔ نتیجه هر ۳ ثانیه این را می‌خواند
        Route::get('/{run}/status', [EvaluationController::class, 'status'])
            ->whereNumber('run')
            ->name('status');
    });
