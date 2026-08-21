{{--
    نوار پیشرفت / سهم.
    <x-bar :percent="72" tone="ok" />
    percent بین ۰ تا ۱۰۰ کلمپ می‌شود. tone: ok | warn | bad (هر چیز دیگر = رنگ لهجه)
--}}
@props(['percent' => 0, 'tone' => null, 'label' => null])
@php
    $barPercent = round(max(0.0, min(100.0, (float) $percent)), 2);

    // app.css فقط این سه واریانت را دارد؛ هر tone دیگری به رنگ لهجه برمی‌گردد.
    $barTone = in_array($tone, ['ok', 'warn', 'bad'], true) ? $tone : null;
@endphp
<div {{ $attributes->class(['bar']) }}
     role="progressbar"
     aria-valuenow="{{ $barPercent }}"
     aria-valuemin="0"
     aria-valuemax="100"
     @if (filled($label)) aria-label="{{ $label }}" @endif>
    <span class="bar__fill{{ $barTone ? ' bar__fill--'.$barTone : '' }}" style="width: {{ $barPercent }}%"></span>
</div>
