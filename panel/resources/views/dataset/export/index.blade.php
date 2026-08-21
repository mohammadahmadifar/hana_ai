@extends('layouts.panel')

@section('title', 'خروجی دیتاست')
@section('page_title', 'خروجی دیتاست برای آموزش مدل')

@section('content')
    @php
        $selectedTypes = $filters['document_type_ids'];
        $selectedSources = $filters['sources'];
        $selectedSplits = $filters['splits'];
        $selectedTags = $filters['tag_ids'];
        $readyCount = collect($exports)->where('status', 'done')->count();
    @endphp

    <div class="page-head">
        <div>
            <h1>خروجی دیتاست</h1>
        </div>
        <p class="page-head__sub">
            نمونه‌ها را فیلتر کنید، قالب آموزشی را انتخاب کنید و بسته را بسازید. ساخت بسته روی صف انجام می‌شود،
            پس می‌توانید صفحه را ببندید و بعداً برای دانلود برگردید.
        </p>
    </div>

    <div class="grid grid--4">
        <x-stat :value="$totalSamples" label="کل نمونه‌های دیتاست" />
        <x-stat :value="$preview['total']" label="منطبق با فیلتر فعلی" tone="info" note="پیش‌نمایش زنده" />
        <x-stat :value="$readyCount" label="بستهٔ آماده" tone="ok" />
        <x-stat :value="$pendingCount" label="در حال ساخت" :tone="$pendingCount > 0 ? 'warn' : null" />
    </div>

    <form method="POST" action="{{ route('dataset.export.store') }}" id="exportForm"
          data-preview-url="{{ route('dataset.export.preview') }}">
        @csrf

        <div class="grid grid--2">

            {{-- ---------------- ستون فیلترها ---------------- --}}
            <div class="card">
                <div class="card__head">
                    <h2>۱) انتخاب نمونه‌ها</h2>
                </div>
                <div class="card__body">

                    <div class="field">
                        <span class="label">نوع مدرک</span>
                        <div class="formgrid">
                            @foreach ($documentTypes as $type)
                                <label class="check">
                                    <input type="checkbox" name="document_type_ids[]" value="{{ $type->id }}"
                                           @checked(in_array($type->id, $selectedTypes, true))>
                                    <span>
                                        {{ $type->label_fa }}
                                        <span class="tiny faint">(<x-num :value="(int) ($countsPerType[$type->id] ?? 0)" /> نمونه)</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <span class="hint">اگر هیچ‌کدام را انتخاب نکنید، همهٔ انواع مدرک وارد بسته می‌شوند.</span>
                    </div>

                    <div class="formgrid">
                        <div class="field">
                            <span class="label">منبع نمونه</span>
                            @foreach ($sources as $key => $label)
                                <label class="check">
                                    <input type="checkbox" name="sources[]" value="{{ $key }}"
                                           @checked(in_array($key, $selectedSources, true))>
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>

                        <div class="field">
                            <span class="label">بخش دیتاست</span>
                            @foreach ($splits as $key => $label)
                                <label class="check">
                                    <input type="checkbox" name="splits[]" value="{{ $key }}"
                                           @checked(in_array($key, $selectedSplits, true))>
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="field">
                        <span class="label">تگ‌ها</span>
                        @if ($tags->isEmpty())
                            <span class="hint">هنوز تگی تعریف نشده است.</span>
                        @else
                            <div class="formgrid">
                                @foreach ($tags as $tag)
                                    <label class="check">
                                        <input type="checkbox" name="tag_ids[]" value="{{ $tag->id }}"
                                               @checked(in_array($tag->id, $selectedTags, true))>
                                        <span>{{ $tag->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <span class="hint">نمونه‌ای انتخاب می‌شود که دست‌کم یکی از تگ‌های تیک‌خورده را داشته باشد.</span>
                        @endif
                    </div>

                    <div class="field">
                        <span class="label">شرط‌های کیفیت</span>
                        <label class="check">
                            <input type="checkbox" name="verified_only" value="1" @checked($filters['verified_only'])>
                            <span>فقط نمونه‌های تاییدشده</span>
                        </label>
                        <label class="check">
                            <input type="checkbox" name="with_boxes_only" value="1" @checked($filters['with_boxes_only'])>
                            <span>فقط نمونه‌هایی که دست‌کم یک کادر دارند</span>
                        </label>
                    </div>

                </div>
            </div>

            {{-- ---------------- ستون قالب و ساخت ---------------- --}}
            <div class="card">
                <div class="card__head">
                    <h2>۲) قالب خروجی</h2>
                </div>
                <div class="card__body">

                    <div class="field">
                        @foreach ($formats as $key => $label)
                            <label class="check" style="align-items: flex-start">
                                <input type="radio" name="format" value="{{ $key }}"
                                       @checked($options['format'] === $key)>
                                <span>
                                    <span class="strong">{{ $label }}</span>
                                    <span class="hint" style="display:block">{{ $formatHints[$key] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <div class="field" id="imagesField">
                        {{-- مقدار صریح «۰» تا برداشتن تیک هم واقعاً به سرور برسد --}}
                        <input type="hidden" name="include_images" value="0">
                        <label class="check">
                            <input type="checkbox" name="include_images" value="1" id="includeImages"
                                   @checked($options['include_images'])>
                            <span>تصویرها هم داخل بسته باشند</span>
                        </label>
                        <span class="hint" id="imagesHint">
                            در قالب Tesseract به‌جای تصویر تمام‌کارت، تصویر بریده‌شدهٔ هر فیلدِ دارای کادر در بسته می‌آید.
                        </span>
                    </div>

                    <div class="field">
                        <label class="check">
                            <input type="checkbox" name="resplit" value="1" id="resplit" @checked($options['resplit'])>
                            <span>بازتقسیم train / val / test در همین بسته</span>
                        </label>
                        <span class="hint">
                            تقسیم قطعی و تکرارپذیر است و رکورد دیتابیس را تغییر نمی‌دهد؛ فقط مقدار split داخل بسته عوض می‌شود.
                        </span>

                        <div class="formgrid" id="ratioRow" @class(['hidden' => ! $options['resplit']])>
                            <div class="field">
                                <label for="train_ratio">درصد آموزش</label>
                                <input class="input input--ltr" type="number" min="0" max="100" step="1"
                                       id="train_ratio" name="train_ratio" value="{{ $options['ratios']['train'] }}">
                            </div>
                            <div class="field">
                                <label for="val_ratio">درصد اعتبارسنجی</label>
                                <input class="input input--ltr" type="number" min="0" max="100" step="1"
                                       id="val_ratio" name="val_ratio" value="{{ $options['ratios']['val'] }}">
                            </div>
                            <div class="field">
                                <label for="test_ratio">درصد آزمون</label>
                                <input class="input input--ltr" type="number" min="0" max="100" step="1"
                                       id="test_ratio" name="test_ratio" value="{{ $options['ratios']['test'] }}">
                            </div>
                        </div>
                        <span class="error hidden" id="ratioError">مجموع درصدها باید دقیقاً ۱۰۰ باشد.</span>
                    </div>

                    <div class="field">
                        <span class="label">پیش‌نمایش انتخاب</span>
                        <div class="readout" id="previewBox">@include('dataset.export.partials.preview', ['preview' => $preview, 'maxSamples' => $maxSamples])</div>
                    </div>

                    <div class="field">
                        <span class="label">سهمیهٔ ساخت</span>
                        <div class="tiny muted stack stack--sm">
                            <span>{{ $quota['note'] }} در این ساعت <x-num :value="$quota['left']" /> بسته باقی مانده است.</span>
                            @if ($quota['has_pending'])
                                <span class="text-warn">یک بستهٔ شما در حال ساخت است؛ تا پایان کارش درخواست تازه ثبت نمی‌شود.</span>
                            @endif
                        </div>
                    </div>

                </div>
                <div class="card__foot">
                    <button class="btn btn--primary" type="submit" id="submitBtn">ساخت بسته و افزودن به صف</button>
                    <span class="spacer"></span>
                    <span class="tiny faint">سقف هر بسته <x-num :value="$maxSamples" /> نمونه است.</span>
                </div>
            </div>

        </div>
    </form>

    {{-- ---------------- فهرست بسته‌ها ---------------- --}}
    <div class="card">
        <div class="card__head">
            <h2>بسته‌های ساخته‌شده</h2>
            <span class="spacer"></span>
            @if ($pendingCount > 0)
                <span class="badge badge--warn badge--dot"><x-num :value="$pendingCount" /> در حال ساخت</span>
            @endif
            <span class="badge badge--info"><x-num :value="count($exports)" /> بسته</span>
        </div>

        <div class="card__body">
            <p class="tiny muted">{{ $quota['retention'] }}</p>

            @if (count($exports) === 0)
                <x-empty-state icon="📦" title="هنوز بسته‌ای ساخته نشده"
                               hint="فیلترها را انتخاب کنید و دکمهٔ «ساخت بسته» را بزنید." />
            @else
                <div class="scroll-x">
                    <table class="table" id="exportsTable">
                        <thead>
                            <tr>
                                <th>شناسهٔ بسته</th>
                                <th>قالب</th>
                                <th>وضعیت</th>
                                <th>نمونه</th>
                                <th>حجم</th>
                                <th>سازنده</th>
                                <th>تاریخ</th>
                                <th class="actions">عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($exports as $export)
                                @php
                                    $counts = $export['counts'] ?? null;
                                    $progress = $export['progress'] ?? ['done' => 0, 'total' => 0];
                                    $percent = ($progress['total'] ?? 0) > 0
                                        ? min(100, round(100 * ($progress['done'] ?? 0) / $progress['total']))
                                        : 0;
                                @endphp
                                <tr data-token="{{ $export['token'] }}" data-status="{{ $export['status'] }}">
                                    <td>
                                        <span class="mono tiny ltr">{{ $export['token'] }}</span>
                                        @if (! empty($export['filters_fa']))
                                            <details>
                                                <summary class="tiny faint" style="cursor:pointer">فیلترهای این بسته</summary>
                                                <div class="tiny muted stack" style="margin-top:6px">
                                                    @foreach ($export['filters_fa'] as $line)
                                                        <span>• {{ $line }}</span>
                                                    @endforeach
                                                </div>
                                            </details>
                                        @endif
                                    </td>
                                    <td class="nowrap">{{ $export['format_label'] ?? ($formats[$export['format'] ?? 'json'] ?? '—') }}</td>
                                    <td>
                                        <span class="badge badge--{{ $export['status_tone'] }}" data-status-badge>
                                            {{ $export['status_label'] }}
                                        </span>
                                        @if ($export['is_pending'])
                                            <div style="margin-top:6px; min-width:110px">
                                                <x-bar :percent="$percent" tone="warn" label="پیشرفت ساخت" data-progress-bar />
                                            </div>
                                        @endif
                                        @if (($export['status'] ?? '') === 'failed' && ! empty($export['error']))
                                            <div class="tiny text-bad" style="margin-top:6px">{{ $export['error'] }}</div>
                                        @endif
                                    </td>
                                    <td class="num">
                                        @if (is_array($counts))
                                            <x-num :value="(int) ($counts['samples'] ?? 0)" />
                                            <span class="tiny faint">/ <x-num :value="(int) ($counts['boxes'] ?? 0)" /> کادر</span>
                                        @else
                                            <span class="faint">—</span>
                                        @endif
                                    </td>
                                    <td class="num nowrap">
                                        {{ $export['has_zip'] ? $export['size_human'] : '—' }}
                                    </td>
                                    <td class="nowrap">{{ $export['created_by']['name'] ?? '—' }}</td>
                                    <td class="nowrap"><x-jdate :value="$export['created_at'] ?? null" time /></td>
                                    <td class="actions">
                                        @if ($export['has_zip'])
                                            <a class="btn btn--sm btn--primary"
                                               href="{{ route('dataset.export.download', ['token' => $export['token']]) }}">دانلود</a>
                                        @else
                                            <span class="btn btn--sm is-disabled" aria-disabled="true">دانلود</span>
                                        @endif

                                        @if (! $export['is_pending'])
                                            <form method="POST" style="display:inline"
                                                  action="{{ route('dataset.export.destroy', ['token' => $export['token']]) }}"
                                                  data-confirm="این بسته حذف شود؟ فایل zip از روی دیسک پاک می‌شود." >
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn--sm btn--danger" type="submit">حذف</button>
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
    </div>
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    var form = document.getElementById('exportForm');
    if (!form) { return; }

    var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    /* ---------- ارقام فارسی ---------- */
    var faMap = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    function fa(value) {
        return String(value).replace(/[0-9]/g, function (d) { return faMap[+d]; });
    }
    function faNumber(value) {
        return fa(Number(value || 0).toLocaleString('en-US')).replace(/,/g, '٬');
    }

    /* ---------- گزینه‌های وابسته به قالب ---------- */
    var includeImages = document.getElementById('includeImages');
    var imagesHint = document.getElementById('imagesHint');
    var resplit = document.getElementById('resplit');
    var ratioRow = document.getElementById('ratioRow');
    var ratioError = document.getElementById('ratioError');
    var submitBtn = document.getElementById('submitBtn');

    function currentFormat() {
        var checked = form.querySelector('input[name="format"]:checked');
        return checked ? checked.value : 'json';
    }

    function syncOptions() {
        var isTesseract = currentFormat() === 'tesseract';

        includeImages.disabled = isTesseract;
        if (isTesseract) { includeImages.checked = true; }
        imagesHint.textContent = isTesseract
            ? 'در قالب Tesseract به‌جای تصویر تمام‌کارت، تصویر بریده‌شدهٔ هر فیلدِ دارای کادر در بسته می‌آید.'
            : 'اگر برداریدش، فقط dataset.json ساخته می‌شود و مسیر اصلی تصویر در کلید source_path می‌ماند.';

        ratioRow.classList.toggle('hidden', !resplit.checked);
        checkRatios();
    }

    function checkRatios() {
        if (!resplit.checked) {
            ratioError.classList.add('hidden');
            submitBtn.disabled = false;
            return;
        }

        var sum = ['train_ratio', 'val_ratio', 'test_ratio'].reduce(function (acc, id) {
            return acc + (parseInt(document.getElementById(id).value, 10) || 0);
        }, 0);

        var bad = sum !== 100;
        ratioError.classList.toggle('hidden', !bad);
        ratioError.textContent = 'مجموع درصدها باید دقیقاً ۱۰۰ باشد؛ الان ' + fa(sum) + ' است.';
        submitBtn.disabled = bad;
    }

    /* ---------- پیش‌نمایش زنده ---------- */
    var previewBox = document.getElementById('previewBox');
    var previewUrl = form.getAttribute('data-preview-url');
    var maxSamples = @json($maxSamples);
    var timer = null;
    var inflight = null;

    function renderPreview(data) {
        var parts = [];
        parts.push('<span class="strong">' + faNumber(data.total) + ' نمونه</span> با این فیلتر انتخاب می‌شود.');

        if (data.total > maxSamples) {
            parts.push('<span class="text-warn">فقط ' + faNumber(maxSamples) + ' نمونهٔ اول وارد بسته می‌شود.</span>');
        }

        if (data.total > 0) {
            parts.push('دارای کادر: ' + faNumber(data.with_boxes) + ' • تاییدشده: ' + faNumber(data.verified));
            var rows = (data.by_type || []).map(function (row) {
                return '• ' + row.label + ': ' + faNumber(row.count);
            });
            if (rows.length) { parts.push(rows.join('\n')); }
        } else {
            parts.push('<span class="text-warn">با این فیلتر نمونه‌ای پیدا نشد؛ بسته ساخته می‌شود ولی خالی خواهد بود.</span>');
        }

        previewBox.innerHTML = parts.join('\n');
    }

    function refreshPreview() {
        if (inflight) { inflight.abort(); }
        var controller = new AbortController();
        inflight = controller;

        previewBox.style.opacity = '0.55';

        fetch(previewUrl, {
            method: 'POST',
            credentials: 'same-origin',
            signal: controller.signal,
            headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                previewBox.style.opacity = '1';
                if (data) { renderPreview(data); }
            })
            .catch(function () { previewBox.style.opacity = '1'; });
    }

    form.addEventListener('change', function (event) {
        syncOptions();
        if (event.target.name === 'format' || event.target.name === 'include_images'
            || event.target.name === 'resplit' || /_ratio$/.test(event.target.name || '')) {
            return;
        }
        clearTimeout(timer);
        timer = setTimeout(refreshPreview, 220);
    });

    form.addEventListener('input', function (event) {
        if (/_ratio$/.test(event.target.name || '')) { checkRatios(); }
    });

    syncOptions();

    /* ---------- به‌روزرسانی وضعیت بسته‌ها ---------- */
    var pending = @json($pendingCount);
    if (pending > 0) {
        var statusUrl = @json(route('dataset.export.status'));

        var poll = setInterval(function () {
            fetch(statusUrl, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (response) { return response.ok ? response.json() : null; })
                .then(function (data) {
                    if (!data) { return; }

                    var stillPending = false;

                    data.items.forEach(function (item) {
                        var row = document.querySelector('tr[data-token="' + item.token + '"]');
                        if (!row) { return; }

                        if (item.status === 'queued' || item.status === 'running') {
                            stillPending = true;
                            var badge = row.querySelector('[data-status-badge]');
                            if (badge) {
                                badge.textContent = item.status_label
                                    + (item.total > 0 ? ' (' + fa(item.done) + '/' + fa(item.total) + ')' : '');
                            }
                            var bar = row.querySelector('[data-progress-bar] .bar__fill');
                            if (bar) { bar.style.width = item.percent + '%'; }
                        } else if (row.getAttribute('data-status') !== item.status) {
                            clearInterval(poll);
                            window.location.reload();
                        }
                    });

                    if (!stillPending) {
                        clearInterval(poll);
                        window.location.reload();
                    }
                })
                .catch(function () {});
        }, 4000);
    }
})();
</script>
@endpush
