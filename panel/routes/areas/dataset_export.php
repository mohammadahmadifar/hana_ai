<?php

// روت‌های ناحیه «خروجی دیتاست» — مالک این فایل همین بخش است.
// routes/web.php همهٔ فایل‌های routes/areas را خودکار require می‌کند.

use App\Http\Controllers\Dataset\ExportController;
use App\Support\DatasetExporter;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:admin,data'])
    ->prefix('dataset/export')
    ->name('dataset.export.')
    ->group(function (): void {
        Route::get('/', [ExportController::class, 'index'])->name('index');
        Route::post('/preview', [ExportController::class, 'preview'])->name('preview');
        Route::post('/', [ExportController::class, 'store'])->name('store');
        Route::get('/status', [ExportController::class, 'status'])->name('status');

        // شناسهٔ بسته الگوی ثابت دارد؛ هیچ نام فایلی از ورودی کاربر ساخته نمی‌شود.
        Route::get('/{token}/download', [ExportController::class, 'download'])
            ->where('token', DatasetExporter::TOKEN_ROUTE_PATTERN)
            ->name('download');

        Route::delete('/{token}', [ExportController::class, 'destroy'])
            ->where('token', DatasetExporter::TOKEN_ROUTE_PATTERN)
            ->name('destroy');
    });
