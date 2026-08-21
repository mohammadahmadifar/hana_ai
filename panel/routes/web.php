<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| روت‌های سامانه
|--------------------------------------------------------------------------
| این فایل عمداً خالی است و فقط فایل‌های ناحیه‌ای را require می‌کند.
| هر بخش روت خودش را در routes/areas/<area>.php می‌نویسد (قانون پروژه ۱۲۷).
*/

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

foreach (['auth', 'panel', 'dataset', 'testimage', 'cases', 'dashboard'] as $area) {
    require __DIR__."/areas/{$area}.php";
}
