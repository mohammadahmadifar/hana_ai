<?php

// روت‌های ناحیه «testimage» — مالک این فایل همان بخش است.
// routes/web.php فقط این فایل را require می‌کند و دست نمی‌خورد.

use App\Http\Controllers\TestImageController;
use Illuminate\Support\Facades\Route;

/*
| ساخت تصویر تستی: کارشناس داده و کارشناس بررسی هر دو لازمشان دارند،
| پس هر سه نقش اجازه دارند. مالکیت تصویر داخل کنترلر بررسی می‌شود
| (هر کسی فقط تصاویر خودش را می‌بیند، مدیر سامانه همه را).
|
| نام روت‌ها با آنچه config/panel_menu.php رزرو کرده یکی است:
|   testimage.create  و  testimage.index
*/

Route::middleware(['auth', 'role:admin,data,expert'])
    ->prefix('test-image')
    ->name('testimage.')
    ->group(function (): void {

        // مسیرهای ثابت پیش از مسیر پارامتری می‌آیند تا «new» با شناسه اشتباه نشود
        Route::get('/new', [TestImageController::class, 'create'])->name('create');
        Route::post('/random', [TestImageController::class, 'random'])->name('random');

        Route::get('/', [TestImageController::class, 'index'])->name('index');
        Route::post('/', [TestImageController::class, 'store'])->name('store');

        Route::get('/{testImage}', [TestImageController::class, 'show'])
            ->whereNumber('testImage')->name('show');

        Route::get('/{testImage}/download', [TestImageController::class, 'download'])
            ->whereNumber('testImage')->name('download');

        Route::post('/{testImage}/ocr', [TestImageController::class, 'ocr'])
            ->whereNumber('testImage')->name('ocr');

        Route::post('/{testImage}/dataset', [TestImageController::class, 'toDataset'])
            ->whereNumber('testImage')->name('dataset');

        Route::delete('/{testImage}', [TestImageController::class, 'destroy'])
            ->whereNumber('testImage')->name('destroy');
    });
