{{--
    حالت خالی برای جدول‌ها و لیست‌ها.
    <x-empty-state icon="📂" title="هنوز پرونده‌ای ثبت نشده" hint="از «درخواست جدید» شروع کنید." />
    محتوای داخل تگ (مثلاً یک دکمه) زیر متن راهنما نمایش داده می‌شود.
--}}
@props(['icon' => '📭', 'title' => '', 'hint' => null])
<div {{ $attributes->class(['empty']) }}>
    <div class="empty__icon" aria-hidden="true">{{ $icon }}</div>
    @if (filled($title))
        <div class="strong">{{ $title }}</div>
    @endif
    @if (filled($hint))
        <div class="small">{{ $hint }}</div>
    @endif
    @if (trim($slot) !== '')
        <div class="row">{{ $slot }}</div>
    @endif
</div>
