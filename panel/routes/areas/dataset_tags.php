<?php

// روت‌های «تگ‌های دیتاست» — تسک ۶۲۳.

use App\Http\Controllers\Dataset\TagController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:admin,data'])
    ->prefix('dataset/tags')
    ->name('dataset.tags.')
    ->group(function (): void {
        Route::get('/', [TagController::class, 'index'])->name('index');
        Route::post('/', [TagController::class, 'store'])->name('store');
        Route::put('/{tag}', [TagController::class, 'update'])->whereNumber('tag')->name('update');
        Route::delete('/{tag}', [TagController::class, 'destroy'])->whereNumber('tag')->name('destroy');
    });
