{{--
    پیام‌های موقت جلسه + خطاهای اعتبارسنجی.
    در layouts/panel بالای محتوا رندر می‌شود؛ صفحه‌ها لازم نیست خودشان صدایش بزنند.
    استفاده: session()->flash('success', 'ذخیره شد.')
--}}
@php
    $flashMap = [
        'success' => ['tone' => 'ok', 'icon' => '✅'],
        'error' => ['tone' => 'bad', 'icon' => '⛔'],
        'warning' => ['tone' => 'warn', 'icon' => '⚠️'],
        'info' => ['tone' => 'info', 'icon' => 'ℹ️'],
    ];
@endphp

@foreach ($flashMap as $flashKey => $flashMeta)
    @if (session()->has($flashKey))
        <div class="alert alert--{{ $flashMeta['tone'] }}" role="alert">
            <span class="alert__icon" aria-hidden="true">{{ $flashMeta['icon'] }}</span>
            <div class="alert__body">
                @foreach (\Illuminate\Support\Arr::wrap(session($flashKey)) as $flashLine)
                    <span>{{ $flashLine }}</span>
                @endforeach
            </div>
        </div>
    @endif
@endforeach

@if (isset($errors) && $errors->any())
    <div class="alert alert--bad" role="alert">
        <span class="alert__icon" aria-hidden="true">⛔</span>
        <div class="alert__body">
            <strong>ورودی‌های فرم را بررسی کنید</strong>
            @foreach ($errors->all() as $errorMessage)
                <span>{{ $errorMessage }}</span>
            @endforeach
        </div>
    </div>
@endif
