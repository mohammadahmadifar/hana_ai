{{--
    کارت آمار.
    <x-stat :value="12" label="پرونده‌های امروز" note="نسبت به دیروز" tone="ok" />
    tone: ok | warn | bad | info (اختیاری)
    اگر value عددی باشد خودکار با ارقام فارسی و جداکننده هزارگان چاپ می‌شود.
--}}
@props(['value' => null, 'label' => '', 'note' => null, 'tone' => null])
<div {{ $attributes->class(['stat', 'stat--'.$tone => filled($tone)]) }}>
    <span class="stat__v">
        @if (is_numeric($value))
            <x-num :value="$value" />
        @else
            {{ $value }}
        @endif
    </span>
    <span class="stat__k">{{ $label }}</span>
    @if (filled($note))
        <span class="stat__note">{{ $note }}</span>
    @endif
</div>
