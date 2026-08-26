@extends('layouts.panel')

@section('title', 'داشبورد')
@section('page_title', 'داشبورد')

@section('topbar_actions')
    <a href="{{ route('cases.create') }}" class="btn btn--primary btn--sm">➕ درخواست جدید</a>
@endsection

{{--
    داشبورد نقش «متقاضی».

    عمداً صفحهٔ جداست و شاخه‌ای از dashboard/index نیست: آن صفحه سراسری است
    (کل پرونده‌ها، میانگین امتیاز، توزیع وضعیت، صف بررسی) و هیچ‌کدام از آن
    اعداد برای متقاضی نه معنا دارد نه باید دیده شود. این‌جا هر عدد از
    DashboardController::applicantDashboard می‌آید که کوئری‌اش روی user_id
    خودِ همین کاربر بسته است.
--}}
@php
    use App\Support\PanelMenu;
    use App\Support\PersianValue;

    $statusTone = [
        'draft' => 'warn',
        'submitted' => 'info',
        'processing' => 'info',
        'needs_review' => 'warn',
        'approved' => 'ok',
        'rejected' => 'bad',
    ];

    $cards = [
        [
            'value' => $casesTotal,
            'label' => 'درخواست‌های من',
            'note' => 'همهٔ درخواست‌هایی که ثبت کرده‌اید',
            'tone' => null,
        ],
        [
            'value' => $inProgress,
            'label' => 'در جریان',
            'note' => 'ثبت‌شده، در حال پردازش، یا در نوبت کارشناس',
            'tone' => $inProgress > 0 ? 'warn' : null,
        ],
        [
            'value' => (int) $statusCounts->get('approved', 0),
            'label' => 'تاییدشده',
            'note' => 'درخواست‌هایی که نتیجه‌شان تایید بوده',
            'tone' => $statusCounts->get('approved', 0) > 0 ? 'ok' : null,
        ],
        [
            'value' => (int) $statusCounts->get('draft', 0),
            'label' => 'پیش‌نویس ناتمام',
            'note' => 'مدارکشان کامل نشده و هنوز ثبت نشده‌اند',
            'tone' => $statusCounts->get('draft', 0) > 0 ? 'warn' : null,
        ],
    ];
@endphp

@section('content')

    <div class="page-head">
        <h1>سلام، {{ $user->name }}</h1>
        <div class="page-head__actions">
            <x-badge tone="info" dot :label="$user->roleLabel()" />
        </div>
        <p class="page-head__sub">
            از این‌جا درخواست مجوز ثبت می‌کنید، مدارکش را بارگذاری می‌کنید و نتیجهٔ
            پیش‌اعتبارسنجی را می‌بینید. بررسی نهایی با کارشناس سامانه است.
        </p>
    </div>

    <div class="grid grid--4">
        @foreach ($cards as $card)
            <x-stat
                :value="$card['value']"
                :label="$card['label']"
                :note="$card['note']"
                :tone="$card['tone']" />
        @endforeach
    </div>

    <div class="card">
        <div class="card__head">
            <h2>درخواست تازه</h2>
        </div>
        <div class="card__body">
            <div class="row">
                <span aria-hidden="true" style="font-size:26px;line-height:1">➕</span>
                <div class="stack stack--sm" style="flex:1;min-width:180px">
                    <div class="strong">ثبت درخواست صدور یا تمدید مجوز</div>
                    <div class="small muted">
                        اول نوع خدمت را انتخاب می‌کنید، بعد مدارک لازمِ همان خدمت را بارگذاری
                        می‌کنید. مدارک هر خدمت را خود سامانه به شما می‌گوید.
                    </div>
                </div>
                <a class="btn btn--sm btn--primary" href="{{ route('cases.create') }}">درخواست جدید</a>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__head">
            <h2>آخرین درخواست‌های من</h2>
            @if ($casesTotal > 0)
                <x-badge dot><x-num :value="$casesTotal" /> پرونده</x-badge>
            @endif
            <span class="spacer"></span>
            <a class="btn btn--sm btn--ghost" href="{{ route('cases.index') }}">همهٔ پرونده‌ها</a>
        </div>

        @if ($cases->isEmpty())
            <div class="card__body">
                <x-empty-state
                    icon="📂"
                    title="هنوز درخواستی ثبت نکرده‌اید"
                    hint="با «درخواست جدید» شروع کنید؛ کل مسیر سه گام است و وضعیتش همین‌جا نمایش داده می‌شود.">
                    <a href="{{ route('cases.create') }}" class="btn btn--primary btn--sm">➕ اولین درخواست را بساز</a>
                </x-empty-state>
            </div>
        @else
            <div class="scroll-x">
                <table class="table">
                    <thead>
                        <tr>
                            <th>کد پرونده</th>
                            <th>نوع خدمت</th>
                            <th>وضعیت</th>
                            <th>امتیاز اطمینان</th>
                            <th>ثبت</th>
                            <th class="actions">کارها</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cases as $case)
                            @php
                                // اگر روت صفحهٔ نتیجه ثبت نشده باشد، ردیف بدون دکمه می‌ماند
                                // و صفحه ۵۰۰ نمی‌شود — همان قرارداد PanelMenu در بقیهٔ پنل.
                                $showUrl = PanelMenu::url('cases.show', ['case' => $case->id]);
                            @endphp
                            <tr>
                                <td class="num nowrap">{{ PersianValue::toPersianDigits($case->code) }}</td>
                                <td>{{ $case->serviceType?->label_fa ?? '—' }}</td>
                                <td>
                                    <x-badge :tone="$statusTone[$case->status] ?? 'info'"
                                             :label="$case->statusLabel()" />
                                </td>
                                <td class="num nowrap">
                                    {{ $case->confidence_score === null
                                        ? '—'
                                        : PersianValue::decimal((float) $case->confidence_score, 1).'٪' }}
                                </td>
                                <td><x-jdate :value="$case->submitted_at ?? $case->created_at" /></td>
                                <td class="actions">
                                    <a href="{{ route('cases.documents.edit', $case) }}" class="btn btn--ghost btn--sm">
                                        {{ $case->status === 'draft' ? '📎 ادامهٔ بارگذاری' : '📎 مدارک' }}
                                    </a>
                                    @if ($showUrl !== null)
                                        <a href="{{ $showUrl }}" class="btn btn--ghost btn--sm">👁 نتیجه</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

@endsection
