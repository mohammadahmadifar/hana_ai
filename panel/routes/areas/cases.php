<?php

// روت‌های ناحیه «cases» — مالک این فایل همان بخش است.
// routes/web.php فقط این فایل را require می‌کند و دست نمی‌خورد.

use App\Http\Controllers\Cases\CaseController;
use App\Http\Controllers\Cases\CaseDocumentController;
use App\Http\Controllers\Cases\CaseFromTestImageController;
use Illuminate\Support\Facades\Route;

/*
| ویزارد درخواست خدمت (تسک ۶۲۹):
|   انتخاب نوع خدمت  →  بارگذاری مدارکِ همان خدمت  →  ثبت نهایی
|
| مدارک لازم از دیتابیس می‌آید (service_type_document_type)، نه از ویو؛
| پس صدور سه مدرک می‌خواهد و تمدید چهارتا، بدون هیچ شرط هاردکدی.
|
| دسترسی: مدیر سامانه، کارشناس بررسی و متقاضی. مالکیت پرونده داخل کنترلر
| بررسی می‌شود (هر کس فقط پرونده‌های خودش را می‌بیند، مدیر سامانه همه را) —
| پس متقاضی با همین گروه هم فقط پروندهٔ خودش را باز می‌کند.
|
| استثنا: «از تصویر تستی» زیرگروه خودش را دارد و متقاضی داخلش نیست، چون
| متقاضی اصلاً تصویر تستی نمی‌سازد.
|
| نام روت‌ها با آنچه config/panel_menu.php رزرو کرده یکی است:
|   cases.create ، cases.index  (و cases.show/cases.review مال تسک ۶۳۵ است
|   و در routes/areas/cases_review.php ثبت می‌شود — این‌جا ثبت نمی‌شوند).
*/

Route::middleware(['auth', 'role:admin,expert,applicant'])
    ->prefix('cases')
    ->name('cases.')
    ->group(function (): void {

        // مسیر ثابت پیش از مسیر پارامتری می‌آید تا «new» با شناسه اشتباه نشود
        Route::get('/new', [CaseController::class, 'create'])->name('create');

        // «بفرست به فرایند بررسی» صفحهٔ تصویر تستی (تسک ۶۲۷): پروندهٔ پیش‌نویس
        // تازه با همان تصویر به‌عنوان مدرک. روتش این‌جاست نه در testimage.php،
        // چون کاری که می‌کند ساختِ پرونده است — ولی برخلاف بقیهٔ این گروه
        // متقاضی را راه نمی‌دهد، چون متقاضی به بخش تصویر تستی دسترسی ندارد.
        // (کارشناس داده هم پرونده نمی‌سازد، هرچند تصویر تستی می‌سازد.)
        Route::middleware('role:admin,expert')->group(function (): void {
            Route::post('/from-test-image/{testImage}', [CaseFromTestImageController::class, 'store'])
                ->whereNumber('testImage')->name('fromTestImage');
        });

        Route::get('/', [CaseController::class, 'index'])->name('index');
        Route::post('/', [CaseController::class, 'store'])->name('store');

        // گام دوم ویزارد: صفحهٔ مدارک همان پرونده
        Route::get('/{case}/documents', [CaseController::class, 'documents'])
            ->whereNumber('case')->name('documents.edit');

        // بارگذاری یا جایگزینی یک مدرک
        Route::post('/{case}/documents', [CaseDocumentController::class, 'store'])
            ->whereNumber('case')->name('documents.store');

        Route::delete('/{case}/documents/{document}', [CaseDocumentController::class, 'destroy'])
            ->whereNumber('case')->whereNumber('document')->name('documents.destroy');

        // گام سوم: ثبت پرونده و رفتنش به صف بررسی
        Route::post('/{case}/submit', [CaseController::class, 'submit'])
            ->whereNumber('case')->name('submit');
    });
