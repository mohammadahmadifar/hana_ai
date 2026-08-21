@extends('layouts.panel')

@section('title', 'پرونده‌های ردشده در یک قانون')
@section('page_title', 'دلیل رد: ' . ($ruleKey ?: 'انتخاب‌نشده'))

@php
    use App\Support\PanelMenu;
    use App\Support\PersianValue;

    $reportsUrl = route('reports.index');

    $statusTone = [
        'draft' => null,
        'submitted' => 'info',
        'processing' => 'info',
        'needs_review' => 'warn',
        'approved' => 'ok',
        'rejected' => 'bad',
    ];

    /** حوزه قانون از خود کلید خوانده می‌شود (قرارداد rule_key سند پایپ‌لاین). */
    $scope = explode('.', $ruleKey)[0] ?? '';
@endphp

@section('content')

    <div class="page-head">
        <h1 class="mono">{{ $ruleKey ?: '—' }}</h1>
        <div class="page-head__actions">
            <a class="btn btn--sm btn--ghost" href="{{ $reportsUrl }}">بازگشت به گزارش‌ها</a>
        </div>
        <p class="page-head__sub">
            @if ($ruleKey === '')
                کلید قانون در نشانی نیامده است. از جدول «پرفراوان‌ترین دلایل رد» یک ردیف را باز کنید.
            @else
                پرونده‌هایی که این بررسی رویشان نتیجه «رد» داده است.
                @if (isset($scopeLabels[$scope]))
                    حوزه بررسی: {{ $scopeLabels[$scope] }}.
                @endif
            @endif
        </p>
    </div>

    <div class="card">
        <div class="card__head">
            <h2>پرونده‌های درگیر</h2>
            @if ($total > 0)
                <x-badge tone="bad" dot><x-num :value="$total" /> ردیف</x-badge>
            @endif
            <span class="spacer"></span>
            @if ($total > $limit)
                <span class="tiny faint">
                    تازه‌ترین <x-num :value="$limit" /> ردیف نمایش داده می‌شود.
                </span>
            @endif
        </div>

        @if ($rows->isEmpty())
            <div class="card__body">
                <x-empty-state
                    icon="📭"
                    title="ردیفی برای این قانون پیدا نشد"
                    hint="ممکن است کلید قانون اشتباه باشد یا ردیف‌های قدیمی با اجرای دوباره اعتبارسنجی پاک شده باشند.">
                    <a class="btn btn--sm btn--primary" href="{{ $reportsUrl }}">فهرست دلایل رد</a>
                </x-empty-state>
            </div>
        @else
            <div class="scroll-x">
                <table class="table">
                    <thead>
                        <tr>
                            <th>کد پرونده</th>
                            <th>متقاضی</th>
                            <th>وضعیت پرونده</th>
                            <th>امتیاز اطمینان</th>
                            <th>پیام</th>
                            <th>تاریخ ثبت ایراد</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $case = $row->permitCase;
                                $caseUrl = $case ? PanelMenu::url('cases.show', ['case' => $case->getKey()]) : null;
                            @endphp
                            <tr>
                                <td class="mono">
                                    @if ($case === null)
                                        <span class="faint">—</span>
                                    @elseif ($caseUrl !== null)
                                        <a href="{{ $caseUrl }}">{{ $case->code }}</a>
                                    @else
                                        {{ $case->code }}
                                    @endif
                                </td>
                                <td>{{ $case?->applicant_name ?: '—' }}</td>
                                <td>
                                    @if ($case === null)
                                        <span class="faint">—</span>
                                    @else
                                        <x-badge :tone="$statusTone[$case->status] ?? null" dot :label="$case->statusLabel()" />
                                    @endif
                                </td>
                                <td class="nowrap">
                                    @if ($case?->confidence_score === null)
                                        <span class="faint">—</span>
                                    @else
                                        <x-num :value="$case->confidence_score" :decimals="1" />٪
                                    @endif
                                </td>
                                <td class="small muted">{{ $row->message_fa ?: '—' }}</td>
                                <td><x-jdate :value="$row->created_at" time /></td>
                                <td>
                                    @if ($caseUrl !== null)
                                        <a class="btn btn--sm btn--primary" href="{{ $caseUrl }}">پرونده</a>
                                    @else
                                        <span class="btn btn--sm is-disabled" aria-disabled="true">به‌زودی</span>
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
