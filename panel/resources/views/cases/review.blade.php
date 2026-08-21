@extends('layouts.panel')

@section('title', 'صف بررسی')
@section('page_title', 'صف بررسی انسانی')

@section('topbar_actions')
    <a href="{{ route('cases.index') }}" class="btn btn--ghost btn--sm">📂 همهٔ پرونده‌ها</a>
@endsection

@php
    use App\Services\Cases\CaseScorer;
    use App\Support\PersianValue;

    $thresholds = CaseScorer::thresholds();
@endphp

@section('content')

    <div class="stack">

        <div class="alert alert--info" role="status">
            <span class="alert__icon" aria-hidden="true">👤</span>
            <div class="alert__body">
                <strong>این‌ها پرونده‌هایی هستند که سامانه عمداً دربارهٔ آن‌ها تصمیم نگرفته است.</strong>
                <span>
                    امتیاز اطمینانشان بین آستانهٔ رد (<x-num :value="$thresholds['reject_below']" />)
                    و آستانهٔ تایید (<x-num :value="$thresholds['approve_at']" />) افتاده است.
                    قدیمی‌ترین پرونده بالای فهرست است.
                </span>
            </div>
        </div>

        <div class="grid grid--3">
            <x-stat :value="$summary['waiting']" label="پروندهٔ منتظر بررسی" tone="warn" />
            <x-stat :value="$summary['average'] ?? '—'"
                    label="میانگین امتیاز اطمینان صف"
                    note="از ۱۰۰" />
            <x-stat :value="$summary['oldest'] ? \App\Support\PersianValue::toPersianDigits((string) (int) $summary['oldest']->diffInDays(now())) : '—'"
                    label="عمر قدیمی‌ترین پرونده (روز)"
                    :tone="$summary['oldest'] && $summary['oldest']->diffInDays(now()) >= 3 ? 'bad' : null" />
        </div>

        @if ($cases->total() === 0)
            <div class="card">
                <div class="card__body">
                    <x-empty-state
                        icon="🎉"
                        title="صف بررسی خالی است"
                        hint="هیچ پرونده‌ای منتظر تصمیم انسانی نیست. پرونده‌ای که امتیازش از آستانهٔ تایید بالاتر یا از آستانهٔ رد پایین‌تر باشد، خودکار تعیین‌تکلیف می‌شود و به این صف نمی‌آید.">
                        <a href="{{ route('cases.index') }}" class="btn btn--ghost btn--sm">📂 دیدن همهٔ پرونده‌ها</a>
                    </x-empty-state>
                </div>
            </div>
        @else

            <div class="card">
                <div class="scroll-x">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>کد پرونده</th>
                                <th>نوع خدمت</th>
                                <th>متقاضی</th>
                                <th>امتیاز اطمینان</th>
                                <th>ایرادها</th>
                                <th>چرا به بررسی رسید</th>
                                <th>در انتظار از</th>
                                <th class="actions">کارها</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cases as $case)
                                @php
                                    $score = $case->confidence_score === null ? null : (float) $case->confidence_score;
                                    $tone = $score === null
                                        ? 'bad'
                                        : ($score >= $thresholds['approve_at'] ? 'ok' : ($score < $thresholds['reject_below'] ? 'bad' : 'warn'));
                                    $waitingSince = $case->submitted_at ?? $case->created_at;
                                @endphp
                                <tr>
                                    <td class="num nowrap">{{ PersianValue::toPersianDigits($case->code) }}</td>
                                    <td class="small">{{ $case->serviceType?->label_fa ?? '—' }}</td>
                                    <td class="small">{{ $case->applicant_name ?: '—' }}</td>
                                    <td style="min-width: 130px;">
                                        <div class="stack stack--sm">
                                            <span class="nowrap strong">
                                                @if ($score === null)
                                                    <span class="faint">محاسبه نشده</span>
                                                @else
                                                    <x-num :value="$score" :decimals="1" />
                                                @endif
                                            </span>
                                            <x-bar :percent="$score ?? 0" :tone="$tone"
                                                   label="امتیاز پروندهٔ {{ $case->code }}" />
                                        </div>
                                    </td>
                                    <td class="nowrap">
                                        @if ((int) $case->failed_count > 0)
                                            <x-badge tone="bad" label="{{ PersianValue::toPersianDigits((string) $case->failed_count) }} رد" />
                                        @endif
                                        @if ((int) $case->warning_count > 0)
                                            <x-badge tone="warn" label="{{ PersianValue::toPersianDigits((string) $case->warning_count) }} مشکوک" />
                                        @endif
                                        @if ((int) $case->failed_count === 0 && (int) $case->warning_count === 0)
                                            <span class="tiny faint">بدون ایراد</span>
                                        @endif
                                    </td>
                                    <td class="tiny faint">{{ \Illuminate\Support\Str::limit((string) $case->decision_reason, 120) ?: '—' }}</td>
                                    <td class="nowrap"><x-jdate :value="$waitingSince" time /></td>
                                    <td class="actions">
                                        <a href="{{ route('cases.show', $case) }}" class="btn btn--primary btn--sm">🔍 بررسی</a>
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

                    <span class="tiny faint">
                        صفحهٔ <x-num :value="$cases->currentPage()" /> از <x-num :value="$cases->lastPage()" />
                    </span>

                    <div class="spacer"></div>

                    @if ($cases->hasMorePages())
                        <a href="{{ $cases->nextPageUrl() }}" class="btn btn--ghost btn--sm">صفحهٔ بعد ←</a>
                    @else
                        <button type="button" class="btn btn--ghost btn--sm" disabled>صفحهٔ بعد ←</button>
                    @endif
                </div>
            @endif

        @endif

    </div>

@endsection
