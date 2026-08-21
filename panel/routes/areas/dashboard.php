<?php

// روت‌های ناحیه «dashboard» — مالک این فایل همان بخش است.
// routes/web.php فقط این فایل را require می‌کند و دست نمی‌خورد.

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});
