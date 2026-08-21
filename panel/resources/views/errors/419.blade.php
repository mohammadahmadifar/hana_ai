{{-- توکن CSRF صفحه منقضی شده است (خطای ۴۱۹). --}}
@extends('layouts.guest')

@section('title', 'صفحه منقضی شده')

@section('content')
    <div class="authcard">
        <div class="stack stack--sm">
            <div class="empty__icon" aria-hidden="true">⏳</div>
            <h1>صفحه منقضی شده است</h1>
            <p class="authcard__sub">اعتبار این صفحه به پایان رسیده و فرم ارسال نشد (خطای ۴۱۹).</p>
        </div>

        <div class="alert alert--warn" role="alert">
            <span class="alert__icon">🔄</span>
            <span class="alert__body">
                <strong>فرم ارسال نشد</strong>
                <span>
                    صفحه مدت زیادی باز مانده بود یا نشست شما تازه شده است.
                    لطفاً دوباره وارد شوید و فرم را یک بار دیگر پر کنید؛ اطلاعات واردشده ثبت نشده است.
                </span>
            </span>
        </div>

        <div class="row">
            <a class="btn btn--primary" href="{{ route('login') }}">بازگشت به صفحهٔ ورود</a>
        </div>
    </div>
@endsection
