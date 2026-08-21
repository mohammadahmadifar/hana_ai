{{--
    خطای پیش‌بینی‌نشدهٔ سمت سرور (خطای ۵۰۰).
    عمداً هیچ جزئیاتی از استثنا نمایش داده نمی‌شود؛ جزئیات فقط در لاگ می‌ماند.
--}}
@extends('layouts.guest')

@section('title', 'خطای سامانه')

@section('content')
    <div class="authcard">
        <div class="stack stack--sm">
            <div class="empty__icon" aria-hidden="true">⚠️</div>
            <h1>خطایی در سامانه رخ داد</h1>
            <p class="authcard__sub">درخواست شما کامل نشد (خطای ۵۰۰).</p>
        </div>

        <div class="alert alert--bad" role="alert">
            <span class="alert__icon">🛠️</span>
            <span class="alert__body">
                <strong>مشکل از سمت سرور است، نه از کار شما</strong>
                <span>
                    این خطا برای بررسی ثبت شد. لطفاً چند لحظه بعد دوباره تلاش کنید؛
                    اگر تکرار شد، موضوع را به مدیر سامانه اطلاع دهید.
                </span>
            </span>
        </div>

        <div class="row">
            <a class="btn btn--primary" href="{{ url('/') }}">بازگشت به صفحهٔ اصلی</a>
        </div>
    </div>
@endsection
