<?php

// روت‌های ناحیه «reports» — مالک این فایل همان بخش است.
// routes/web.php فقط این فایل را require می‌کند و دست نمی‌خورد.

use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

/*
| دسترسی: گزارش‌ها از داده پرونده تغذیه می‌شوند و ردیف‌هایشان به پرونده لینک
| می‌دهند، پس همان نقش‌هایی می‌بینندشان که گروه «درخواست خدمت» را می‌بینند
| (User::canReviewCases()). نقش «کارشناس داده» این ناحیه را ۴۰۳ می‌گیرد.
|
| کلید قانون و کلید فیلد به‌جای پارامتر مسیر، در کوئری‌استرینگ می‌آیند:
| مقدارشان نقطه دارد (`document.expired.national_card`) و به‌عنوان بخشی از
| مسیر هم زشت است هم با هر تغییر قرارداد rule_key روت را می‌شکند.
*/
Route::middleware(['auth', 'role:admin,expert'])
    ->prefix('reports')
    ->name('reports.')
    ->group(function (): void {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/rule', [ReportController::class, 'rule'])->name('rule');
        Route::get('/field', [ReportController::class, 'field'])->name('field');
    });
