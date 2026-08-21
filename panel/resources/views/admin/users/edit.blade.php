@extends('layouts.panel')

@section('title', 'ویرایش کاربر')
@section('page_title', 'ویرایش کاربر')

@section('content')
    <div class="page-head">
        <div>
            <h1>ویرایش «{{ $user->name }}»</h1>
        </div>
        <div class="page-head__actions">
            <a class="btn btn--ghost" href="{{ route('admin.users.index') }}">بازگشت به فهرست</a>
        </div>
        <p class="page-head__sub">
            نقش: {{ $user->roleLabel() }} —
            وضعیت: {{ $user->is_active ? 'فعال' : 'غیرفعال' }}
        </p>
    </div>

    @if ($isSelf)
        <div class="alert alert--info">
            <span class="alert__icon">ℹ️</span>
            <span class="alert__body">
                <strong>این حساب خودتان است</strong>
                <span>برای جلوگیری از قفل شدن سامانه، نقش و وضعیت حساب خودتان از اینجا قابل تغییر نیست.</span>
            </span>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.users.update', $user) }}" novalidate>
        @csrf
        @method('PUT')
        <div class="card">
            <div class="card__head">
                <h2>اطلاعات کاربر</h2>
                <span class="spacer"></span>
                @if ($user->is_active)
                    <span class="badge badge--ok badge--dot">فعال</span>
                @else
                    <span class="badge badge--bad badge--dot">غیرفعال</span>
                @endif
            </div>
            <div class="card__body">
                @include('admin.users._form', ['user' => $user, 'isSelf' => $isSelf, 'roles' => $roles])
            </div>
            <div class="card__foot">
                <div class="row row--end">
                    <a class="btn btn--ghost" href="{{ route('admin.users.index') }}">انصراف</a>
                    <button class="btn btn--primary" type="submit">ذخیرهٔ تغییرات</button>
                </div>
            </div>
        </div>
    </form>

    @if (! $isSelf && $user->is_active)
        <div class="card">
            <div class="card__head">
                <h3>غیرفعال‌سازی حساب</h3>
            </div>
            <div class="card__body">
                <p class="muted small">
                    حساب حذف نمی‌شود تا سابقهٔ پرونده‌ها و بررسی‌ها دست‌نخورده بماند؛ فقط امکان ورود از کاربر گرفته می‌شود.
                </p>
                <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                      onsubmit="return confirm('حساب «{{ $user->name }}» غیرفعال شود؟');">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn--danger" type="submit">غیرفعال‌سازی این کاربر</button>
                </form>
            </div>
        </div>
    @endif
@endsection
