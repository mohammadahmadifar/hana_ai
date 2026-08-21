{{-- قالب صفحه‌های بیرون پنل: ورود و صفحه‌های خطا --}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'سامانه') — {{ config('app.name') }}</title>
    <link rel="icon" href="/favicon.ico">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
    <main class="authpage">
        @yield('content')
    </main>
</body>
</html>
