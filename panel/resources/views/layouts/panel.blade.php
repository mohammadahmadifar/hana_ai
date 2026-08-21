{{--
    قالب پایه پنل — همه صفحه‌های داخل سامانه روی این قالب سوار می‌شوند.

    قرارداد سکشن‌ها:
      @section('title')          عنوان تب مرورگر (بدون نام سامانه؛ خودکار اضافه می‌شود)
      @section('page_title')     عنوان نوار بالا
      @section('topbar_actions') دکمه‌های سمت چپ نوار بالا (اختیاری)
      @section('content')        بدنه صفحه
      @push('head') / @push('scripts')  برای CSS یا JS موضعی یک صفحه

    نام محصول: تنها منبعِ نام، config('app.name') است — همان چیزی که قالب مهمان
    (ورود و صفحه ۴۰۳) هم استفاده می‌کند؛ پس سامانه همه‌جا یک اسم دارد. زیرعنوانِ
    کوتاهِ کنار نام از config/panel_menu.php خوانده می‌شود.

    منو از config/panel_menu.php خوانده می‌شود — این فایل را برای افزودن
    صفحه جدید دست نزنید.
--}}
@php
    use App\Support\PanelMenu;

    $panelBrand = config('panel_menu.brand', []);
    $panelAppName = config('app.name');
    $panelUser = auth()->user();
    $panelLogoutUrl = PanelMenu::url('logout');
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <title>@hasSection('title')@yield('title') — @endif{{ $panelAppName }}</title>
    <link rel="icon" href="/favicon.ico">
    <link rel="stylesheet" href="/assets/app.css">
    @stack('head')
</head>
<body>
<div class="shell">

    <aside class="sidebar">
        <div class="sidebar__brand">
            <strong>{{ $panelAppName }}</strong>
            <span>{{ $panelBrand['subtitle'] ?? '' }}</span>
        </div>

        <nav class="sidebar__nav" aria-label="منوی اصلی">
            @include('partials.menu')
        </nav>

        <div class="sidebar__foot">
            @if ($panelUser)
                <div class="stack stack--sm">
                    <div>
                        <div class="strong small">{{ $panelUser->name }}</div>
                        <div class="tiny faint">{{ $panelUser->roleLabel() }}</div>
                    </div>
                    @if ($panelLogoutUrl !== null)
                        <form method="POST" action="{{ $panelLogoutUrl }}">
                            @csrf
                            <button type="submit" class="btn btn--ghost btn--sm btn--block">
                                <span aria-hidden="true">↩</span>
                                خروج از حساب
                            </button>
                        </form>
                    @endif
                </div>
            @else
                <div class="tiny faint">مهمان</div>
            @endif
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div class="topbar__title">@yield('page_title', $panelAppName)</div>
            <div class="topbar__spacer"></div>
            @hasSection('topbar_actions')
                @yield('topbar_actions')
            @endif
        </header>

        <div class="content">
            @include('partials.flash')
            @yield('content')
        </div>
    </main>

</div>
{{--
    تایید عملیات خطرناک — یک نگهبان سراسری برای کل پنل.

    هرگز داده کاربر را داخل رشتهٔ جاوااسکریپت در یک attribute (مثل
    onsubmit="confirm('{{ $x }}')") ننویس: پارسر HTML موجودیت‌های بلید
    (&#039;) را قبل از اجرای JS به نویسهٔ اصلی برمی‌گرداند و رشته می‌شکند.
    به‌جایش روی فرم بنویس:  data-confirm="متن پرسش"
    مقدارش یک داده است نه کد، و اینجا از dataset خوانده می‌شود.
--}}
<script>
    (function () {
        document.addEventListener('submit', function (event) {
            var form = event.target;

            if (!form || !form.matches || !form.matches('form[data-confirm]')) {
                return;
            }

            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        }, true);
    })();
</script>

@stack('scripts')
</body>
</html>
