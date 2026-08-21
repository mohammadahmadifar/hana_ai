@extends('layouts.panel')

@section('title', 'پرونده‌ها')
@section('page_title', $mine ? 'پرونده‌های من' : 'پرونده‌های همهٔ کاربران')

@section('topbar_actions')
    @if ($canSeeAll)
        <a href="{{ route('cases.index', $mine ? ['all' => 1] : []) }}" class="btn btn--ghost btn--sm">
            {{ $mine ? '👥 همهٔ کاربران' : '👤 فقط خودم' }}
        </a>
    @endif
    <a href="{{ route('cases.create') }}" class="btn btn--primary btn--sm">➕ درخواست جدید</a>
@endsection

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
@endphp

@section('content')

    @if ($cases->total() === 0)
        <div class="card">
            <div class="card__body">
                <x-empty-state
                    icon="📂"
                    title="هنوز پرونده‌ای ثبت نشده است"
                    hint="یک درخواست تازه بسازید: اول نوع خدمت را انتخاب می‌کنید و بعد مدارک همان خدمت را بارگذاری می‌کنید.">
                    <a href="{{ route('cases.create') }}" class="btn btn--primary btn--sm">➕ اولین درخواست را بساز</a>
                </x-empty-state>
            </div>
        </div>
    @else

        <div class="stack">

            <div class="row tiny faint">
                <span>در مجموع <x-num :value="$cases->total()" /> پرونده</span>
                <div class="spacer"></div>
                <span>صفحهٔ <x-num :value="$cases->currentPage()" /> از <x-num :value="$cases->lastPage()" /></span>
            </div>

            <div class="card">
                <div class="scroll-x">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>کد پرونده</th>
                                <th>نوع خدمت</th>
                                <th>متقاضی</th>
                                <th>وضعیت</th>
                                <th>مدارک</th>
                                <th>ساخته‌شده</th>
                                @unless ($mine)
                                    <th>کاربر</th>
                                @endunless
                                <th class="actions">کارها</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cases as $case)
                                @php
                                    $bar = $progress[$case->id] ?? ['ready' => 0, 'required' => 0, 'missing' => []];
                                    $incomplete = $case->status === 'draft' && $bar['missing'] !== [];
                                    $showUrl = PanelMenu::url('cases.show', ['case' => $case->id]);
                                @endphp

                                <tr>
                                    <td class="num nowrap">{{ PersianValue::toPersianDigits($case->code) }}</td>
                                    <td>{{ $case->serviceType?->label_fa ?? '—' }}</td>
                                    <td>{{ $case->applicant_name ?: '—' }}</td>
                                    <td>
                                        <x-badge :tone="$statusTone[$case->status] ?? 'info'"
                                                 label="{{ $incomplete ? 'ناقص' : $case->statusLabel() }}" />
                                    </td>
                                    <td class="nowrap">
                                        <span class="small">
                                            <x-num :value="$bar['ready']" /> از <x-num :value="$bar['required']" />
                                        </span>
                                        @if ($bar['missing'] !== [])
                                            <div class="tiny faint">مانده: {{ implode('، ', $bar['missing']) }}</div>
                                        @endif
                                    </td>
                                    <td><x-jdate :value="$case->created_at" /></td>
                                    @unless ($mine)
                                        <td class="small">{{ $case->user?->name ?? '—' }}</td>
                                    @endunless
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
            </div>

            @if ($cases->hasPages())
                <div class="row">
                    @if ($cases->onFirstPage())
                        <button type="button" class="btn btn--ghost btn--sm" disabled>→ صفحهٔ قبل</button>
                    @else
                        <a href="{{ $cases->previousPageUrl() }}" class="btn btn--ghost btn--sm">→ صفحهٔ قبل</a>
                    @endif

                    <div class="spacer"></div>

                    @if ($cases->hasMorePages())
                        <a href="{{ $cases->nextPageUrl() }}" class="btn btn--ghost btn--sm">صفحهٔ بعد ←</a>
                    @else
                        <button type="button" class="btn btn--ghost btn--sm" disabled>صفحهٔ بعد ←</button>
                    @endif
                </div>
            @endif

        </div>

    @endif

@endsection
