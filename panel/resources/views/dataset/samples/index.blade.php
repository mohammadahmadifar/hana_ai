@extends('layouts.panel')

@section('title', 'نمونه‌های دیتاست')
@section('page_title', 'نمونه‌های دیتاست')

@php
    use App\Http\Controllers\Dataset\SampleController;
    use App\Support\Jalali;

    $fa = fn ($n) => Jalali::digits($n);

    // رنگ تگ داده است، نه استایل؛ پس پیش از چاپ در style بررسی می‌شود.
    $safeColor = fn (?string $c) => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c) ? $c : '#64748b';

    $sourceTone = ['generated' => 'info', 'uploaded' => null];
    $splitTone = ['train' => null, 'val' => 'info', 'test' => 'warn'];

    // اگر هیچ تگی ثبت نشده باشد، عملیات تگ‌دار در فهرست کارهای دسته‌ای نمی‌آید.
    $usableBulkActions = $tags->isEmpty()
        ? collect($bulkActions)->except(['tag_add', 'tag_remove'])->all()
        : $bulkActions;
@endphp

@push('head')
    <style>
        .thumbcell { width: 66px; }
        .thumbcell .thumb { width: 66px; }
        .thumbcell .thumb img { height: 44px; object-fit: cover; }
        .filterbar { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; align-items: end; }
        .bulkbar { border: 1px solid var(--rule-soft); background: var(--surface-2); border-radius: var(--r-sm, 8px); padding: 10px 12px; }
        .tagline { display: flex; flex-wrap: wrap; gap: 4px; }
        .tagdot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-inline-end: 5px; vertical-align: middle; }
    </style>
@endpush

@section('content')

    <div class="page-head">
        <div>
            <h1>نمونه‌های دیتاست</h1>
        </div>
        <div class="page-head__actions">
            @if (\Illuminate\Support\Facades\Route::has('dataset.generate.create'))
                <a class="btn btn--primary" href="{{ route('dataset.generate.create') }}">تولید انبوه</a>
            @endif
            <a class="btn btn--ghost" href="{{ route('dataset.tags.index') }}">مدیریت تگ‌ها</a>
        </div>
        <p class="page-head__sub">
            هر ردیف یک تصویر مدرک مصنوعی با برچسب‌های فیلد آن است. از همین‌جا می‌توانید
            نمونه‌ها را پالایش کنید، تگ بزنید، بخش دیتاست را عوض کنید یا حذفشان کنید.
        </p>
    </div>

    <div class="grid grid--4">
        <x-stat :value="$totals['total']" label="کل نمونه‌ها" note="همهٔ انواع مدرک" />
        <x-stat :value="$totals['generated']" label="تولیدشده" note="خروجی موتور تولید" tone="info" />
        <x-stat :value="$totals['uploaded']" label="آپلودی" note="تصویر بارگذاری‌شده" />
        <x-stat :value="$totals['verified']" label="تاییدشده" note="برچسب‌هایش بازبینی شده"
                :tone="$totals['verified'] > 0 ? 'ok' : null" />
    </div>

    <div class="card">
        <div class="card__head">
            <h2>پالایش</h2>
            <span class="spacer"></span>
            @if ($hasAnyFilter)
                <a class="btn btn--sm btn--ghost" href="{{ route('dataset.samples.index') }}">پاک کردن پالایه‌ها</a>
            @endif
        </div>
        <div class="card__body">
            <form method="GET" action="{{ route('dataset.samples.index') }}">
                <div class="filterbar">
                    <div class="field">
                        <label class="label" for="f-type">نوع مدرک</label>
                        <select class="select" id="f-type" name="type">
                            <option value="">همه</option>
                            @foreach ($documentTypes as $documentType)
                                <option value="{{ $documentType->id }}" @selected($filters['type'] === (string) $documentType->id)>
                                    {{ $documentType->label_fa }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label class="label" for="f-source">منبع</label>
                        <select class="select" id="f-source" name="source">
                            <option value="">همه</option>
                            @foreach ($sources as $key => $label)
                                <option value="{{ $key }}" @selected($filters['source'] === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label class="label" for="f-aug">اعوجاج</label>
                        <select class="select" id="f-aug" name="aug">
                            <option value="">همه</option>
                            @foreach ($augmentationOptions as $key => $label)
                                <option value="{{ $key }}" @selected($filters['aug'] === (string) $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label class="label" for="f-split">بخش دیتاست</label>
                        <select class="select" id="f-split" name="split">
                            <option value="">همه</option>
                            @foreach ($splits as $key => $label)
                                <option value="{{ $key }}" @selected($filters['split'] === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label class="label" for="f-verified">وضعیت تایید</label>
                        <select class="select" id="f-verified" name="verified">
                            <option value="">همه</option>
                            <option value="1" @selected($filters['verified'] === '1')>تاییدشده</option>
                            <option value="0" @selected($filters['verified'] === '0')>تاییدنشده</option>
                        </select>
                    </div>

                    <div class="field">
                        <label class="label" for="f-tag">تگ</label>
                        <select class="select" id="f-tag" name="tag">
                            <option value="">همه</option>
                            @foreach ($tags as $tag)
                                <option value="{{ $tag->id }}" @selected($filters['tag'] === (string) $tag->id)>{{ $tag->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label class="label">&nbsp;</label>
                        <button class="btn btn--primary btn--block" type="submit">اعمال پالایه</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card__head">
            <h2>فهرست نمونه‌ها</h2>
            <span class="spacer"></span>
            <span class="badge badge--info">{{ $fa($samples->total()) }} نتیجه</span>
        </div>

        @if ($samples->isEmpty())
            <div class="card__body">
                @if ($samples->total() > 0)
                    <x-empty-state
                        icon="📄"
                        title="این صفحه خالی است"
                        hint="شمارهٔ صفحه از آخرین صفحهٔ نتیجه‌ها بزرگ‌تر است.">
                        <a class="btn btn--sm btn--primary" href="{{ $samples->url(1) }}">رفتن به صفحهٔ اول</a>
                    </x-empty-state>
                @elseif ($hasAnyFilter)
                    <x-empty-state
                        icon="🔍"
                        title="با این پالایه‌ها نمونه‌ای پیدا نشد"
                        hint="شرط‌ها را ساده‌تر کنید یا پالایه‌ها را پاک کنید.">
                        <a class="btn btn--sm" href="{{ route('dataset.samples.index') }}">پاک کردن پالایه‌ها</a>
                    </x-empty-state>
                @else
                    <x-empty-state
                        icon="🗂"
                        title="هنوز هیچ نمونه‌ای در دیتاست نیست"
                        hint="با «تولید انبوه» چند ده نمونهٔ مصنوعی بسازید تا فهرست پر شود.">
                        @if (\Illuminate\Support\Facades\Route::has('dataset.generate.create'))
                            <a class="btn btn--sm btn--primary" href="{{ route('dataset.generate.create') }}">رفتن به تولید انبوه</a>
                        @else
                            <span class="btn btn--sm is-disabled" aria-disabled="true">تولید انبوه — به‌زودی</span>
                        @endif
                    </x-empty-state>
                @endif
            </div>
        @else
            <div class="card__body">
                <form method="POST" action="{{ route('dataset.samples.bulk') }}" id="bulk-form">
                    @csrf

                    <div class="bulkbar row">
                        <span class="small strong nowrap">عملیات دسته‌ای:</span>

                        <select class="select" name="action" id="bulk-action" style="max-width:190px">
                            @foreach ($usableBulkActions as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>

                        @if ($tags->isNotEmpty())
                            <select class="select" name="tag_id" id="bulk-tag" style="max-width:190px">
                                @foreach ($tags as $tag)
                                    <option value="{{ $tag->id }}">{{ $tag->name }}</option>
                                @endforeach
                            </select>
                        @endif

                        <select class="select" name="split" id="bulk-split" style="max-width:190px">
                            @foreach ($splits as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>

                        <button class="btn btn--sm btn--primary" type="submit">اجرا روی انتخاب‌شده‌ها</button>
                        <span class="spacer"></span>
                        <span class="small muted" id="bulk-count">هیچ نمونه‌ای انتخاب نشده</span>
                    </div>

                    <div class="scroll-x">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width:34px">
                                        <input type="checkbox" id="bulk-all" aria-label="انتخاب همهٔ ردیف‌های این صفحه">
                                    </th>
                                    <th>تصویر</th>
                                    <th>#</th>
                                    <th>نوع مدرک</th>
                                    <th>منبع</th>
                                    <th>اعوجاج</th>
                                    <th>برچسب</th>
                                    <th>تگ‌ها</th>
                                    <th>بخش</th>
                                    <th>تایید</th>
                                    <th>تاریخ ثبت</th>
                                    <th class="actions">عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($samples as $sample)
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="js-pick" name="ids[]" value="{{ $sample->id }}"
                                                   aria-label="انتخاب نمونهٔ {{ $sample->id }}">
                                        </td>
                                        <td class="thumbcell">
                                            <a class="thumb" href="{{ route('dataset.samples.show', $sample) }}">
                                                <img src="{{ route('media', ['disk' => $sample->disk, 'path' => $sample->path]) }}"
                                                     alt="تصویر نمونهٔ {{ $sample->id }}" loading="lazy">
                                            </a>
                                        </td>
                                        <td class="num">{{ $fa($sample->id) }}</td>
                                        <td>{{ $sample->documentType?->label_fa ?? '—' }}</td>
                                        <td>
                                            <x-badge :tone="$sourceTone[$sample->source] ?? null"
                                                     :label="$sources[$sample->source] ?? $sample->source" />
                                        </td>
                                        <td class="small">{{ SampleController::augmentationLabel($sample->augmentation) }}</td>
                                        <td class="num">{{ $fa($sample->annotations_count) }}</td>
                                        <td>
                                            @if ($sample->tags->isEmpty())
                                                <span class="faint">—</span>
                                            @else
                                                <span class="tagline">
                                                    @foreach ($sample->tags as $tag)
                                                        <span class="badge">
                                                            <span class="tagdot" style="background: {{ $safeColor($tag->color) }}"></span>{{ $tag->name }}
                                                        </span>
                                                    @endforeach
                                                </span>
                                            @endif
                                        </td>
                                        <td>
                                            <x-badge :tone="$splitTone[$sample->split] ?? null"
                                                     :label="$splits[$sample->split] ?? $sample->split" />
                                        </td>
                                        <td>
                                            @if ($sample->is_verified)
                                                <span class="badge badge--ok badge--dot">تاییدشده</span>
                                            @else
                                                <span class="badge badge--warn badge--dot">تاییدنشده</span>
                                            @endif
                                        </td>
                                        <td class="num nowrap">{{ Jalali::format($sample->created_at) }}</td>
                                        <td class="actions">
                                            <a class="btn btn--sm" href="{{ route('dataset.samples.show', $sample) }}">جزئیات</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>

            <div class="card__foot">
                @include('dataset.samples._pager', ['paginator' => $samples])
            </div>
        @endif
    </div>

@endsection

@push('scripts')
    <script>
        (function () {
            var form = document.getElementById('bulk-form');
            if (!form) { return; }

            var all = document.getElementById('bulk-all');
            var picks = Array.prototype.slice.call(form.querySelectorAll('.js-pick'));
            var action = document.getElementById('bulk-action');
            var tagBox = document.getElementById('bulk-tag');
            var splitBox = document.getElementById('bulk-split');
            var counter = document.getElementById('bulk-count');

            var faDigits = function (n) {
                return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
            };

            var selected = function () {
                return picks.filter(function (box) { return box.checked; }).length;
            };

            var refresh = function () {
                var n = selected();
                counter.textContent = n === 0
                    ? 'هیچ نمونه‌ای انتخاب نشده'
                    : faDigits(n) + ' نمونه انتخاب شده';

                if (all) {
                    all.checked = n > 0 && n === picks.length;
                    all.indeterminate = n > 0 && n < picks.length;
                }

                // فقط ورودی مربوط به همان عملیات دیده شود.
                if (tagBox) { tagBox.classList.toggle('hidden', action.value !== 'tag_add' && action.value !== 'tag_remove'); }
                if (splitBox) { splitBox.classList.toggle('hidden', action.value !== 'split'); }
            };

            if (all) {
                all.addEventListener('change', function () {
                    picks.forEach(function (box) { box.checked = all.checked; });
                    refresh();
                });
            }

            picks.forEach(function (box) { box.addEventListener('change', refresh); });
            action.addEventListener('change', refresh);

            form.addEventListener('submit', function (event) {
                var n = selected();

                if (n === 0) {
                    event.preventDefault();
                    window.alert('اول دست‌کم یک نمونه را انتخاب کنید.');
                    return;
                }

                var label = action.options[action.selectedIndex].text;
                var question = action.value === 'delete'
                    ? faDigits(n) + ' نمونه به‌همراه فایل‌هایشان برای همیشه حذف شوند؟'
                    : '«' + label + '» روی ' + faDigits(n) + ' نمونه اجرا شود؟';

                if (!window.confirm(question)) {
                    event.preventDefault();
                }
            });

            refresh();
        })();
    </script>
@endpush
