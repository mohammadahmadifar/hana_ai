{{--
    قالب پایه پنل — همه صفحه‌های داخل سامانه روی این قالب سوار می‌شوند.

    قرارداد سکشن‌ها:
      @section('title')          عنوان تب مرورگر (بدون نام سامانه؛ خودکار اضافه می‌شود)
      @section('page_title')     عنوان نوار بالا
      @section('topbar_actions') دکمه‌های سمت چپ نوار بالا (اختیاری)
      @section('content')        بدنه صفحه
      @push('head') / @push('scripts')  برای CSS یا JS موضعی یک صفحه

    منو از config/panel_menu.php خوانده می‌شود — این فایل را برای افزودن
    صفحه جدید دست نزنید.
--}}
@php
    $panelBrand = config('panel_menu.brand', []);
    $panelUser = auth()->user();
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <title>@hasSection('title')@yield('title') — @endif{{ $panelBrand['title'] ?? 'سامانه هانا' }}</title>
    <link rel="icon" href="/favicon.ico">
    <link rel="stylesheet" href="/assets/app.css">
    @stack('head')
</head>
<body>
<div class="shell">

    <aside class="sidebar">
        <div class="sidebar__brand">
            <strong>{{ $panelBrand['title'] ?? 'سامانه هانا' }}</strong>
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
                    @if (\Illuminate\Support\Facades\Route::has('logout'))
                        <form method="POST" action="{{ route('logout') }}">
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
            <div class="topbar__title">@yield('page_title', 'سامانه هانا')</div>
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
@stack('scripts')
</body>
</html>
