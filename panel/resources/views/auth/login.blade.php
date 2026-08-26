@extends('layouts.guest')

@section('title', 'ورود به سامانه')

@section('content')
    <form class="authcard" method="POST" action="{{ url('/login') }}" novalidate>
        @csrf

        <div class="stack stack--sm">
            <h1>ورود به سامانه</h1>
            <p class="authcard__sub">سامانهٔ هوشمند پیش‌اعتبارسنجی و پایش مجوزهای حمل‌ونقل</p>
        </div>

        @if (session('success'))
            <div class="alert alert--ok" role="status">
                <span class="alert__icon">✅</span>
                <span class="alert__body">{{ session('success') }}</span>
            </div>
        @endif

        @error('auth')
            <div class="alert alert--bad" role="alert">
                <span class="alert__icon">⛔</span>
                <span class="alert__body">
                    <strong>ورود انجام نشد</strong>
                    <span>{{ $message }}</span>
                </span>
            </div>
        @enderror

        <div class="stack">
            {{--
                نام کاربری کد ملی است، نه ایمیل (تسک ۷۴۰).
                inputmode="numeric" روی موبایل صفحه‌کلید عددی می‌آورد، و
                maxlength جلوی کد ملی‌های پرکاراکترِ کپی‌شده را می‌گیرد؛ ارقام
                فارسی هم پذیرفته می‌شوند و سمت سرور به لاتین تبدیل می‌شوند.
            --}}
            <div class="field">
                <label class="label" for="national_id">کد ملی <span class="label__req">*</span></label>
                <input
                    class="input input--ltr @error('national_id') is-invalid @enderror @error('auth') is-invalid @enderror"
                    id="national_id"
                    type="text"
                    name="national_id"
                    value="{{ old('national_id') }}"
                    autocomplete="username"
                    inputmode="numeric"
                    maxlength="20"
                    dir="ltr"
                    autofocus
                    required>
                <span class="hint">همان ده رقمِ روی کارت ملی، بدون خط تیره.</span>
                @error('national_id')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label class="label" for="password">رمز عبور <span class="label__req">*</span></label>
                <input
                    class="input input--ltr @error('password') is-invalid @enderror @error('auth') is-invalid @enderror"
                    id="password"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    dir="ltr"
                    required>
                @error('password')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <label class="check">
                <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                <span>مرا به خاطر بسپار</span>
            </label>

            <button class="btn btn--primary btn--lg btn--block" type="submit">ورود</button>
        </div>

        <p class="hint">
            ثبت‌نام عمومی وجود ندارد. حساب کاربری را فقط مدیر سامانه می‌سازد؛
            اگر حساب ندارید یا رمزتان را فراموش کرده‌اید با مدیر سامانه تماس بگیرید.
        </p>
    </form>
@endsection
