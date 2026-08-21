{{--
    نشان وضعیت.
    <x-badge tone="ok" label="تایید" />
    <x-badge tone="warn" label="نیاز به بررسی" dot />
    اگر label ندهید، محتوای داخل تگ استفاده می‌شود.
--}}
@props(['tone' => null, 'label' => null, 'dot' => false])
<span {{ $attributes->class([
    'badge',
    'badge--'.$tone => filled($tone),
    'badge--dot' => (bool) $dot,
]) }}>{{ $label ?? $slot }}</span>
