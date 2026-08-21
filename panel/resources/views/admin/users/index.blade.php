@extends('layouts.panel')

@section('title', 'کاربران سامانه')
@section('page_title', 'کاربران سامانه')

@section('content')
    @php
        // رقم فارسی برای شمارنده‌ها (تاریخ‌ها از کنترلر فارسی می‌آیند)
        $fa = fn ($n) => strtr((string) $n, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    @endphp
    <div class="page-head">
        <div>
            <h1>کاربران سامانه</h1>
        </div>
        <div class="page-head__actions">
            <a class="btn btn--primary" href="{{ route('admin.users.create') }}">افزودن کاربر</a>
        </div>
        <p class="page-head__sub">
            ثبت‌نام عمومی وجود ندارد؛ هر حساب کاربری از همین‌جا ساخته و مدیریت می‌شود.
        </p>
    </div>

    <div class="card">
        <div class="card__head">
            <h2>فهرست کاربران</h2>
            <span class="spacer"></span>
            <span class="badge badge--info">{{ $fa($users->count()) }} کاربر</span>
            <span class="badge badge--ok">{{ $fa($activeCount) }} فعال</span>
        </div>

        <div class="card__body">
            <form class="row" method="GET" action="{{ route('admin.users.index') }}">
                <input class="input" type="search" name="q" value="{{ $q }}"
                       placeholder="جست‌وجو در نام یا ایمیل" style="max-width:280px">
                <select class="select" name="role" style="max-width:200px">
                    <option value="">همهٔ نقش‌ها</option>
                    @foreach ($roles as $key => $label)
                        <option value="{{ $key }}" @selected($role === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <button class="btn" type="submit">اعمال</button>
                @if ($q !== '' || $role !== '')
                    <a class="btn btn--ghost" href="{{ route('admin.users.index') }}">پاک کردن</a>
                @endif
            </form>

            @if ($users->isEmpty())
                <div class="empty">
                    <div class="empty__icon">🔍</div>
                    <p>کاربری با این شرط پیدا نشد.</p>
                </div>
            @else
                <div class="scroll-x">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>نام</th>
                                <th>ایمیل</th>
                                <th>نقش</th>
                                <th>وضعیت</th>
                                <th>تاریخ ساخت</th>
                                <th class="actions">عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($users as $row)
                                <tr>
                                    <td class="num">{{ $fa($loop->iteration) }}</td>
                                    <td>
                                        <span class="strong">{{ $row->name }}</span>
                                        @if (auth()->user()->is($row))
                                            <span class="badge badge--info">شما</span>
                                        @endif
                                    </td>
                                    <td class="ltr">{{ $row->email }}</td>
                                    <td>{{ $row->roleLabel() }}</td>
                                    <td>
                                        @if ($row->is_active)
                                            <span class="badge badge--ok badge--dot">فعال</span>
                                        @else
                                            <span class="badge badge--bad badge--dot">غیرفعال</span>
                                        @endif
                                    </td>
                                    <td class="num nowrap">{{ $row->created_fa }}</td>
                                    <td class="actions">
                                        <a class="btn btn--sm" href="{{ route('admin.users.edit', $row) }}">ویرایش</a>
                                        @if (! auth()->user()->is($row) && $row->is_active)
                                            <form method="POST" action="{{ route('admin.users.destroy', $row) }}"
                                                  style="display:inline"
                                                  data-confirm-user="{{ $row->name }}">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn--sm btn--danger" type="submit">غیرفعال‌سازی</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="card__foot">
            <span class="small muted">
                هم‌اکنون {{ $fa($adminCount) }} مدیر سامانهٔ فعال وجود دارد. حساب خودتان را نمی‌توانید غیرفعال کنید یا نقشش را عوض کنید.
            </span>
        </div>
    </div>
@endsection

@push('scripts')
    @include('admin.users._confirm-script')
@endpush
