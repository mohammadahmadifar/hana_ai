<?php

// روت‌های ناحیه «تگ‌گذاری تصویری دیتاست» — مالک این فایل همان بخش است.
// routes/web.php فایل‌های routes/areas/*.php را خودکار بارگذاری می‌کند.

use App\Http\Controllers\Dataset\AnnotateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:admin,data'])
    ->prefix('dataset/annotate')
    ->name('dataset.annotate.')
    ->group(function (): void {
        // صف نمونه‌هایی که هنوز برچسب کامل یا تایید ندارند
        Route::get('/', [AnnotateController::class, 'index'])->name('index');

        // بوم تگ‌گذاری یک نمونهٔ مشخص
        Route::get('/{sample}', [AnnotateController::class, 'edit'])
            ->whereNumber('sample')
            ->name('edit');

        // ذخیرهٔ برچسب‌ها (فرم پنل یا بدنهٔ JSON)
        Route::post('/{sample}', [AnnotateController::class, 'update'])
            ->whereNumber('sample')
            ->name('update');
    });
