@extends('layouts.guest')

@section('title', 'دسترسی مجاز نیست')

@section('content')
    <div class="authcard">
        <div class="stack stack--sm">
            <div class="empty__icon" aria-hidden="true">⛔</div>
            <h1>دسترسی نداری</h1>
            <p class="authcard__sub">این صفحه برای نقش کاربری شما باز نیست (خطای ۴۰۳).</p>
        </div>

        <div class="alert alert--bad" role="alert">
            <span class="alert__icon">🔒</span>
            <span class="alert__body">
                <strong>اجازهٔ دیدن این بخش را ندارید</strong>
                <span>
                    @php($detail = trim((string) ($exception?->getMessage() ?? '')))
                    {{ $detail !== '' ? $detail : 'اگر فکر می‌کنید اشتباهی رخ داده، از مدیر سامانه بخواهید نقش شما را بررسی کند.' }}
                </span>
            </span>
        </div>

        @auth
            <div class="row">
                <a class="btn btn--primary" href="{{ url('/') }}">بازگشت به صفحهٔ اصلی</a>
                <span class="spacer"></span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="btn btn--ghost" type="submit">خروج از حساب</button>
                </form>
            </div>
            <p class="hint">
                وارد شده با حساب «{{ auth()->user()->name }}» — نقش: {{ auth()->user()->roleLabel() }}
            </p>
        @endauth

        @guest
            <div class="row">
                <a class="btn btn--primary" href="{{ route('login') }}">رفتن به صفحهٔ ورود</a>
            </div>
        @endguest
    </div>
@endsection
