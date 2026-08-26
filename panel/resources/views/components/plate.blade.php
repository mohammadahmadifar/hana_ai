{{--
    پلاک خودرو، به شکل خودِ پلاک — نه یک رشتهٔ متنی (تسک ۷۴۱).

    <x-plate :value="$field['display']" />
    <x-plate :value="$plate" size="sm" />

    چیدمان همان چیدمان واقعی پلاک ایرانی است و عمداً «راست‌چین» نمی‌شود:
    نوار آبی ایران در چپ، دو رقم، حرف، سه رقم، و کد استان در کادر جدا سمت راست
    — همان دو کادری که app/ocr/vehicle_card_ocr.py هم می‌شناسد. برای همین کل
    کادر dir="ltr" است، هرچند صفحه راست‌چین باشد.

    مقداری که با الگوی پلاک نمی‌خواند (OCR ناخوانا) به نمایش متنی برمی‌گردد؛
    کادر خالیِ پلاک بدتر از متن خام است، چون به کارشناس می‌گوید چیزی خوانده شده.
--}}
@props(['value' => null, 'size' => null])

@php
    $plateParts = \App\Support\PersianValue::plateParts($value);
    $plateText = trim((string) ($value ?? ''));
@endphp

@if ($plateParts === null)
    <span {{ $attributes->class(['plate-raw', 'num']) }}>{{ $plateText !== '' ? $plateText : 'خوانده نشد' }}</span>
@else
    <span {{ $attributes->class(['plate', 'plate--sm' => $size === 'sm']) }}
          dir="ltr"
          role="img"
          aria-label="شمارهٔ پلاک: {{ $plateParts['digits'] }} {{ $plateParts['letter'] }} {{ $plateParts['serial'] }} ایران {{ $plateParts['province'] }}">
        <span class="plate__iran" aria-hidden="true">
            <span class="plate__flag"></span>
            <span class="plate__country">I.R.<br>IRAN</span>
        </span>

        <span class="plate__main" aria-hidden="true">
            <span class="plate__digits">{{ $plateParts['digits'] }}</span>
            <span class="plate__letter">{{ $plateParts['letter'] }}</span>
            <span class="plate__serial">{{ $plateParts['serial'] }}</span>
        </span>

        <span class="plate__province" aria-hidden="true">
            <span class="plate__code">{{ $plateParts['province'] }}</span>
            <span class="plate__label">ایران</span>
        </span>
    </span>
@endif
