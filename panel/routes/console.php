<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| زمان‌بندی
|--------------------------------------------------------------------------
|
| برای اجرا شدن این‌ها، روی سرور باید یک ورودی cron باشد:
|
|     * * * * * cd /home/coder/hana_ai/panel && php artisan schedule:run >> /dev/null 2>&1
|
*/

// تصویرهای موقتی موتور (پیش‌پردازش، بررسی سلامت، پیش‌نمایش تصویر تستی) تا
// امروز هیچ‌وقت پاک نمی‌شدند و پوشه‌شان ۸۴ مگابایت شده بود. محتوایشان تصویر
// مدرک هویتی است، پس ماندنشان فقط مسئلهٔ دیسک نیست.
Schedule::command('hana:prune-engine-files --days=7')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();
