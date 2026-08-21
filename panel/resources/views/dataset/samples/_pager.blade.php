{{--
    صفحه‌بندی ساده و راست‌چین با کلاس‌های خود سیستم طراحی.
    استفاده: @include('dataset.samples._pager', ['paginator' => $samples])
--}}
@php
    $lastPage = $paginator->lastPage();
    $current = $paginator->currentPage();
    $fa = fn ($n) => \App\Support\Jalali::digits($n);

    // پنجرهٔ شماره‌ها: دو صفحه این‌طرف و آن‌طرف صفحهٔ جاری، به‌علاوهٔ اول و آخر.
    $window = collect(range(max(1, $current - 2), min($lastPage, $current + 2)))
        ->merge([1, $lastPage])
        ->unique()
        ->sort()
        ->values();
@endphp

@if ($lastPage > 1)
    <nav class="row" aria-label="صفحه‌بندی">
        @if ($paginator->onFirstPage())
            <span class="btn btn--sm is-disabled" aria-disabled="true">قبلی</span>
        @else
            <a class="btn btn--sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">قبلی</a>
        @endif

        @php $previousPage = 0; @endphp
        @foreach ($window as $page)
            @if ($previousPage > 0 && $page > $previousPage + 1)
                <span class="faint small">…</span>
            @endif

            @if ($page === $current)
                <span class="btn btn--sm btn--primary" aria-current="page">{{ $fa($page) }}</span>
            @else
                <a class="btn btn--sm" href="{{ $paginator->url($page) }}">{{ $fa($page) }}</a>
            @endif

            @php $previousPage = $page; @endphp
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="btn btn--sm" href="{{ $paginator->nextPageUrl() }}" rel="next">بعدی</a>
        @else
            <span class="btn btn--sm is-disabled" aria-disabled="true">بعدی</span>
        @endif

        <span class="spacer"></span>
        <span class="small muted">
            نمایش {{ $fa($paginator->firstItem()) }} تا {{ $fa($paginator->lastItem()) }}
            از {{ $fa($paginator->total()) }} نمونه
        </span>
    </nav>
@else
    <div class="small muted">
        {{ $fa($paginator->total()) }} نمونه در این نما
    </div>
@endif
