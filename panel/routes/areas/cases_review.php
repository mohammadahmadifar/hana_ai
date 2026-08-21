<?php

// روت‌های ناحیه «نتیجهٔ پرونده و بررسی انسانی» — مالک این فایل همان بخش است.
// routes/web.php فایل‌های routes/areas/*.php را خودکار بارگذاری می‌کند.

use App\Http\Controllers\Cases\CaseReviewController;
use Illuminate\Support\Facades\Route;

/*
| مرحلهٔ آخر فلوچارت (تسک ۶۳۵):
|   صف بررسی  →  صفحهٔ نتیجهٔ یک پرونده  →  اصلاح فیلد  →  تصمیم نهایی
|
| تقسیم کار با routes/areas/cases.php (تسک ۶۲۹): آن‌جا ویزارد ساختن پرونده و
| بارگذاری مدارک است (cases.create / cases.store / cases.index / cases.documents.*)
| و این‌جا خواندن نتیجه و تصمیم‌گیری (cases.show / cases.review / cases.fields.update
| / cases.decide). هر دو فایل روی همان پیشوند `cases` و همان گروه نام `cases.`
| می‌نشینند، پس هیچ نام روتی نباید بین دو فایل تکرار شود.
|
| دسترسی: کارشناس بررسی و مدیر سامانه هر پرونده‌ای را می‌بینند — برخلاف ویزارد
| که مالکیت دارد. دلیلش خود کار است: بررسی انسانی یعنی کسی غیر از ثبت‌کنندهٔ
| پرونده آن را بازبینی کند، وگرنه «بررسی» معنایی ندارد.
|
| ترتیب مهم است: مسیر ثابت /cases/review پیش از /cases/{case} می‌آید. قید
| whereNumber هم روی {case} هست، پس حتی اگر ترتیب عوض شود «review» با شناسهٔ
| پرونده اشتباه گرفته نمی‌شود.
*/

Route::middleware(['auth', 'role:admin,expert'])
    ->prefix('cases')
    ->name('cases.')
    ->group(function (): void {

        // صف بررسی: فقط پرونده‌هایی که ماشین تصمیمشان را به انسان واگذار کرده
        Route::get('/review', [CaseReviewController::class, 'review'])->name('review');

        // صفحهٔ نتیجهٔ یک پرونده: امتیاز، دلایل، مدارک کنار فیلدها
        Route::get('/{case}', [CaseReviewController::class, 'show'])
            ->whereNumber('case')->name('show');

        // اصلاح دستی فیلدهای یک مدرک (یک فرم برای هر مدرک، فقط تغییرها ذخیره می‌شوند)
        Route::post('/{case}/documents/{document}/fields', [CaseReviewController::class, 'updateFields'])
            ->whereNumber('case')->whereNumber('document')->name('fields.update');

        // تصمیم نهایی کارشناس: تایید، رد، یا پس‌گرفتن تصمیم دستی
        Route::post('/{case}/decide', [CaseReviewController::class, 'decide'])
            ->whereNumber('case')->name('decide');
    });
