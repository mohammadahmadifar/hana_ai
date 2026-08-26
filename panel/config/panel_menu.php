<?php

/*
|--------------------------------------------------------------------------
| منوی پنل — قرارداد ناوبری سامانه
|--------------------------------------------------------------------------
| این فایل تنها منبع حقیقت برای منوی کناری است. برای افزودن صفحه جدید فقط
| همین‌جا یک آیتم اضافه کنید؛ لازم نیست قالب (layouts/panel) دست بخورد.
|
| ساختار هر گروه:
|   label  : عنوان فارسی گروه (بالای آیتم‌ها نمایش داده می‌شود)
|   roles  : نقش‌های مجاز برای دیدن کل گروه — آرایه خالی یعنی همه نقش‌ها
|   items  : آیتم‌های گروه
|
| ساختار هر آیتم:
|   route  : نام روت لاراول. اگر روت هنوز ثبت نشده باشد، قالب آیتم را
|            غیرفعال و با برچسب «به‌زودی» نشان می‌دهد و هرگز خطا نمی‌دهد.
|   label  : برچسب فارسی
|   icon   : ایموجی (تنها آیکون مجاز پروژه)
|   roles  : نقش‌های مجاز این آیتم — خالی یعنی «هر کس گروه را می‌بیند»
|   active : الگوی routeIs برای هایلایت (اختیاری، پیش‌فرض خودِ route).
|            می‌تواند رشته یا آرایه‌ای از الگوها باشد، مثل 'cases.*'
|   counter: کلید شمارنده‌ی badge (اختیاری). قالب مقدارش را محاسبه می‌کند.
|            کلیدهای شناخته‌شده در layouts/panel.blade.php تعریف شده‌اند.
*/

return [

    /*
    | منطقه‌زمانی نمایش. تاریخ‌ها در دیتابیس با APP_TIMEZONE (فعلاً UTC) ذخیره
    | می‌شوند؛ کامپوننت <x-jdate> برای نمایش به این منطقه تبدیل می‌کند تا
    | ساعت و تاریخ شمسی برای کاربر ایرانی درست باشد.
    */
    'timezone' => 'Asia/Tehran',

    'brand' => [
        'title' => 'سامانه هانا',
        'subtitle' => 'پیش‌اعتبارسنجی و پایش مجوزهای حمل‌ونقل',
    ],

    'groups' => [

        [
            'label' => 'نمای کلی',
            'roles' => [],
            'items' => [
                [
                    'route' => 'dashboard',
                    'label' => 'داشبورد',
                    'icon' => '📊',
                    'roles' => [],
                ],
            ],
        ],

        [
            'label' => 'داده و آموزش',
            'roles' => ['admin', 'data'],
            'items' => [
                [
                    'route' => 'dataset.samples.index',
                    'label' => 'نمونه‌های دیتاست',
                    'icon' => '🗂',
                    'roles' => [],
                    'active' => 'dataset.samples.*',
                ],
                [
                    'route' => 'dataset.generate.create',
                    'label' => 'تولید انبوه',
                    'icon' => '⚙️',
                    'roles' => [],
                    'active' => 'dataset.generate.*',
                ],
                [
                    'route' => 'dataset.annotate.index',
                    'label' => 'تگ‌گذاری',
                    'icon' => '🏷',
                    'roles' => [],
                    'active' => 'dataset.annotate.*',
                ],
                [
                    'route' => 'dataset.export.index',
                    'label' => 'خروجی آموزش',
                    'icon' => '📦',
                    'roles' => [],
                    'active' => 'dataset.export.*',
                ],
                [
                    'route' => 'dataset.tags.index',
                    'label' => 'تگ‌ها',
                    'icon' => '🔖',
                    'roles' => [],
                    'active' => 'dataset.tags.*',
                ],
            ],
        ],

        [
            'label' => 'تصویر تستی',
            'roles' => ['admin', 'data', 'expert'],
            'items' => [
                [
                    'route' => 'testimage.create',
                    'label' => 'ساخت تصویر تستی',
                    'icon' => '🖼',
                    'roles' => [],
                ],
                [
                    'route' => 'testimage.index',
                    'label' => 'تصاویر من',
                    'icon' => '🗃',
                    'roles' => [],
                    'active' => ['testimage.index', 'testimage.show'],
                ],
            ],
        ],

        [
            'label' => 'درخواست خدمت',
            // متقاضی هم این گروه را می‌بیند، ولی فقط دو آیتم اولش؛ «صف بررسی»
            // و «گزارش خطاها» با roles خودشان بسته شده‌اند تا آیتمی نبیند که
            // کلیکش ۴۰۳ می‌دهد. شمارندهٔ صف هم فقط وقتی خوانده می‌شود که آیتمش
            // دیده شود، پس متقاضی عدد پرونده‌های دیگران را هم نمی‌گیرد.
            'roles' => ['admin', 'expert', 'applicant'],
            'items' => [
                [
                    'route' => 'cases.create',
                    'label' => 'درخواست جدید',
                    'icon' => '➕',
                    'roles' => [],
                    'active' => ['cases.create', 'cases.store'],
                ],
                [
                    'route' => 'cases.index',
                    'label' => 'پرونده‌ها',
                    'icon' => '📂',
                    'roles' => [],
                    'active' => ['cases.index', 'cases.show', 'cases.documents.*', 'cases.submit'],
                ],
                [
                    'route' => 'cases.review',
                    'label' => 'صف بررسی',
                    'icon' => '🔍',
                    'roles' => ['admin', 'expert'],
                    'active' => 'cases.review*',
                    'counter' => 'cases_needs_review',
                ],
                [
                    'route' => 'reports.index',
                    'label' => 'گزارش خطاها',
                    'icon' => '📉',
                    'roles' => ['admin', 'expert'],
                    'active' => 'reports.*',
                ],
                [
                    // تسک ۷۲۶ — اندازه‌گیری دقت روی دستهٔ بزرگ تصویر مصنوعی.
                    // فقط مدیر: هر اجرا تا هزار تصویر می‌سازد و صف را چند
                    // دقیقه مشغول می‌کند.
                    'route' => 'evaluation.create',
                    'label' => 'ارزیابی دقت',
                    'icon' => '🎯',
                    'roles' => ['admin'],
                    'active' => 'evaluation.*',
                ],
            ],
        ],

        [
            'label' => 'مدیریت',
            'roles' => ['admin'],
            'items' => [
                [
                    'route' => 'admin.users.index',
                    'label' => 'کاربران',
                    'icon' => '👥',
                    'roles' => [],
                    'active' => 'admin.users.*',
                ],
                [
                    'route' => 'admin.settings.scoring',
                    'label' => 'تنظیمات امتیازدهی',
                    'icon' => '🎚',
                    'roles' => [],
                    'active' => 'admin.settings.*',
                ],
            ],
        ],

    ],
];
