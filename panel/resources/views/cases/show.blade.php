@extends('layouts.panel')

@section('title', 'نتیجهٔ پروندهٔ '.$case->code)
@section('page_title', 'نتیجهٔ پروندهٔ '.\App\Support\PersianValue::toPersianDigits($case->code))

@section('topbar_actions')
    {{-- «صف بررسی» فقط برای کسی که روتش برایش باز است؛ متقاضی با کلیک روی آن
         ۴۰۳ می‌گرفت. --}}
    @if ($canReview)
        <a href="{{ route('cases.review') }}" class="btn btn--ghost btn--sm">🔍 صف بررسی</a>
    @endif
    <a href="{{ route('cases.documents.edit', $case) }}" class="btn btn--ghost btn--sm">📎 مدارک</a>
    <a href="#decision" class="btn btn--primary btn--sm">
        {{ $canReview ? '⬇ رفتن به تصمیم' : '⬇ رفتن به نتیجه' }}
    </a>
@endsection

@php
    use App\Support\PersianValue;

    $statusTone = [
        'draft' => 'warn',
        'submitted' => 'info',
        'processing' => 'info',
        'needs_review' => 'warn',
        'approved' => 'ok',
        'rejected' => 'bad',
    ][$case->status] ?? 'info';
@endphp

@section('content')

    <div class="stack">

        {{-- شناسنامهٔ پرونده --}}
        <div class="card">
            <div class="card__body">
                <div class="row">
                    <div class="stack stack--sm">
                        <div class="row">
                            <strong>{{ $case->serviceType?->label_fa ?? 'خدمت نامشخص' }}</strong>
                            <x-badge :tone="$statusTone" :label="$case->statusLabel()" />
                            @if ($case->decision_is_manual)
                                <x-badge tone="info" label="✎ تصمیم انسانی" />
                            @endif
                        </div>
                        <div class="row tiny faint">
                            <span class="num nowrap">{{ PersianValue::toPersianDigits($case->code) }}</span>
                            @if (filled($case->applicant_name))
                                <span>·</span><span>{{ $case->applicant_name }}</span>
                            @endif
                            @if (filled($case->applicant_national_id))
                                <span>·</span><span class="num">{{ PersianValue::toPersianDigits($case->applicant_national_id) }}</span>
                            @endif
                            <span>·</span>
                            <span>ثبت‌کننده: {{ $case->user?->name ?? '—' }}</span>
                            <span>·</span>
                            <span>ارسال: <x-jdate :value="$case->submitted_at ?? $case->created_at" time /></span>
                            @if ($case->processed_at)
                                <span>·</span>
                                <span>آخرین پردازش: <x-jdate :value="$case->processed_at" time /></span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if ($pending)
            {{-- سیم‌کشی تسک ۶۳۱ به تسک ۶۳۵: اندپوینت cases.processing.status وضعیت
                 زنده می‌دهد، پس کاربر لازم نیست خودش صفحه را تازه کند. اگر JS خاموش
                 باشد یا اندپوینت جواب ندهد، همین متن ثابت می‌ماند و راهنمای دستی
                 سر جایش است. --}}
            <div class="alert alert--info" role="status"
                 id="case-processing"
                 data-status-url="{{ route('cases.processing.status', $case) }}">
                <span class="alert__icon" aria-hidden="true">⏳</span>
                <div class="alert__body">
                    <strong>پردازش خودکار این پرونده هنوز تمام نشده است.</strong>
                    <span data-processing-message>
                        اعداد و بررسی‌های زیر ممکن است ناقص باشند و پس از پایان OCR عوض شوند.
                        این صفحه خودش وضعیت را دنبال می‌کند؛ اگر تازه نشد، چند لحظه بعد
                        دوباره بازش کنید.
                    </span>
                    <x-bar :percent="0" tone="ok" label="پیشرفت پردازش" data-processing-bar />
                </div>
            </div>
        @endif

        @if ($case->status === 'needs_review')
            <div class="alert alert--warn" role="status">
                <span class="alert__icon" aria-hidden="true">👤</span>
                <div class="alert__body">
                    @if ($canReview)
                        <strong>این پرونده منتظر تصمیم شماست.</strong>
                        <span>
                            امتیاز اطمینانش بین آستانهٔ رد و آستانهٔ تایید افتاده، پس سامانه عمداً تصمیم نگرفته است.
                            مقدار هر فیلد را با تصویر همان مدرک بسنجید، اشتباه‌ها را اصلاح کنید و بعد تصمیم بگیرید.
                        </span>
                    @else
                        <strong>این پرونده در نوبت بررسی کارشناس است.</strong>
                        <span>
                            امتیاز اطمینانش بین آستانهٔ رد و آستانهٔ تایید افتاده، پس سامانه عمداً خودش تصمیم نگرفته
                            و پرونده را به کارشناس سپرده است. تا اعلام نتیجه کاری لازم نیست انجام دهید.
                        </span>
                    @endif
                </div>
            </div>
        @endif

        {{-- «حالا چه کار کنم؟» — پیش از امتیاز، چون کاربر اول دنبال کارِ بعدی است --}}
        @include('cases._review-actions', ['actions' => $actions, 'canReview' => $canReview])

        {{-- امتیاز اطمینان و مؤلفه‌ها --}}
        @include('cases._review-score', ['case' => $case, 'components' => $components])

        {{-- مدارک: تصویر کنار فیلدهای خوانده‌شده --}}
        <div class="stack">
            <div class="row">
                <h2>مدارک و فیلدهای خوانده‌شده</h2>
                <div class="spacer"></div>
                @if ($reviewable && $canReview)
                    <span class="tiny faint">
                        مقدارها قابل ویرایش‌اند.
                        @if ($datasetOn)
                            هر اصلاح شما به دیتاست تگ‌گذاری هم برمی‌گردد و دادهٔ آموزشی می‌شود.
                        @else
                            (بازگرداندن اصلاح‌ها به دیتاست تگ‌گذاری در تنظیمات خاموش است.)
                        @endif
                    </span>
                @endif
            </div>

            @forelse ($panels as $panel)
                @include('cases._review-document', [
                    'panel' => $panel,
                    'case' => $case,
                    // برای متقاضی فقط‌خواندنی: پنل مدرک بدون فرم اصلاح فیلد
                    'reviewable' => $reviewable && $canReview,
                    'correctors' => $correctors,
                    'editingDocument' => $editingDocument,
                ])
            @empty
                <div class="card">
                    <div class="card__body">
                        <x-empty-state
                            icon="📂"
                            title="برای این پرونده هیچ مدرکی تعریف نشده است"
                            hint="فهرست مدارک لازم از نوع خدمت می‌آید؛ اگر خالی است، دادهٔ مرجع سامانه کامل نیست." />
                    </div>
                </div>
            @endforelse
        </div>

        {{-- فهرست دلایل --}}
        @include('cases._review-checks', [
            'checksByScope' => $checksByScope,
            'checkTotals' => $checkTotals,
        ])

        {{-- تصمیم نهایی --}}
        @include('cases._review-decision', [
            'case' => $case,
            'reviewable' => $reviewable,
            'canReview' => $canReview,
        ])

    </div>

@endsection

@push('scripts')
<script>
    /*
        بزرگ‌نمایی تصویر مدرک — بدون کتابخانه و بدون CSS تازه.
        تصویر داخل یک ظرف scroll-x است، پس وقتی از عرض ستون بزرگ‌تر شود
        همان ظرف افقی اسکرول می‌شود و چیزی از صفحه بیرون نمی‌زند.
    */
    (function () {
        document.querySelectorAll('[data-zoom]').forEach(function (button) {
            var image = document.getElementById(button.dataset.zoom);

            if (!image) {
                return;
            }

            button.addEventListener('click', function () {
                var zoomed = image.dataset.zoomed === '1';

                if (zoomed) {
                    image.style.width = '';
                    image.style.maxWidth = '';
                    image.dataset.zoomed = '0';
                    button.textContent = button.dataset.zoomIn;
                } else {
                    image.style.width = '1600px';
                    image.style.maxWidth = 'none';
                    image.dataset.zoomed = '1';
                    button.textContent = button.dataset.zoomOut;
                }
            });
        });
    })();
</script>
@endpush

@push('scripts')
    @if ($pending)
        {{-- پایش وضعیت پردازش (تسک ۶۳۱ + ۶۳۵).
             عمداً ساده: بدون کتابخانه، بدون build. هر ۳ ثانیه یک بار، حداکثر ۴۰ بار
             (۲ دقیقه) — بعد از آن دست می‌کشد تا تبِ رهاشده تا ابد به سرور نزند. --}}
        <script>
            (function () {
                var box = document.getElementById('case-processing');

                if (!box || !box.dataset.statusUrl) {
                    return;
                }

                var message = box.querySelector('[data-processing-message]');
                var bar = box.querySelector('[data-processing-bar] .bar__fill');
                var left = 40;

                function stop(text) {
                    if (text && message) {
                        message.textContent = text;
                    }
                }

                function tick() {
                    if (left-- <= 0) {
                        stop('پایش خودکار متوقف شد. برای دیدن نتیجهٔ نهایی صفحه را تازه کنید.');

                        return;
                    }

                    fetch(box.dataset.statusUrl, {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    })
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error('bad status');
                            }

                            return response.json();
                        })
                        .then(function (data) {
                            if (bar && typeof data.percent === 'number') {
                                bar.style.width = Math.max(0, Math.min(100, data.percent)) + '%';
                            }

                            if (data.message_fa) {
                                stop(data.message_fa);
                            }

                            if (data.finished) {
                                window.location.reload();

                                return;
                            }

                            window.setTimeout(tick, 3000);
                        })
                        .catch(function () {
                            // خطای شبکه پایش را نمی‌کشد؛ فقط این دور را رد می‌کند.
                            window.setTimeout(tick, 5000);
                        });
                }

                window.setTimeout(tick, 2000);
            })();
        </script>
    @endif
@endpush
