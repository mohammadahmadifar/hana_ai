@extends('layouts.panel')

@section('title', 'پیشرفت دستهٔ تولید')
@section('page_title', 'پیشرفت دستهٔ تولید')

@section('topbar_actions')
    <a class="btn btn--ghost" href="{{ route('dataset.generate.create') }}">دستهٔ تازه</a>
@endsection

@section('content')
    @php
        $tone = \App\Jobs\GenerateDatasetSamples::statusTone($batch->status);
        $label = \App\Jobs\GenerateDatasetSamples::statusLabel($batch->status);
        $finished = in_array($batch->status, ['done', 'failed'], true);
        $totalImages = (int) $batch->count_requested * max(1, $documentTypes->count());
    @endphp

    <div class="page-head">
        <div>
            <h1>دستهٔ تولید #<x-num :value="$batch->id" /></h1>
        </div>
        <div class="page-head__actions">
            <a class="btn" href="{{ route('dataset.generate.show', $batch) }}">بازخوانی</a>
        </div>
        <p class="page-head__sub">
            ثبت‌شده توسط {{ $batch->user?->name ?? '—' }} در
            <x-jdate :value="$batch->created_at" time />.
            تا وقتی کار تمام نشده، اعداد این صفحه هر ۳ ثانیه خودشان به‌روز می‌شوند.
        </p>
    </div>

    @php
        // یادداشت خطا فقط مال دستهٔ «ناموفق» نیست: دسته‌ای که تمام شده هم ممکن است
        // بخشی از تصویرهایش ساخته نشده باشد و کاربر باید همین‌جا ببیندش.
        $isStopped = $batch->status === 'failed';
        $errorTitle = $isStopped ? 'تولید این دسته متوقف شد' : 'بخشی از تصویرهای این دسته ساخته نشد';
    @endphp

    <div class="alert alert--{{ $isStopped ? 'bad' : 'warn' }} {{ filled($batch->error) ? '' : 'hidden' }}"
         role="alert" id="batchAlert">
        <span class="alert__icon" aria-hidden="true" id="batchErrorIcon">{{ $isStopped ? '⛔' : '⚠️' }}</span>
        <div class="alert__body">
            <strong id="batchErrorTitle">{{ $errorTitle }}</strong>
            <span id="batchError">{{ $batch->error }}</span>
        </div>
    </div>

    <div class="card">
        <div class="card__head">
            <h2>وضعیت</h2>
            <span class="spacer"></span>
            <x-badge id="statusBadge" :tone="$tone" dot :label="$label" />
        </div>

        <div class="card__body">
            <div class="grid grid--4">
                <x-stat id="statRequested" :value="$batch->count_requested" label="نمونهٔ درخواستی"
                        note="هر نمونه یک شخص مصنوعی" />
                <x-stat id="statDone" :value="$batch->count_done" label="نمونهٔ انجام‌شده" tone="ok" />
                <x-stat id="statFailed" :value="$batch->count_failed" label="نمونهٔ ناموفق"
                        note="نمونه‌ای که همهٔ مدرک‌هایش ساخته نشد"
                        :tone="$batch->count_failed > 0 ? 'bad' : null" />
                <x-stat id="statPercent" :value="$batch->progressPercent().'٪'" label="پیشرفت" tone="info" />
            </div>

            <x-bar id="progressBar" :percent="$batch->progressPercent()"
                   :tone="$tone === 'info' ? null : $tone" label="پیشرفت دسته" />

            <div class="row">
                <span class="small muted">
                    تصویر ثبت‌شده در دیتاست:
                    <span class="strong num" id="sampleCount"><x-num :value="$sampleCount" /></span>
                    از <x-num :value="$totalImages" />
                </span>
                <span class="spacer"></span>
                <span class="small muted">
                    برچسب (کادر فیلد) ثبت‌شده:
                    <span class="strong num" id="annotationCount"><x-num :value="$annotationCount" /></span>
                </span>
            </div>
        </div>

        {{-- class دوبار نوشته نشود: مرورگر فقط اولی را می‌بیند و «hidden» بی‌اثر می‌ماند. --}}
        <div id="finishFoot" @class(['card__foot', 'hidden' => ! $finished])>
            <span class="small muted">
                @if ($batch->finished_at)
                    پایان در <x-jdate :value="$batch->finished_at" time />.
                @else
                    کار تمام شد.
                @endif
            </span>
            <span class="spacer"></span>
            @if (\Illuminate\Support\Facades\Route::has('dataset.samples.index'))
                <a class="btn btn--primary"
                   href="{{ route('dataset.samples.index', ['batch' => $batch->id, 'source' => 'generated']) }}">فهرست نمونه‌های دیتاست</a>
            @endif
            <a class="btn" href="{{ route('dataset.generate.create') }}">دستهٔ تازه</a>
        </div>
    </div>

    <div class="grid grid--2">
        <div class="card">
            <div class="card__head">
                <h2>تنظیمات این دسته</h2>
            </div>
            <div class="card__body">
                <div class="field">
                    <span class="label">نوع مدرک</span>
                    <div class="row">
                        @forelse ($documentTypes as $type)
                            <x-badge tone="info" :label="$type->label_fa" />
                        @empty
                            <span class="faint small">—</span>
                        @endforelse
                    </div>
                </div>

                <div class="field">
                    <span class="label">اعوجاج</span>
                    @if (empty($appliedAugmentations))
                        <span class="small">بدون اعوجاج — نمونهٔ تمیز</span>
                    @else
                        <ul class="stack stack--sm" style="margin:0; padding-inline-start:18px">
                            @foreach ($appliedAugmentations as $line)
                                <li class="small">{{ $line }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="formgrid">
                    <div class="field">
                        <span class="label">split مقصد</span>
                        <span class="small">{{ $splitLabel }}</span>
                    </div>
                    <div class="field">
                        <span class="label">تگ اولیه</span>
                        <div class="row">
                            @forelse ($tags as $tag)
                                <x-badge :label="$tag->name" />
                            @empty
                                <span class="faint small">بدون تگ</span>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card__head">
                <h2>نمونه‌های ساخته‌شده</h2>
                <span class="spacer"></span>
                <span class="badge badge--ok"><x-num :value="$sampleCount" /> تصویر</span>
            </div>
            <div class="card__body">
                @if ($preview->isEmpty())
                    <x-empty-state icon="🖼" title="هنوز تصویری ثبت نشده"
                                   hint="به‌محض آماده‌شدن اولین تصویرها، همین‌جا دیده می‌شوند. صفحه را بازخوانی کنید." />
                @else
                    <div class="grid grid--4">
                        @foreach ($preview as $sample)
                            <figure class="thumb" style="margin:0">
                                <img src="{{ route('media', ['disk' => $sample->disk, 'path' => $sample->path, 'w' => 200]) }}"
                                     alt="نمونهٔ {{ $sample->documentType?->label_fa ?? 'مدرک' }} شمارهٔ {{ $sample->id }}"
                                     loading="lazy">
                                <figcaption class="tiny faint" style="padding:6px 8px">
                                    {{ $sample->documentType?->label_fa ?? '—' }}
                                    · {{ $sample->split }}
                                    · {{ $sample->augmentation }}
                                </figcaption>
                            </figure>
                        @endforeach
                    </div>
                    <span class="hint">تازه‌ترین ۸ تصویر این دسته نمایش داده می‌شود.</span>
                @endif
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    var finished = @json($finished);
    var statusUrl = @json(route('dataset.generate.status', $batch));

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

    // متن فارسیِ آماده (پیام خطا) نباید از فیلتر رقم رد شود؛ نقطهٔ جمله را خراب می‌کند.
    var setRaw = function (id, value) {
        var box = document.getElementById(id);
        if (box) { box.textContent = value; }
    };

    var alertBox = document.getElementById('batchAlert');
    var badge = document.getElementById('statusBadge');
    var bar = document.getElementById('progressBar');
    var foot = document.getElementById('finishFoot');
    var timer = null;

    var apply = function (data) {
        setStat('statDone', data.count_done);
        setStat('statFailed', data.count_failed);
        setStat('statPercent', data.percent + '٪');
        setText('sampleCount', data.sample_count);
        setText('annotationCount', data.annotation_count);

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

        // یادداشت خطا (مثلاً «۴ تصویر ساخته نشد») همان لحظه دیده شود، نه بعد از پایان کار.
        if (alertBox && data.error) {
            var stopped = data.status === 'failed';
            alertBox.className = 'alert alert--' + (stopped ? 'bad' : 'warn');
            setRaw('batchErrorIcon', stopped ? '⛔' : '⚠️');
            setRaw('batchErrorTitle', stopped ? 'تولید این دسته متوقف شد' : 'بخشی از تصویرهای این دسته ساخته نشد');
            setRaw('batchError', data.error);
        }

        if (data.finished) {
            if (timer) { clearInterval(timer); }
            if (foot) { foot.classList.remove('hidden'); }
            // یک بار بارگذاری دوباره تا گالری نمونه‌ها و پیام پایانی کامل رندر شود.
            window.setTimeout(function () { window.location.reload(); }, 600);
        }
    };

    var poll = function () {
        fetch(statusUrl, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) { if (data) { apply(data); } })
            .catch(function () { /* شبکه قطع شد؛ تلاش بعدی خودش انجام می‌شود */ });
    };

    timer = window.setInterval(poll, 3000);
    poll();
})();
</script>
@endpush
