<?php

// روت‌های ناحیه «تنظیمات» — تسک ۶۳۴.
// نام روت‌ها با config/panel_menu.php هماهنگ است: آیتم منو روی
// admin.settings.scoring نشسته و الگوی هایلایتش admin.settings.* است.
// routes/web.php فقط این فایل را require می‌کند و دست نمی‌خورد.

use App\Http\Controllers\Admin\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:admin'])
    ->prefix('admin/settings')
    ->name('admin.settings.')
    ->group(function (): void {
        Route::get('/scoring', [SettingsController::class, 'scoring'])->name('scoring');
        Route::put('/scoring', [SettingsController::class, 'updateScoring'])->name('scoring.update');
    });
