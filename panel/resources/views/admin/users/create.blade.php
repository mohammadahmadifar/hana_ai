@extends('layouts.panel')

@section('title', 'افزودن کاربر')
@section('page_title', 'افزودن کاربر')

@section('content')
    <div class="page-head">
        <div>
            <h1>افزودن کاربر</h1>
        </div>
        <div class="page-head__actions">
            <a class="btn btn--ghost" href="{{ route('admin.users.index') }}">بازگشت به فهرست</a>
        </div>
        <p class="page-head__sub">حساب تازه بلافاصله می‌تواند با ایمیل و رمزی که اینجا تعیین می‌کنید وارد سامانه شود.</p>
    </div>

    <form method="POST" action="{{ route('admin.users.store') }}" novalidate>
        @csrf
        <div class="card">
            <div class="card__head">
                <h2>اطلاعات کاربر</h2>
            </div>
            <div class="card__body">
                @include('admin.users._form', ['user' => null, 'isSelf' => false, 'roles' => $roles])
            </div>
            <div class="card__foot">
                <div class="row row--end">
                    <a class="btn btn--ghost" href="{{ route('admin.users.index') }}">انصراف</a>
                    <button class="btn btn--primary" type="submit">ساخت کاربر</button>
                </div>
            </div>
        </div>
    </form>
@endsection
