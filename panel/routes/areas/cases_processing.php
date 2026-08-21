<?php

// روت‌های ناحیه «پردازش پرونده» (تسک ۶۳۱) — مالک این فایل همین بخش است.
// routes/web.php همه فایل‌های routes/areas/*.php را خودکار require می‌کند.

use App\Http\Controllers\Cases\CaseProcessingController;
use Illuminate\Support\Facades\Route;

/*
| فقط یک اندپوینت: وضعیت زندهٔ پردازش یک پرونده به شکل JSON.
|
| صفحهٔ نتیجهٔ پرونده (cases.show — تسک ۶۳۵) تا وقتی پردازش تمام نشده هر چند
| ثانیه این را می‌خواند؛ دقیقاً همان الگوی dataset.generate.status.
|
| نام روت: cases.processing.status   →   GET /cases/{case}/processing/status
| دسترسی: همان دسترسی بقیهٔ پرونده‌ها (مدیر سامانه و کارشناس)؛ مالکیت پرونده
| داخل کنترلر بررسی می‌شود، پس کارشناس پروندهٔ دیگری را نمی‌بیند.
*/

Route::middleware(['auth', 'role:admin,expert'])
    ->prefix('cases')
    ->name('cases.processing.')
    ->group(function (): void {
        Route::get('/{case}/processing/status', [CaseProcessingController::class, 'status'])
            ->whereNumber('case')
            ->name('status');
    });
