@extends('layouts.panel')

@section('title', 'صف تگ‌گذاری')
@section('page_title', 'تگ‌گذاری تصویری')

@push('head')
<style>
    /* ستون‌های صف — فقط از متغیرهای سامانه رنگ می‌گیرد */
    .qprog { display: flex; align-items: center; gap: 8px; min-width: 140px; }
    .qprog .bar { flex: 1; }
    .qprog__n { font-size: 12px; color: var(--ink-soft); white-space: nowrap; }
    .qfilters { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; }
    .qfilters .field { min-width: 150px; }
    .pager { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
</style>
@endpush

@section('content')
    @php
        $fa = fn ($n) => strtr((string) $n, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
        $sourceLabels = ['generated' => 'تولیدشده', 'uploaded' => 'آپلودشده'];
        $splitLabels = ['train' => 'آموزش', 'val' => 'ارزیابی', 'test' => 'آزمون'];
    @endphp

    <div class="page-head">
        <div>
            <h1>صف تگ‌گذاری</h1>
        </div>
        <div class="page-head__actions">
            @if ($samples->total() > 0 && $samples->count() > 0)
                <a class="btn btn--primary" href="{{ route('dataset.annotate.edit', $samples->first()) }}">
                    شروع از اولین نمونه
                </a>
            @endif
        </div>
        <p class="page-head__sub">
            نمونه‌هایی که هنوز تایید نشده‌اند یا مقدار فیلدهای الزامی‌شان کامل نیست. اولویت نمایش با
            نمونه‌های آپلودشده است، چون هیچ برچسبی همراهشان نیست.
        </p>
    </div>

    <div class="grid grid--4">
        <x-stat :value="$counters['pending']" label="در انتظار کار" tone="warn" note="الزامی ناقص یا تاییدنشده" />
        <x-stat :value="$counters['unlabeled']" label="بدون هیچ برچسب" tone="bad" note="کار از صفر" />
        <x-stat :value="$counters['uploaded']" label="آپلودشده در صف" tone="info" note="اولویت اول" />
        <x-stat :value="$counters['done']" label="کامل و تاییدشده" tone="ok" note="از صف خارج شده" />
    </div>

    <div class="card">
        <div class="card__head">
            <h2>فهرست نمونه‌ها</h2>
            <span class="spacer"></span>
            <span class="badge badge--info">{{ $fa($samples->total()) }} نمونه در این نما</span>
        </div>

        <div class="card__body">
            <form class="qfilters" method="GET" action="{{ route('dataset.annotate.index') }}">
                <div class="field">
                    <label class="label" for="f-status">وضعیت</label>
                    <select class="select" id="f-status" name="status">
                        @foreach ($statuses as $key => $label)
                            <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label class="label" for="f-type">نوع مدرک</label>
                    <select class="select" id="f-type" name="type">
                        <option value="">همه</option>
                        @foreach ($types as $documentType)
                            <option value="{{ $documentType->key }}" @selected($type === $documentType->key)>
                                {{ $documentType->label_fa }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label class="label" for="f-source">منبع</label>
                    <select class="select" id="f-source" name="source">
                        <option value="">همه</option>
                        @foreach ($sourceLabels as $key => $label)
                            <option value="{{ $key }}" @selected($source === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field" style="min-width:220px">
                    <label class="label" for="f-q">جست‌وجو</label>
                    <input class="input" id="f-q" type="search" name="q" value="{{ $q }}"
                           placeholder="شناسه، نام فایل یا یادداشت">
                </div>

                <div class="row">
                    <button class="btn" type="submit">اعمال</button>
                    @if ($status !== 'pending' || $type !== '' || $source !== '' || $q !== '')
                        <a class="btn btn--ghost" href="{{ route('dataset.annotate.index') }}">پاک کردن</a>
                    @endif
                </div>
            </form>

            @if ($samples->isEmpty())
                <x-empty-state icon="🏷" title="نمونه‌ای در این نما نیست"
                               hint="یا صف تگ‌گذاری خالی است، یا پالایه‌ها را باید بازتر کنید." >
                    @if ($status !== 'pending' || $type !== '' || $source !== '' || $q !== '')
                        <a class="btn btn--sm" href="{{ route('dataset.annotate.index') }}">نمایش کل صف</a>
                    @endif
                </x-empty-state>
            @else
                <div class="scroll-x">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>شناسه</th>
                                <th>نوع مدرک</th>
                                <th>منبع</th>
                                <th>پیشرفت برچسب</th>
                                <th>تایید</th>
                                <th>بخش</th>
                                <th>ابعاد</th>
                                <th>افزوده‌شده</th>
                                <th class="actions">عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($samples as $row)
                                @php
                                    $total = (int) ($fieldCounts[$row->document_type_id] ?? 0);
                                    $done = (int) $row->annotations_count;
                                    $percent = $total > 0 ? min(100, round($done * 100 / $total)) : 0;
                                    $tone = $done === 0 ? 'bad' : ($done < $total ? 'warn' : 'ok');
                                @endphp
                                <tr>
                                    <td class="num">#{{ $fa($row->id) }}</td>
                                    <td>
                                        <span class="strong">{{ $row->documentType?->label_fa ?? '—' }}</span>
                                        @if ($row->original_name)
                                            <div class="tiny faint nowrap">{{ \Illuminate\Support\Str::limit($row->original_name, 28) }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($row->source === 'uploaded')
                                            <span class="badge badge--info">آپلودشده</span>
                                        @else
                                            <span class="badge">تولیدشده</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="qprog">
                                            <x-bar :percent="$percent" :tone="$tone" label="پیشرفت برچسب" />
                                            <span class="qprog__n num">{{ $fa($done) }}/{{ $fa($total) }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        @if ($row->is_verified)
                                            <span class="badge badge--ok badge--dot">تاییدشده</span>
                                        @else
                                            <span class="badge badge--warn badge--dot">تاییدنشده</span>
                                        @endif
                                    </td>
                                    <td class="small">{{ $splitLabels[$row->split] ?? '—' }}</td>
                                    <td class="num tiny faint nowrap">
                                        {{ $row->width ? $fa($row->width).'×'.$fa($row->height) : '—' }}
                                    </td>
                                    <td><x-jdate :value="$row->created_at" class="tiny" /></td>
                                    <td class="actions">
                                        <a class="btn btn--primary btn--sm" href="{{ route('dataset.annotate.edit', $row) }}">
                                            تگ بزن
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @if ($samples->hasPages())
            <div class="card__foot">
                <div class="pager">
                    @if ($samples->onFirstPage())
                        <span class="btn btn--sm is-disabled" aria-disabled="true">صفحهٔ قبل</span>
                    @else
                        <a class="btn btn--sm" href="{{ $samples->previousPageUrl() }}" rel="prev">صفحهٔ قبل</a>
                    @endif

                    <span class="small muted">
                        صفحهٔ {{ $fa($samples->currentPage()) }} از {{ $fa($samples->lastPage()) }}
                    </span>

                    @if ($samples->hasMorePages())
                        <a class="btn btn--sm" href="{{ $samples->nextPageUrl() }}" rel="next">صفحهٔ بعد</a>
                    @else
                        <span class="btn btn--sm is-disabled" aria-disabled="true">صفحهٔ بعد</span>
                    @endif

                    <span class="spacer" style="flex:1"></span>
                    <span class="tiny faint">
                        نمایش {{ $fa($samples->firstItem() ?? 0) }} تا {{ $fa($samples->lastItem() ?? 0) }}
                        از {{ $fa($samples->total()) }}
                    </span>
                </div>
            </div>
        @endif
    </div>
@endsection
