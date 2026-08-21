{{--
    عدد با ارقام فارسی و جداکننده هزارگان.
    <x-num :value="1250" />            → ۱٬۲۵۰
    <x-num :value="87.5" :decimals="1" /> → ۸۷٫۵
    مقدار غیرعددی همان‌طور که هست (فقط با ارقام فارسی) چاپ می‌شود.
--}}
@props(['value' => null, 'decimals' => 0])
@php
    $numText = is_numeric($value)
        ? number_format((float) $value, (int) $decimals, '.', ',')
        : (string) ($value ?? '');

    $numText = strtr($numText, [
        '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ',' => '٬', '.' => '٫',
    ]);
@endphp
<span {{ $attributes->class(['num']) }}>{{ $numText }}</span>