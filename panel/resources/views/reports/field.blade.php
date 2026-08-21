@extends('layouts.panel')

@section('title', 'ضعیف‌ترین خواندن‌های یک فیلد')
@section('page_title', 'فیلد: ' . ($fieldLabel ?: ($fieldKey ?: 'انتخاب‌نشده')))

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

    $confidenceTone = function (?float $value) use ($lowConfidence): ?string {
        if ($value === null) {
            return null;
        }

        return $value < $lowConfidence ? 'bad' : ($value < $lowConfidence + 20 ? 'warn' : 'ok');
    };

    $average = $summary?->avg_confidence === null ? null : (float) $summary->avg_confidence;
    $samples = (int) ($summary->samples ?? 0);
@endphp

@section('content')

    <div class="page-head">
        <h1>{{ $fieldLabel ?: ($fieldKey ?: '—') }}</h1>
        <div class="page-head__actions">
            <a class="btn btn--sm btn--ghost" href="{{ $reportsUrl }}">بازگشت به گزارش‌ها</a>
        </div>
        <p class="page-head__sub">
            @if ($fieldKey === '')
                کلید فیلد در نشانی نیامده است. از جدول «ضعیف‌ترین فیلدها در OCR» یک ردیف را باز کنید.
            @else
                ضعیف‌ترین خواندن‌های موتور برای کلید <span class="mono">{{ $fieldKey }}</span>،
                کم‌اطمینان‌ترین اول. همین فهرست می‌گوید موتور دقیقاً چه چیزی به‌جای مقدار درست خوانده است.
            @endif
        </p>
    </div>

    @if ($samples > 0)
        <div class="grid grid--4">
            <x-stat
                :value="$average === null ? '—' : PersianValue::decimal($average, 1).'٪'"
                label="میانگین اطمینان"
                note="روی همه خواندن‌های این فیلد"
                :tone="$confidenceTone($average)" />
            <x-stat
                :value="$samples"
                label="تعداد نمونه"
                note="هرچه کمتر، میانگین کم‌اتکاتر" />
            <x-stat
                :value="$summary->min_confidence === null ? '—' : PersianValue::decimal((float) $summary->min_confidence, 1).'٪'"
                label="کمینه اطمینان"
                note="بدترین خواندن ثبت‌شده"
                tone="bad" />
            <x-stat
                :value="$summary->max_confidence === null ? '—' : PersianValue::decimal((float) $summary->max_confidence, 1).'٪'"
                label="بیشینه اطمینان"
                note="بهترین خواندن ثبت‌شده"
                tone="ok" />
        </div>
    @endif

    <div class="card">
        <div class="card__head">
            <h2>خواندن‌های ثبت‌شده</h2>
            <span class="spacer"></span>
            @if ($samples > $limit)
                <span class="tiny faint">
                    ضعیف‌ترین <x-num :value="$limit" /> نمونه نمایش داده می‌شود.
                </span>
            @endif
        </div>

        @if ($rows->isEmpty())
            <div class="card__body">
                <x-empty-state
                    icon="🔎"
                    title="خواندنی برای این فیلد ثبت نشده است"
                    hint="ممکن است کلید فیلد اشتباه باشد یا هنوز OCR روی مدرکی که این فیلد را دارد اجرا نشده باشد.">
                    <a class="btn btn--sm btn--primary" href="{{ $reportsUrl }}">فهرست فیلدها</a>
                </x-empty-state>
            </div>
        @else
            <div class="scroll-x">
                <table class="table">
                    <thead>
                        <tr>
                            <th>اطمینان</th>
                            <th>کد پرونده</th>
                            <th>نوع مدرک</th>
                            <th>مقدار خام موتور</th>
                            <th>مقدار نرمال‌شده</th>
                            <th>منبع</th>
                            <th>وضعیت پرونده</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $case = $row->permitCase;
                                $caseUrl = $case ? PanelMenu::url('cases.show', ['case' => $case->getKey()]) : null;
                                $rowConfidence = (float) $row->confidence;
                            @endphp
                            <tr>
                                <td style="min-width:120px">
                                    <div class="stack stack--sm">
                                        <span class="nowrap"><x-num :value="$rowConfidence" :decimals="1" />٪</span>
                                        <x-bar :percent="$rowConfidence" :tone="$confidenceTone($rowConfidence)" label="اطمینان خواندن" />
                                    </div>
                                </td>
                                <td class="mono">
                                    @if ($case === null)
                                        <span class="faint">—</span>
                                    @elseif ($caseUrl !== null)
                                        <a href="{{ $caseUrl }}">{{ $case->code }}</a>
                                    @else
                                        {{ $case->code }}
                                    @endif
                                </td>
                                <td>{{ $row->caseDocument?->documentType?->label_fa ?? '—' }}</td>
                                <td class="small">{{ $row->raw_value !== null && $row->raw_value !== '' ? $row->raw_value : '—' }}</td>
                                <td class="small">{{ $row->normalized_value !== null && $row->normalized_value !== '' ? $row->normalized_value : '—' }}</td>
                                <td>
                                    @if ($row->source === 'manual')
                                        <x-badge tone="info" dot label="اصلاح کارشناس" />
                                    @else
                                        <x-badge dot label="موتور" />
                                    @endif
                                </td>
                                <td>
                                    @if ($case === null)
                                        <span class="faint">—</span>
                                    @else
                                        <x-badge :tone="$statusTone[$case->status] ?? null" dot :label="$case->statusLabel()" />
                                    @endif
                                </td>
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
