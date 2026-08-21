{{--
    تاریخ شمسی. چون کتابخانه تقویم جلالی در پروژه نصب نیست، تبدیل این‌جا
    انجام می‌شود (الگوریتم استاندارد jdf).
    <x-jdate :value="$case->created_at" />              → ۱۴۰۵/۰۵/۳۰
    <x-jdate :value="$case->created_at" time />         → ۱۴۰۵/۰۵/۳۰ ۱۴:۳۲
    <x-jdate :value="$case->created_at" format="long" /> → ۳۰ مرداد ۱۴۰۵
--}}
@props(['value' => null, 'time' => false, 'format' => 'short', 'fallback' => '—', 'tz' => null])
@php
    $jMonths = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

    $jMoment = null;
    if (filled($value)) {
        try {
            $jMoment = $value instanceof \DateTimeInterface
                ? \Illuminate\Support\Carbon::instance($value)
                : \Illuminate\Support\Carbon::parse($value);
            $jMoment = $jMoment->timezone($tz ?: config('panel_menu.timezone', config('app.timezone')));
        } catch (\Throwable) {
            $jMoment = null;
        }
    }

    $jText = $fallback;
    $jIso = null;

    if ($jMoment) {
        $jIso = $jMoment->toIso8601String();
        [$gy, $gm, $gd] = [(int) $jMoment->year, (int) $jMoment->month, (int) $jMoment->day];

        $gDayOfMonth = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400) + $gd + $gDayOfMonth[$gm - 1];

        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        $jText = $format === 'long'
            ? $jd.' '.$jMonths[$jm - 1].' '.$jy
            : sprintf('%04d/%02d/%02d', $jy, $jm, $jd);

        if ($time) {
            $jText .= ' '.$jMoment->format('H:i');
        }

        $jText = strtr($jText, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }
@endphp
@if ($jIso)<time {{ $attributes->class(['num', 'nowrap']) }} datetime="{{ $jIso }}">{{ $jText }}</time>@else<span {{ $attributes->class(['faint']) }}>{{ $jText }}</span>@endif
