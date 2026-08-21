@extends('layouts.panel')

@section('title', 'تولید انبوه نمونه')
@section('page_title', 'تولید انبوه نمونه دیتاست')

@section('content')
    @php
        $oldTypes = array_map('intval', (array) old('document_type_ids', $documentTypes->pluck('id')->all()));
        $oldTags = array_map('intval', (array) old('tag_ids', []));
        $oldAug = (array) old('aug', []);
        $oldClean = (bool) old('clean_only', false);
        $oldCount = (int) old('count', 20);
        $oldSplit = (string) old('split_mode', 'auto');
    @endphp

    <div class="page-head">
        <div>
            <h1>تولید انبوه نمونه دیتاست</h1>
        </div>
        <p class="page-head__sub">
            موتور برای هر نمونه یک شخص مصنوعی می‌سازد، مدرک‌های انتخاب‌شده را چاپ می‌کند و
            «کادر دقیق هر فیلد» را برمی‌گرداند؛ بنابراین برچسب‌گذاری بدون کار دستی انجام می‌شود.
            هیچ دادهٔ هویتی واقعی در این مسیر وجود ندارد.
        </p>
    </div>

    <form method="POST" action="{{ route('dataset.generate.store') }}" id="genForm">
        @csrf

        {{-- ۱) حجم و نوع مدرک --}}
        <div class="card">
            <div class="card__head">
                <h2>۱. چه چیزی ساخته شود؟</h2>
            </div>

            <div class="card__body">
                <div class="formgrid">
                    <div class="field">
                        <label class="label" for="count">
                            تعداد نمونه <span class="label__req">*</span>
                        </label>
                        <input class="input input--ltr @error('count') is-invalid @enderror"
                               id="count" name="count" type="number" dir="ltr"
                               min="1" max="200" step="1" value="{{ $oldCount }}" required>
                        <span class="hint">
                            هر «نمونه» یک شخص مصنوعی است؛ از هر شخص، همهٔ مدرک‌های انتخاب‌شده ساخته می‌شود.
                            بین ۱ تا ۲۰۰.
                        </span>
                        @error('count')
                            <span class="error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="field">
                        <span class="label">پیش‌بینی خروجی</span>
                        <div class="readout" id="estimate" aria-live="polite">—</div>
                        <span class="hint">
                            تعداد کل تصویر = تعداد نمونه × تعداد نوع مدرک. رندر هر تصویر حدود نیم‌ثانیه طول می‌کشد.
                        </span>
                    </div>
                </div>

                <div class="field">
                    <span class="label">نوع مدرک <span class="label__req">*</span></span>

                    @if ($documentTypes->isEmpty())
                        <x-empty-state icon="📄" title="هیچ نوع مدرک قابل تولیدی تعریف نشده"
                                       hint="برای تولید تصویر، نوع مدرک باید «قابل تولید» و «فعال» باشد." />
                    @else
                        <div class="grid grid--3">
                            @foreach ($documentTypes as $type)
                                <label class="check">
                                    <input type="checkbox" name="document_type_ids[]" value="{{ $type->id }}"
                                           class="js-doctype" @checked(in_array($type->id, $oldTypes, true))>
                                    <span>
                                        <span class="strong">{{ $type->label_fa }}</span>
                                        <span class="tiny faint ltr">{{ $type->key }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <span class="hint">
                            «مجوز قبلی» در این فهرست نیست؛ قالب تولیدی ندارد و فقط با بارگذاری تصویر واقعی وارد دیتاست می‌شود.
                        </span>
                    @endif

                    @error('document_type_ids')
                        <span class="error">{{ $message }}</span>
                    @enderror
                </div>
            </div>
        </div>

        {{-- ۲) اعوجاج --}}
        <div class="card">
            <div class="card__head">
                <h2>۲. اعوجاج تصویر</h2>
                <span class="spacer"></span>
                <span class="badge badge--info">شبیه‌سازی شرایط واقعی اسکن</span>
            </div>

            <div class="card__body">
                <div class="field">
                    <input type="hidden" name="clean_only" value="0">
                    <label class="check">
                        <input type="checkbox" name="clean_only" value="1" id="cleanOnly" @checked($oldClean)>
                        <span class="strong">بدون هیچ اعوجاجی — فقط نمونهٔ تمیز</span>
                    </label>
                    <span class="hint">
                        نمونهٔ تمیز برای سنجش «سقف دقت» مدل لازم است؛ اگر مدل روی تصویر تمیز هم اشتباه کند،
                        مشکل از اعوجاج نیست.
                    </span>
                </div>

                @error('aug')
                    <span class="error">{{ $message }}</span>
                @enderror

                <div id="augBlock" class="stack">
                    @foreach ($catalogue as $name => $meta)
                        @php
                            $row = (array) ($oldAug[$name] ?? []);
                            $enabled = filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                            $mode = ($row['mode'] ?? 'random') === 'fixed' ? 'fixed' : 'random';
                            $value = is_numeric($row['value'] ?? null) ? (float) $row['value'] : (float) $meta['default'];
                        @endphp

                        <div class="card" data-aug="{{ $name }}">
                            <div class="card__body">
                                <div class="row">
                                    <label class="check">
                                        <input type="checkbox" name="aug[{{ $name }}][enabled]" value="1"
                                               class="js-aug-enabled" @checked($enabled)>
                                        <span class="strong">
                                            <span aria-hidden="true">{{ $meta['icon'] }}</span>
                                            {{ $meta['label'] }}
                                        </span>
                                    </label>
                                    <span class="spacer"></span>
                                    <span class="tiny faint">{{ $meta['hint'] }}</span>
                                </div>

                                <div class="formgrid js-aug-body">
                                    <div class="field">
                                        <label class="label" for="mode_{{ $name }}">حالت مقدار</label>
                                        <select class="select js-aug-mode" id="mode_{{ $name }}"
                                                name="aug[{{ $name }}][mode]">
                                            <option value="random" @selected($mode === 'random')>تصادفی برای هر تصویر</option>
                                            <option value="fixed" @selected($mode === 'fixed')>مقدار مشخص</option>
                                        </select>
                                        <span class="hint">
                                            حالت تصادفی برای هر تصویر قرعهٔ تازه می‌زند و دیتاست را متنوع‌تر می‌کند.
                                        </span>
                                    </div>

                                    <div class="field js-aug-value">
                                        <label class="label" for="val_{{ $name }}">
                                            {{ $meta['param_label'] }}
                                            @if (filled($meta['unit']))
                                                <span class="muted small">({{ $meta['unit'] }})</span>
                                            @endif
                                        </label>
                                        <div class="row">
                                            <input class="range js-aug-range" id="val_{{ $name }}"
                                                   name="aug[{{ $name }}][value]" type="range"
                                                   min="{{ $meta['min'] }}" max="{{ $meta['max'] }}"
                                                   step="{{ $meta['step'] }}" value="{{ $value }}"
                                                   data-decimals="{{ $meta['decimals'] }}">
                                            <output class="readout js-aug-out" for="val_{{ $name }}"></output>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ۳) مقصد و تگ --}}
        <div class="card">
            <div class="card__head">
                <h2>۳. مقصد و برچسب اولیه</h2>
            </div>

            <div class="card__body">
                <div class="formgrid">
                    <div class="field">
                        <label class="label" for="split_mode">split مقصد <span class="label__req">*</span></label>
                        <select class="select @error('split_mode') is-invalid @enderror"
                                id="split_mode" name="split_mode" required>
                            @foreach ($splitModes as $key => $label)
                                <option value="{{ $key }}" @selected($oldSplit === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <span class="hint">
                            در حالت خودکار، همهٔ مدرک‌های یک شخص در یک split می‌مانند تا دادهٔ آموزش به آزمون نشت نکند.
                            برای دسته‌های کوچک‌تر از ۱۰ نمونه عملاً همه در train می‌مانند.
                        </span>
                        @error('split_mode')
                            <span class="error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="field">
                        <span class="label">تگ اولیه <span class="muted small">(اختیاری)</span></span>
                        @if ($tags->isEmpty())
                            <span class="hint">هنوز تگی تعریف نشده است.</span>
                        @else
                            <div class="stack stack--sm">
                                @foreach ($tags as $tag)
                                    <label class="check">
                                        <input type="checkbox" name="tag_ids[]" value="{{ $tag->id }}"
                                               @checked(in_array($tag->id, $oldTags, true))>
                                        <span>{{ $tag->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                        @error('tag_ids')
                            <span class="error">{{ $message }}</span>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="card__foot">
                <span class="small muted">
                    کار سنگین روی صف انجام می‌شود؛ بعد از ثبت، صفحهٔ پیشرفت باز می‌شود و می‌توانید همان‌جا منتظر بمانید.
                </span>
                <span class="spacer"></span>
                <button class="btn btn--primary" type="submit" id="submitBtn"
                        @disabled($documentTypes->isEmpty())>شروع تولید</button>
            </div>
        </div>
    </form>

    {{-- دسته‌های اخیر --}}
    <div class="card">
        <div class="card__head">
            <h2>دسته‌های اخیر</h2>
            <span class="spacer"></span>
            <span class="badge badge--info"><x-num :value="$recentBatches->count()" /> دسته</span>
        </div>

        <div class="card__body">
            @if ($recentBatches->isEmpty())
                <x-empty-state icon="⚙️" title="هنوز دسته‌ای تولید نشده"
                               hint="اولین دسته را از فرم بالا بسازید." />
            @else
                <div class="scroll-x">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>شناسه</th>
                                <th>سازنده</th>
                                <th>تعداد نمونه</th>
                                <th>انجام‌شده</th>
                                <th>ناموفق</th>
                                <th>وضعیت</th>
                                <th>پیشرفت</th>
                                <th>زمان ثبت</th>
                                <th class="actions">عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentBatches as $row)
                                @php
                                    $tone = \App\Jobs\GenerateDatasetSamples::statusTone($row->status);
                                @endphp
                                <tr>
                                    <td class="num">#<x-num :value="$row->id" /></td>
                                    <td>{{ $row->user?->name ?? '—' }}</td>
                                    <td class="num"><x-num :value="$row->count_requested" /></td>
                                    <td class="num text-ok"><x-num :value="$row->count_done" /></td>
                                    <td class="num {{ $row->count_failed > 0 ? 'text-bad' : 'faint' }}">
                                        <x-num :value="$row->count_failed" />
                                    </td>
                                    <td>
                                        <x-badge :tone="$tone" dot
                                                 :label="\App\Jobs\GenerateDatasetSamples::statusLabel($row->status)" />
                                    </td>
                                    <td style="min-width:120px">
                                        <x-bar :percent="$row->progressPercent()"
                                               :tone="$tone === 'info' ? null : $tone"
                                               label="پیشرفت دسته" />
                                    </td>
                                    <td><x-jdate :value="$row->created_at" time /></td>
                                    <td class="actions">
                                        <a class="btn btn--sm" href="{{ route('dataset.generate.show', $row) }}">جزئیات</a>
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

    var faDigits = function (text) {
        var map = {'0':'۰','1':'۱','2':'۲','3':'۳','4':'۴','5':'۵','6':'۶','7':'۷','8':'۸','9':'۹','.':'٫','-':'−'};
        return String(text).replace(/[0-9.\-]/g, function (ch) { return map[ch] || ch; });
    };

    var form = document.getElementById('genForm');
    if (!form) { return; }

    // ---- پیش‌بینی زندهٔ تعداد کل تصویر ----
    var countInput = document.getElementById('count');
    var estimate = document.getElementById('estimate');

    var refreshEstimate = function () {
        var count = parseInt(countInput.value, 10);
        var types = form.querySelectorAll('.js-doctype:checked').length;

        if (!count || count < 1 || types < 1) {
            estimate.textContent = 'ابتدا تعداد و نوع مدرک را مشخص کنید';
            return;
        }

        var total = count * types;
        estimate.textContent = faDigits(count) + ' نمونه × ' + faDigits(types) + ' نوع مدرک = '
            + faDigits(total) + ' تصویر';
    };

    countInput.addEventListener('input', refreshEstimate);
    Array.prototype.forEach.call(form.querySelectorAll('.js-doctype'), function (box) {
        box.addEventListener('change', refreshEstimate);
    });

    // ---- کارت‌های اعوجاج ----
    var cleanOnly = document.getElementById('cleanOnly');
    var augBlock = document.getElementById('augBlock');

    var refreshAugCard = function (card) {
        var enabled = card.querySelector('.js-aug-enabled');
        var body = card.querySelector('.js-aug-body');
        var mode = card.querySelector('.js-aug-mode');
        var valueField = card.querySelector('.js-aug-value');
        var range = card.querySelector('.js-aug-range');
        var out = card.querySelector('.js-aug-out');

        var off = cleanOnly.checked;

        enabled.disabled = off;
        if (off) { enabled.checked = false; }

        var on = enabled.checked && !off;

        body.classList.toggle('hidden', !on);
        mode.disabled = !on;
        range.disabled = !on || mode.value !== 'fixed';
        valueField.classList.toggle('hidden', mode.value !== 'fixed');

        var decimals = parseInt(range.getAttribute('data-decimals'), 10) || 0;
        out.textContent = faDigits(parseFloat(range.value).toFixed(decimals));

        card.style.opacity = off ? '0.55' : '1';
    };

    var refreshAllAug = function () {
        Array.prototype.forEach.call(augBlock.querySelectorAll('[data-aug]'), refreshAugCard);
    };

    Array.prototype.forEach.call(augBlock.querySelectorAll('[data-aug]'), function (card) {
        card.querySelector('.js-aug-enabled').addEventListener('change', function () { refreshAugCard(card); });
        card.querySelector('.js-aug-mode').addEventListener('change', function () { refreshAugCard(card); });
        card.querySelector('.js-aug-range').addEventListener('input', function () { refreshAugCard(card); });
    });

    cleanOnly.addEventListener('change', refreshAllAug);

    refreshEstimate();
    refreshAllAug();
})();
</script>
@endpush
