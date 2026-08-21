@extends('layouts.panel')

@section('title', 'مدارک پرونده '.$case->code)
@section('page_title', 'مدارک پروندهٔ '.\App\Support\PersianValue::toPersianDigits($case->code))

@section('topbar_actions')
    <a href="{{ route('cases.index') }}" class="btn btn--ghost btn--sm">📂 پرونده‌ها</a>
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

    $requiredRows = array_values(array_filter($checklist, fn (array $row): bool => $row['required']));
    $readyCount = count(array_filter($requiredRows, fn (array $row): bool => $row['state'] === 'ready'));
@endphp

@section('content')

    <div class="stack">

        @include('cases._steps', ['current' => $isComplete && $editable ? 3 : ($editable ? 2 : 4)])

        <div class="card">
            <div class="card__body">
                <div class="row">
                    <div class="stack stack--sm">
                        <div class="row">
                            <strong>{{ $case->serviceType?->label_fa ?? 'خدمت نامشخص' }}</strong>
                            <x-badge :tone="$statusTone"
                                     label="{{ $editable && ! $isComplete ? 'ناقص' : $case->statusLabel() }}" />
                        </div>
                        <div class="tiny faint">
                            <span class="num">{{ PersianValue::toPersianDigits($case->code) }}</span>
                            @if (filled($case->applicant_name))
                                <span>·</span>
                                <span>{{ $case->applicant_name }}</span>
                            @endif
                            @if (filled($case->applicant_national_id))
                                <span>·</span>
                                <span class="num">{{ $case->applicant_national_id }}</span>
                            @endif
                            <span>·</span>
                            <x-jdate :value="$case->created_at" time />
                        </div>
                    </div>

                    <div class="spacer"></div>

                    <div class="stack stack--sm" style="min-width: 180px;">
                        <span class="tiny faint">
                            <x-num :value="$readyCount" /> از <x-num :value="count($requiredRows)" /> مدرک لازم آماده است
                        </span>
                        <x-bar :percent="count($requiredRows) > 0 ? round($readyCount * 100 / count($requiredRows)) : 0" />
                    </div>
                </div>
            </div>
        </div>

        @if (! $editable)
            <div class="alert alert--info" role="status">
                <span class="alert__icon" aria-hidden="true">🔒</span>
                <div class="alert__body">
                    <strong>این پرونده ثبت شده است و مدارکش قفل است.</strong>
                    <span>وضعیت فعلی: {{ $case->statusLabel() }}. برای تغییر مدارک باید پروندهٔ تازه‌ای بسازید.</span>
                </div>
            </div>
        @elseif ($missing !== [])
            <div class="alert alert--warn" role="status">
                <span class="alert__icon" aria-hidden="true">⚠️</span>
                <div class="alert__body">
                    <strong>پرونده ناقص است.</strong>
                    <span>این مدرک‌ها هنوز آماده نیستند: {{ implode('، ', $missing) }}.</span>
                    <span>هر کدام را روی کادر خودش بارگذاری کنید؛ فایلی که رد شده باشد را باید با فایل بهتری جایگزین کنید.</span>
                </div>
            </div>
        @else
            <div class="alert alert--ok" role="status">
                <span class="alert__icon" aria-hidden="true">✅</span>
                <div class="alert__body">
                    <strong>همهٔ مدارک لازم بارگذاری شده‌اند.</strong>
                    <span>با دکمهٔ پایین صفحه پرونده را ثبت کنید تا برای بررسی در نوبت قرار بگیرد.</span>
                </div>
            </div>
        @endif

        <div class="grid grid--2">
            @foreach ($checklist as $row)
                @include('cases._document-card', ['row' => $row, 'case' => $case, 'editable' => $editable])
            @endforeach
        </div>

        @if ($editable)
            <div class="card">
                <div class="card__body">
                    <div class="row">
                        <div class="stack stack--sm">
                            <strong>ثبت نهایی پرونده</strong>
                            <span class="small muted">
                                @if ($isComplete)
                                    با ثبت پرونده، مدارک قفل می‌شوند و پرونده برای خواندن و بررسی خودکار به نوبت می‌رود.
                                @else
                                    تا وقتی مدارک ناقص‌اند دکمهٔ ثبت باز نمی‌شود.
                                @endif
                            </span>
                        </div>

                        <div class="spacer"></div>

                        <form method="POST" action="{{ route('cases.submit', $case) }}"
                              @if ($isComplete) data-confirm="پرونده ثبت شود؟ پس از ثبت، مدارک قابل تغییر نیستند." @endif>
                            @csrf
                            <button type="submit" class="btn btn--primary btn--lg" @disabled(! $isComplete)>
                                📨 ثبت پرونده و ارسال برای بررسی
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        @endif

    </div>

@endsection

@push('scripts')
<script>
    /*
        ناحیهٔ آپلود: کشیدن‌ورهاکردن + ارسال خودکار فرم بعد از انتخاب فایل.
        بدون کتابخانه و بدون build — همان کلاس‌های drop/is-over در app.css.
    */
    (function () {
        function submitWith(input) {
            var form = input.closest('form[data-upload-form]');
            var drop = form ? form.querySelector('[data-drop]') : null;

            if (!form || !input.files || input.files.length === 0) {
                return;
            }

            if (drop) {
                drop.classList.remove('is-over');
                var title = drop.querySelector('[data-drop-title]');
                if (title) {
                    title.textContent = 'در حال بارگذاری و بررسی فایل…';
                }
            }

            form.submit();
        }

        document.querySelectorAll('[data-upload-input]').forEach(function (input) {
            input.addEventListener('change', function () {
                submitWith(input);
            });
        });

        document.querySelectorAll('[data-drop]').forEach(function (drop) {
            var input = document.getElementById(drop.dataset.input);

            if (!input) {
                return;
            }

            ['dragenter', 'dragover'].forEach(function (name) {
                drop.addEventListener(name, function (event) {
                    event.preventDefault();
                    drop.classList.add('is-over');
                });
            });

            ['dragleave', 'dragend'].forEach(function (name) {
                drop.addEventListener(name, function () {
                    drop.classList.remove('is-over');
                });
            });

            drop.addEventListener('drop', function (event) {
                event.preventDefault();
                drop.classList.remove('is-over');

                var files = event.dataTransfer ? event.dataTransfer.files : null;

                if (!files || files.length === 0) {
                    return;
                }

                // انتساب مستقیم FileList به input فایل در مرورگرهای امروزی کار می‌کند.
                try {
                    input.files = files;
                } catch (error) {
                    var box = new DataTransfer();
                    box.items.add(files[0]);
                    input.files = box.files;
                }

                submitWith(input);
            });
        });
    })();
</script>
@endpush
