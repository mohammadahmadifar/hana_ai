{{-- نشانی خواسته‌شده وجود ندارد (خطای ۴۰۴). --}}
@extends('layouts.guest')

@section('title', 'صفحه پیدا نشد')

@section('content')
    <div class="authcard">
        <div class="stack stack--sm">
            <div class="empty__icon" aria-hidden="true">🧭</div>
            <h1>این صفحه پیدا نشد</h1>
            <p class="authcard__sub">نشانی‌ای که باز کردید در سامانه وجود ندارد (خطای ۴۰۴).</p>
        </div>

        <div class="alert alert--info" role="alert">
            <span class="alert__icon">🔍</span>
            <span class="alert__body">
                <strong>شاید نشانی اشتباه تایپ شده باشد</strong>
                <span>
                    ممکن است این صفحه جابه‌جا یا حذف شده باشد، یا رکوردی که دنبالش بودید دیگر وجود نداشته باشد.
                    از منوی سامانه دوباره به بخش موردنظر بروید.
                </span>
            </span>
        </div>

        <div class="row">
            <a class="btn btn--primary" href="{{ url('/') }}">بازگشت به صفحهٔ اصلی</a>
        </div>
    </div>
@endsection
