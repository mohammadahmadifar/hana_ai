{{--
    نوار گام‌های ویزارد درخواست خدمت.
    استفاده:  @include('cases._steps', ['current' => 2])
    کلاس‌ها از app.css می‌آیند: steps / stepitem / stepitem__n / is-active / is-done
--}}
@php
    $wizardSteps = ['انتخاب نوع خدمت', 'بارگذاری مدارک', 'ثبت و ارسال برای بررسی'];
    $wizardCurrent = (int) ($current ?? 1);
@endphp

<div class="steps" role="list" aria-label="مراحل درخواست خدمت">
    @foreach ($wizardSteps as $stepIndex => $stepLabel)
        @php $stepNumber = $stepIndex + 1; @endphp
        <div role="listitem"
             class="stepitem {{ $stepNumber === $wizardCurrent ? 'is-active' : ($stepNumber < $wizardCurrent ? 'is-done' : '') }}"
             @if ($stepNumber === $wizardCurrent) aria-current="step" @endif>
            <span class="stepitem__n" aria-hidden="true">
                {{ $stepNumber < $wizardCurrent ? '✓' : \App\Support\PersianValue::toPersianDigits((string) $stepNumber) }}
            </span>
            <span>{{ $stepLabel }}</span>
        </div>
    @endforeach
</div>
