<?php

// روت‌های «نمونه‌های دیتاست» — تسک ۶۲۳.
// نام روت‌ها با config/panel_menu.php هماهنگ است (dataset.samples.index).

use App\Http\Controllers\Dataset\SampleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:admin,data'])
    ->prefix('dataset/samples')
    ->name('dataset.samples.')
    ->group(function (): void {
        Route::get('/', [SampleController::class, 'index'])->name('index');

        // کارهای دسته‌ای پیش از روت‌های پارامتری می‌آید تا «bulk» شناسهٔ نمونه تلقی نشود.
        Route::post('/bulk', [SampleController::class, 'bulk'])->name('bulk');

        Route::get('/{sample}', [SampleController::class, 'show'])->whereNumber('sample')->name('show');
        Route::post('/{sample}/verify', [SampleController::class, 'verify'])->whereNumber('sample')->name('verify');
        Route::post('/{sample}/tags', [SampleController::class, 'attachTag'])->whereNumber('sample')->name('tags.attach');
        Route::delete('/{sample}/tags/{tag}', [SampleController::class, 'detachTag'])->whereNumber('sample')->whereNumber('tag')->name('tags.detach');
        Route::delete('/{sample}', [SampleController::class, 'destroy'])->whereNumber('sample')->name('destroy');
    });
