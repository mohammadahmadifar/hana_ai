@extends('layouts.panel')

@section('title', 'تصاویر تستی')
@section('page_title', $mine ? 'تصاویر تستی من' : 'تصاویر تستی همهٔ کاربران')

@section('topbar_actions')
    @if ($canSeeAll)
        <a href="{{ route('testimage.index', $mine ? ['all' => 1] : []) }}" class="btn btn--ghost btn--sm">
            {{ $mine ? '👥 همهٔ کاربران' : '👤 فقط خودم' }}
        </a>
    @endif
    <a href="{{ route('testimage.create') }}" class="btn btn--primary btn--sm">🖼 ساخت تصویر تستی</a>
@endsection

@section('content')

    @if ($images->total() === 0)
        <div class="card">
            <div class="card__body">
                <x-empty-state
                    icon="🗃"
                    title="هنوز تصویر تستی نساخته‌اید"
                    hint="یک مدرک با دادهٔ دلخواه خودتان بسازید و ببینید OCR چقدر از آن را درست می‌خواند.">
                    <a href="{{ route('testimage.create') }}" class="btn btn--primary btn--sm">🖼 اولین تصویر را بساز</a>
                </x-empty-state>
            </div>
        </div>
    @else

        <div class="stack">

            <div class="row tiny faint">
                <span>در مجموع <x-num :value="$images->total()" /> تصویر تستی</span>
                <div class="spacer"></div>
                <span>صفحهٔ <x-num :value="$images->currentPage() " /> از <x-num :value="$images->lastPage()" /></span>
            </div>

            <div class="grid grid--3">
                @foreach ($images as $item)
                    @php $items = $applied[$item->id] ?? []; @endphp

                    <div class="card">
                        <a href="{{ route('testimage.show', $item) }}" class="thumb" style="display:block;">
                            <img src="{{ route('media', ['disk' => $item->disk, 'path' => $item->path]) }}"
                                 alt="تصویر تستی {{ $item->documentType?->label_fa }}"
                                 loading="lazy">
                        </a>

                        <div class="card__body">
                            <div class="row">
                                <strong class="small">{{ $item->documentType?->label_fa ?? 'نوع نامشخص' }}</strong>
                                <div class="spacer"></div>
                                <span class="tiny faint num">#{{ \App\Support\PersianValue::toPersianDigits((string) $item->id) }}</span>
                            </div>

                            <div class="row">
                                @if ($items === [])
                                    <x-badge tone="ok" label="✨ بدون اعوجاج" />
                                @else
                                    @foreach ($items as $one)
                                        <x-badge tone="warn"
                                                 label="{{ $one['icon'] }} {{ $one['label'] }} {{ $one['value'] }}" />
                                    @endforeach
                                @endif
                            </div>

                            <div class="row tiny faint">
                                <x-jdate :value="$item->created_at" time />
                                @if (! $mine && $item->user)
                                    <span>·</span>
                                    <span>{{ $item->user->name }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="card__foot">
                            <a href="{{ route('testimage.show', $item) }}" class="btn btn--ghost btn--sm">👁 نمایش</a>
                            <a href="{{ route('testimage.download', $item) }}" class="btn btn--ghost btn--sm">⬇ دانلود</a>
                            <div class="spacer"></div>
                            <form method="POST" action="{{ route('testimage.destroy', $item) }}"
                                  data-confirm="این تصویر تستی و فایل‌هایش حذف شوند؟" >
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn--danger btn--sm">🗑</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($images->hasPages())
                <div class="row">
                    @if ($images->onFirstPage())
                        <button type="button" class="btn btn--ghost btn--sm" disabled>→ صفحهٔ قبل</button>
                    @else
                        <a href="{{ $images->previousPageUrl() }}" class="btn btn--ghost btn--sm">→ صفحهٔ قبل</a>
                    @endif

                    <div class="spacer"></div>

                    @if ($images->hasMorePages())
                        <a href="{{ $images->nextPageUrl() }}" class="btn btn--ghost btn--sm">صفحهٔ بعد ←</a>
                    @else
                        <button type="button" class="btn btn--ghost btn--sm" disabled>صفحهٔ بعد ←</button>
                    @endif
                </div>
            @endif

        </div>
    @endif

@endsection
