@extends('layouts.panel')

@section('title', 'ارزیابی دقت')
@section('page_title', 'ارزیابی دقت سامانه')

@section('content')
    @php
        $oldTypes = array_map('intval', (array) old('document_type_ids', $documentTypes->pluck('id')->all()));
        $oldCount = (int) old('count', 200);
        $oldMode = (string) old('augment_mode', 'clean');
    @endphp

    <div class="page-head">
        <div>
            <h1>ارزیابی دقت سامانه</h1>
        </div>
        <p class="page-head__sub">
            سامانه به تعدادی که می‌گویید مدرک مصنوعی می‌سازد، هرکدام را از همان مسیری می‌خواند که یک
            پروندهٔ واقعی می‌رود (پیش‌پردازش ← OCR چندمقیاسی ← استخراج فیلد) و مقدار خوانده‌شده را با
            متنی که خودش روی همان تصویر چاپ کرده مقایسه می‌کند. نتیجه یک عدد است: «چند درصد فیلدها
            درست خوانده شدند». چون تصویر را خودمان می‌سازیم، پاسخ درست از قبل معلوم است و هیچ
            تگ‌گذاری دستی لازم نیست.
        </p>
    </div>

    <form method="POST" action="{{ route('evaluation.store') }}" id="evalForm">
        @csrf

        <div class="card">
            <div class="card__head">
                <h2>۱. چند تصویر و از چه مدرکی؟</h2>
            </div>

            <div class="card__body">
                <div class="formgrid">
                    <div class="field">
                        <label class="label" for="count">
                            تعداد تصویر <span class="label__req">*</span>
                        </label>
                        <input class="input input--ltr @error('count') is-invalid @enderror"
                               id="count" name="count" type="number" dir="ltr"
                               min="{{ $min }}" max="{{ $max }}" step="1" value="{{ $oldCount }}" required>
                        <span class="hint">
                            بین <x-num :value="$min" /> تا <x-num :value="$max" />. هر تصویر یک مدرک کامل است و
                            نوع مدرک‌ها به‌ترتیب بین تصویرها می‌چرخند تا سهم همه برابر بماند.
                        </span>
                        @error('count')
                            <span class="error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="field">
                        <span class="label">زمان تقریبی</span>
                        <div class="readout" id="estimate" aria-live="polite">—</div>
                        <span class="hint">
                            ساخت و خواندن هر تصویر حدود یک ثانیه طول می‌کشد و کار روی چند هسته پخش می‌شود.
                            همه‌اش در صف اجرا می‌شود، پس می‌توانید صفحه را ببندید و بعداً برگردید.
                        </span>
                    </div>
                </div>

                <div class="field">
                    <span class="label">نوع مدرک <span class="label__req">*</span></span>

                    @if ($documentTypes->isEmpty())
                        <x-empty-state icon="📄" title="هیچ نوع مدرک قابل تولیدی تعریف نشده"
                                       hint="برای ارزیابی، نوع مدرک باید «قابل تولید» و «فعال» باشد." />
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
                            «مجوز قبلی» در این فهرست نیست؛ قالب تولیدی ندارد، پس نمی‌شود برایش تصویر ساخت.
                        </span>
                    @endif

                    @error('document_type_ids')
                        <span class="error">{{ $message }}</span>
                    @enderror
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card__head">
                <h2>۲. تصویرها چه شکلی باشند؟</h2>
            </div>

            <div class="card__body">
                <div class="stack stack--sm">
                    @foreach ($augmentModes as $key => $label)
                        <label class="check">
                            <input type="radio" name="augment_mode" value="{{ $key }}"
                                   @checked($oldMode === $key)>
                            <span>
                                <span class="strong">{{ $label }}</span>
                                <span class="tiny faint">
                                    {{ $key === 'clean'
                                        ? 'سقف دقت موتور را نشان می‌دهد: هیچ چیزی جز خودِ خواندن دخیل نیست.'
                                        : 'چرخش، روشنایی، تاری، نویز و سایه — نزدیک‌تر به عکسی که کاربر واقعی می‌فرستد. عدد پایین‌تری می‌دهد و باید هم بدهد.' }}
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>

                @error('augment_mode')
                    <span class="error">{{ $message }}</span>
                @enderror

                <span class="hint">
                    تصویرها بعد از خوانده‌شدن پاک می‌شوند (هزار تصویر حدود دو گیگابایت است)؛ فقط
                    چند نمونه برای دیدن می‌ماند. هیچ مدرک هویتی واقعی در این مسیر نیست.
                </span>
            </div>

            <div class="card__foot">
                <span class="spacer"></span>
                <button type="submit" class="btn btn--primary">🎯 شروع ارزیابی</button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card__head">
            <h2>ارزیابی‌های اخیر</h2>
        </div>
        <div class="card__body">
            @if ($recentRuns->isEmpty())
                <x-empty-state icon="🎯" title="هنوز ارزیابی‌ای انجام نشده"
                               hint="اولین ارزیابی را با فرم بالا شروع کنید." />
            @else
                <div class="scroll-x">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>شناسه</th>
                                <th>تعداد تصویر</th>
                                <th>حالت</th>
                                <th>دقت کل</th>
                                <th>وضعیت</th>
                                <th>زمان</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentRuns as $row)
                                <tr>
                                    <td class="num">#<x-num :value="$row->id" /></td>
                                    <td class="num"><x-num :value="$row->count_requested" /></td>
                                    <td class="small">{{ $augmentModes[$row->augment_mode] ?? $row->augment_mode }}</td>
                                    <td class="num strong">
                                        @if ($row->accuracy() === null)
                                            <span class="faint">—</span>
                                        @else
                                            <x-num :value="$row->accuracy()" :decimals="1" />٪
                                        @endif
                                    </td>
                                    <td>
                                        <x-badge :tone="\App\Jobs\RunAccuracyEvaluation::statusTone($row->status)" dot
                                                 :label="\App\Jobs\RunAccuracyEvaluation::statusLabel($row->status)" />
                                    </td>
                                    <td class="small"><x-jdate :value="$row->created_at" time /></td>
                                    <td><a class="btn btn--sm" href="{{ route('evaluation.show', $row) }}">دیدن</a></td>
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
        var map = {'0':'۰','1':'۱','2':'۲','3':'۳','4':'۴','5':'۵','6':'۶','7':'۷','8':'۸','9':'۹','.':'٫'};
        return String(text).replace(/[0-9.]/g, function (ch) { return map[ch] || ch; });
    };

    var count = document.getElementById('count');
    var estimate = document.getElementById('estimate');

    var refresh = function () {
        if (!count || !estimate) { return; }

        var images = parseInt(count.value, 10);

        if (!images || images < 1) {
            estimate.textContent = '—';
            return;
        }

        // ۰٫۹ ثانیه برای هر تصویر، اندازه‌گیری‌شده روی همین سرور.
        var minutes = Math.max(1, Math.round(images * 0.9 / 60));

        estimate.textContent = faDigits(images) + ' تصویر — حدود ' + faDigits(minutes) + ' دقیقه';
    };

    if (count) {
        count.addEventListener('input', refresh);
        refresh();
    }
})();
</script>
@endpush
