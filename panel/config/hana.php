<?php

/*
|--------------------------------------------------------------------------
| موتور پایتون «هانا»
|--------------------------------------------------------------------------
|
| پنل هرگز مستقیم به فایل‌های app/ و dataset/ دست نمی‌زند. تنها پل،
| کلاس App\Services\HanaEngine است که «python -m hana_engine.cli» را
| با JSON روی stdin صدا می‌زند و JSON می‌گیرد.
|
*/

return [

    // مسیر مفسر پایتون محیط مجازی موتور
    'python' => env('HANA_ENGINE_PYTHON', '/home/coder/hana_ai/.venv/bin/python'),

    // ریشه پروژه پایتون (پوشه‌ای که hana_engine داخل آن است)
    'root' => env('HANA_ENGINE_ROOT', dirname(base_path())),

    // ماژول نقطه‌ورود موتور
    'module' => env('HANA_ENGINE_MODULE', 'hana_engine.cli'),

    // بیشترین زمان مجاز اجرای یک فراخوانی (ثانیه)
    'timeout' => (int) env('HANA_ENGINE_TIMEOUT', 300),

    // ثبت stderr موتور در لاگ لاراول
    'log_stderr' => (bool) env('HANA_ENGINE_LOG_STDERR', true),

    // بیشترین طول متنی که از stderr در لاگ ذخیره می‌شود
    'stderr_limit' => 4000,

    /*
    | پاک‌سازی خروجی موقت موتور — hana:prune-engine-files
    |
    | ریشه و پوشه‌های زیرش قابل تنظیم‌اند تا تست بتواند دستور را روی یک پوشهٔ
    | موقت اجرا کند و هرگز به storage واقعی دست نزند. هر مسیری که این‌جا
    | اضافه می‌شود باید **فقط** خروجی تولیدشدهٔ موتور داشته باشد؛ مدارک
    | پرونده و دیتاست هرگز.
    */
    'prune_base' => storage_path('app/private'),

    'prune_roots' => [
        'engine',                   // خروجی پیش‌فرض ocr_document و بقیهٔ دستورها
        'engine-check',             // خروجی hana:engine-check
        'testimages/_preprocessed', // پیش‌نمایش صفحهٔ «تصویر تستی»
    ],

    // فایل قدیمی‌تر از این تعداد روز پاک می‌شود (پیش‌فرض دستور و زمان‌بندی)
    'prune_days' => (int) env('HANA_PRUNE_DAYS', 7),

];
