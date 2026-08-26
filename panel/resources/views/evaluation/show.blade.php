@extends('layouts.panel')

@section('title', 'نتیجهٔ ارزیابی دقت')
@section('page_title', 'نتیجهٔ ارزیابی دقت')

@section('topbar_actions')
    <a class="btn btn--ghost" href="{{ route('evaluation.create') }}">ارزیابی تازه</a>
@endsection

@section('content')
    @php
        use App\Support\PersianValue;

        $tone = \App\Jobs\RunAccuracyEvaluation::statusTone($run->status);
        $label = \App\Jobs\RunAccuracyEvaluation::statusLabel($run->status);
        $finished = $run->isFinished();
        $accuracy = $run->accuracy();
        $accuracyTone = $accuracy === null ? 'info' : ($accuracy >= 90 ? 'ok' : ($accuracy >= 75 ? 'warn' : 'bad'));

        // مقدارِ چندتکه (عدد + علامت) از مسیر عددیِ x-stat رد نمی‌شود، پس
        // ارقامش باید همین‌جا فارسی شوند — قانون پروژه ۱۲۸.
        //
        // برای عدد اعشاری حتماً decimal(): toPersianDigits فقط رقم را نگاشت
        // می‌کند و نقطهٔ لاتین «۷۲.۸» را جا می‌گذارد، در حالی که جداکنندهٔ
        // فارسی «٫» است — همان چیزی که JS این صفحه هم می‌سازد.
        $fa = fn ($value): string => PersianValue::toPersianDigits((string) $value);
        $faDecimal = fn ($value): string => PersianValue::decimal((float) $value, 1);
    @endphp

    <div class="page-head">
        <div>
            <h1>ارزیابی #<x-num :value="$run->id" /></h1>
        </div>
        <div class="page-head__actions">
            <a class="btn" href="{{ route('evaluation.show', $run) }}">بازخوانی</a>
        </div>
        <p class="page-head__sub">
            ثبت‌شده توسط {{ $run->user?->name ?? '—' }} در <x-jdate :value="$run->created_at" time />.
            تا وقتی کار تمام نشده، اعداد این صفحه هر ۳ ثانیه خودشان به‌روز می‌شوند.
        </p>
    </div>

    <div class="alert alert--bad {{ filled($run->error) ? '' : 'hidden' }}" role="alert" id="runAlert">
        <span class="alert__icon" aria-hidden="true">⛔</span>
        <div class="alert__body">
            <strong>در جریان این ارزیابی مشکلی پیش آمد</strong>
            <span id="runError">{{ $run->error }}</span>
        </div>
    </div>

    <div class="card">
        <div class="card__head">
            <h2>نتیجه</h2>
            <span class="spacer"></span>
            <x-badge id="statusBadge" :tone="$tone" dot :label="$label" />
        </div>

        <div class="card__body">
            <div class="grid grid--4">
                <x-stat id="statAccuracy"
                        :value="$accuracy === null ? '—' : $faDecimal($accuracy).'٪'"
                        label="دقت کل"
                        note="درصد فیلدهایی که دقیقاً همان چیزی خوانده شدند که چاپ شده بود"
                        :tone="$accuracyTone" />
                <x-stat id="statFields"
                        :value="$fa($run->fields_correct).' از '.$fa($run->fields_total)"
                        label="فیلد درست"
                        note="فیلدی که روی قالب چاپ نمی‌شود اصلاً سنجیده نمی‌شود" />
                <x-stat id="statConfidence"
                        :value="$run->averageConfidence() === null ? '—' : $faDecimal($run->averageConfidence())"
                        label="میانگین اطمینان"
                        note="عددی که خودِ استخراج‌گر به خواندنش می‌دهد (۰ تا ۱۰۰)" tone="info" />
                <x-stat id="statImages"
                        :value="$run->count_done"
                        label="تصویر خوانده‌شده"
                        :note="'از '.$fa($run->count_requested).' تصویر درخواستی'" />
            </div>

            <x-bar id="progressBar" :percent="$run->progressPercent()"
                   :tone="$tone === 'info' ? null : $tone" label="پیشرفت ارزیابی" />

            <div class="row">
                <span class="small muted">
                    تصویر ناموفق:
                    <span class="strong num" id="statFailed"><x-num :value="$run->count_failed" /></span>
                    — تصویری که ساخته یا خوانده نشد و هیچ فیلدی از آن وارد این درصد نشده است.
                </span>
                <span class="spacer"></span>
                <span class="small muted">
                    حالت تصویر: <span class="strong">{{ $augmentLabel }}</span>
                </span>
            </div>
        </div>

        <div id="finishFoot" @class(['card__foot', 'hidden' => ! $finished])>
            <span class="small muted">
                @if ($run->finished_at)
                    پایان در <x-jdate :value="$run->finished_at" time />.
                @else
                    کار تمام شد.
                @endif
            </span>
            <span class="spacer"></span>
            <a class="btn" href="{{ route('evaluation.create') }}">ارزیابی تازه</a>
        </div>
    </div>

    @if ($rows !== [])
        <div class="card">
            <div class="card__head">
                <h2>دقت به تفکیک مدرک و فیلد</h2>
                <span class="spacer"></span>
                <span class="tiny faint">ضعیف‌ترین بالای فهرست</span>
            </div>
            <div class="card__body stack">
                @foreach ($rows as $row)
                    <div class="field">
                        <div class="row">
                            <span class="label" style="margin:0">{{ $row['label'] }}</span>
                            <span class="spacer"></span>
                            <span class="small muted">
                                <x-num :value="$row['samples']" /> تصویر ·
                                <span class="strong num"><x-num :value="$row['accuracy']" :decimals="1" />٪</span>
                                (<x-num :value="$row['correct']" /> از <x-num :value="$row['count']" />)
                            </span>
                        </div>

                        <x-bar :percent="$row['accuracy']"
                               :tone="$row['accuracy'] >= 90 ? 'ok' : ($row['accuracy'] >= 75 ? 'warn' : 'bad')"
                               label="دقت {{ $row['label'] }}" />

                        <div class="scroll-x">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>فیلد</th>
                                        <th>سنجیده‌شده</th>
                                        <th>درست</th>
                                        <th>دقت</th>
                                        <th>میانگین اطمینان</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($row['fields'] as $field)
                                        <tr>
                                            <td>{{ $field['label'] }}</td>
                                            <td class="num"><x-num :value="$field['count']" /></td>
                                            <td class="num"><x-num :value="$field['correct']" /></td>
                                            <td class="num strong"><x-num :value="$field['accuracy']" :decimals="1" />٪</td>
                                            <td class="num"><x-num :value="$field['confidence']" :decimals="1" /></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if (! empty($run->previews))
        <div class="card">
            <div class="card__head">
                <h2>نمونهٔ تصویرهای ساخته‌شده</h2>
                <span class="spacer"></span>
                <span class="tiny faint">بقیهٔ تصویرها بعد از خوانده‌شدن پاک شدند</span>
            </div>
            <div class="card__body">
                <div class="grid grid--3">
                    @foreach ($run->previews as $preview)
                        <div class="stack stack--sm">
                            <figure class="thumb" style="margin:0">
                                <img src="{{ route('media', ['disk' => 'dataset', 'path' => $preview['path'], 'w' => 320]) }}"
                                     alt="نمونهٔ {{ $preview['document'] ?? 'مدرک' }}" loading="lazy">
                                <figcaption class="tiny faint" style="padding:6px 8px">
                                    {{ $preview['document'] ?? '—' }}
                                </figcaption>
                            </figure>

                            <table class="table">
                                <tbody>
                                    @foreach ($preview['fields'] ?? [] as $field)
                                        <tr>
                                            <td class="tiny">{{ $field['label'] }}</td>
                                            <td class="tiny num">{{ $field['expected'] }}</td>
                                            <td class="tiny num">
                                                @if ($field['ok'])
                                                    <span class="text-ok">✓</span>
                                                @else
                                                    <span class="text-bad">✗ {{ $field['got'] !== '' ? $field['got'] : '—' }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                </div>
                <span class="hint">
                    ستون وسط متنی است که روی تصویر چاپ شده (پاسخ درست) و ستون آخر نتیجهٔ خواندن سامانه.
                </span>
            </div>
        </div>
    @endif

    @if (! empty($run->misses))
        <div class="card">
            <div class="card__head">
                <h2>نمونهٔ خواندن‌های نادرست</h2>
                <span class="spacer"></span>
                <span class="tiny faint">حداکثر ۶۰ مورد اول</span>
            </div>
            <div class="card__body">
                <div class="scroll-x">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>مدرک</th>
                                <th>فیلد</th>
                                <th>چاپ شده بود</th>
                                <th>خوانده شد</th>
                                <th>اطمینان</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($run->misses as $miss)
                                <tr>
                                    <td class="small">{{ $miss['document'] ?? '—' }}</td>
                                    <td class="small">{{ $miss['field'] ?? '—' }}</td>
                                    <td class="num">{{ $miss['expected'] ?? '—' }}</td>
                                    <td class="num text-bad">{{ $miss['got'] ?? '—' }}</td>
                                    <td class="num"><x-num :value="$miss['confidence'] ?? 0" :decimals="1" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <span class="hint">
                    این جدول همان چیزی است که برای بهتر کردن موتور به کار می‌آید: هر ردیف می‌گوید کدام
                    فیلد روی کدام مدرک بد خوانده شده و به‌جایش چه آمده است.
                </span>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    var finished = @json($finished);
    var statusUrl = @json(route('evaluation.status', $run));

    if (finished) { return; }

    var faDigits = function (text) {
        var map = {'0':'۰','1':'۱','2':'۲','3':'۳','4':'۴','5':'۵','6':'۶','7':'۷','8':'۸','9':'۹','.':'٫'};
        return String(text).replace(/[0-9.]/g, function (ch) { return map[ch] || ch; });
    };

    var setStat = function (id, value) {
        var box = document.getElementById(id);
        if (!box) { return; }
        var slot = box.querySelector('.stat__v');
        if (slot) { slot.textContent = faDigits(value); }
    };

    var setText = function (id, value) {
        var box = document.getElementById(id);
        if (box) { box.textContent = faDigits(value); }
    };

    var alertBox = document.getElementById('runAlert');
    var badge = document.getElementById('statusBadge');
    var bar = document.getElementById('progressBar');
    var foot = document.getElementById('finishFoot');
    var timer = null;

    var apply = function (data) {
        setStat('statAccuracy', data.accuracy === null ? '—' : data.accuracy + '٪');
        setStat('statFields', data.fields_correct + ' از ' + data.fields_total);
        setStat('statConfidence', data.avg_confidence === null ? '—' : data.avg_confidence);
        setStat('statImages', data.count_done);
        setText('statFailed', data.count_failed);

        if (badge) {
            badge.textContent = data.status_label;
            badge.className = 'badge badge--' + data.status_tone + ' badge--dot';
        }

        if (bar) {
            var fill = bar.querySelector('.bar__fill');
            if (fill) {
                fill.style.width = data.percent + '%';
                fill.className = 'bar__fill' + (data.status_tone === 'info' ? '' : ' bar__fill--' + data.status_tone);
            }
            bar.setAttribute('aria-valuenow', data.percent);
        }

        if (alertBox && data.error) {
            alertBox.classList.remove('hidden');
            var slot = document.getElementById('runError');
            if (slot) { slot.textContent = data.error; }
        }

        if (data.finished) {
            if (timer) { clearInterval(timer); }
            if (foot) { foot.classList.remove('hidden'); }
            // یک بار بارگذاری دوباره تا جدول تفکیکی و نمونه‌ها رندر شوند.
            window.setTimeout(function () { window.location.reload(); }, 600);
        }
    };

    var poll = function () {
        fetch(statusUrl, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) { if (data) { apply(data); } })
            .catch(function () { /* شبکه قطع شد؛ تلاش بعدی سه ثانیه دیگر */ });
    };

    timer = window.setInterval(poll, 3000);
    poll();
})();
</script>
@endpush
