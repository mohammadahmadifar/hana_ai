@extends('layouts.panel')

@section('title', 'تگ‌های دیتاست')
@section('page_title', 'تگ‌های دیتاست')

@php
    use App\Support\Jalali;

    $fa = fn ($n) => Jalali::digits($n);
    $safeColor = fn (?string $c) => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c) ? $c : '#64748b';

    // کدام فرم آخرین‌بار خطا داده؟ همان فرم مقدارهای قبلی را نگه می‌دارد و باز می‌ماند.
    $lastForm = old('form', '');
@endphp

@push('head')
    <style>
        .swatch { display: inline-block; width: 14px; height: 14px; border-radius: 4px; border: 1px solid var(--rule); vertical-align: middle; margin-inline-end: 7px; }
        .colorpick { width: 54px; padding: 3px; min-height: 38px; }
        .tagform { display: grid; grid-template-columns: 54px minmax(140px, 1fr) minmax(180px, 2fr) auto; gap: 10px; align-items: end; }
        @media (max-width: 720px) { .tagform { grid-template-columns: 1fr; } }
    </style>
@endpush

@section('content')

    <div class="page-head">
        <div>
            <h1>تگ‌های دیتاست</h1>
        </div>
        <div class="page-head__actions">
            <a class="btn btn--ghost" href="{{ route('dataset.samples.index') }}">نمونه‌های دیتاست</a>
        </div>
        <p class="page-head__sub">
            تگ برچسبِ سازمان‌دهی نمونه‌هاست (مثل «کیفیت پایین» یا «فونت نازک»).
            حذف یک تگ هیچ نمونه‌ای را پاک نمی‌کند و فقط پیوندش را برمی‌دارد.
        </p>
    </div>

    <div class="grid grid--3">
        <x-stat :value="$tags->count()" label="کل تگ‌ها" note="تعریف‌شده در سامانه" />
        <x-stat :value="$usedCount" label="تگ‌های به‌کاررفته" note="دست‌کم روی یک نمونه" tone="info" />
        <x-stat :value="$linkCount" label="مجموع پیوندها" note="نمونه‌های تگ‌خورده" />
    </div>

    <div class="card">
        <div class="card__head">
            <h2>افزودن تگ تازه</h2>
        </div>
        <div class="card__body">
            <form method="POST" action="{{ route('dataset.tags.store') }}">
                @csrf
                <input type="hidden" name="form" value="create">

                <div class="tagform">
                    <div class="field">
                        <label class="label" for="new-color">رنگ</label>
                        <input class="input colorpick" type="color" id="new-color" name="color"
                               value="{{ $lastForm === 'create' ? old('color', $defaultColor) : $defaultColor }}">
                    </div>

                    <div class="field">
                        <label class="label" for="new-name">نام تگ <span class="label__req">*</span></label>
                        <input class="input @error('name') is-invalid @enderror" type="text" id="new-name" name="name"
                               maxlength="60" required
                               placeholder="مثلاً کیفیت پایین"
                               value="{{ $lastForm === 'create' ? old('name') : '' }}">
                    </div>

                    <div class="field">
                        <label class="label" for="new-desc">توضیح</label>
                        <input class="input" type="text" id="new-desc" name="description_fa" maxlength="500"
                               placeholder="این تگ کِی زده می‌شود؟"
                               value="{{ $lastForm === 'create' ? old('description_fa') : '' }}">
                    </div>

                    <div class="field">
                        <label class="label">&nbsp;</label>
                        <button class="btn btn--primary" type="submit">ثبت تگ</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card__head">
            <h2>فهرست تگ‌ها</h2>
            <span class="spacer"></span>
            <span class="badge badge--info">{{ $fa($tags->count()) }} تگ</span>
        </div>

        @if ($tags->isEmpty())
            <div class="card__body">
                <x-empty-state
                    icon="🔖"
                    title="هنوز تگی تعریف نشده"
                    hint="از فرم بالا اولین تگ را بسازید تا بتوانید نمونه‌ها را دسته‌بندی کنید." />
            </div>
        @else
            <div class="scroll-x">
                <table class="table">
                    <thead>
                        <tr>
                            <th>تگ</th>
                            <th>توضیح</th>
                            <th>شمار نمونه</th>
                            <th class="actions">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tags as $tag)
                            @php $editing = $lastForm === 'edit:'.$tag->id; @endphp
                            <tr>
                                <td>
                                    <span class="swatch" style="background: {{ $safeColor($tag->color) }}"></span>
                                    <span class="strong">{{ $tag->name }}</span>
                                    <div class="tiny faint mono ltr">{{ $safeColor($tag->color) }}</div>
                                </td>
                                <td class="small muted">{{ $tag->description_fa ?: '—' }}</td>
                                <td>
                                    @if ($tag->samples_count > 0)
                                        <a class="badge badge--info" href="{{ route('dataset.samples.index', ['tag' => $tag->id]) }}">
                                            {{ $fa($tag->samples_count) }} نمونه
                                        </a>
                                    @else
                                        <span class="faint small">بدون استفاده</span>
                                    @endif
                                </td>
                                <td class="actions">
                                    <button class="btn btn--sm js-edit" type="button" data-target="edit-{{ $tag->id }}">
                                        ویرایش
                                    </button>
                                    <form method="POST" action="{{ route('dataset.tags.destroy', $tag) }}" style="display:inline"
                                          data-confirm="تگ «{{ $tag->name }}» حذف شود؟ از {{ $fa($tag->samples_count) }} نمونه برداشته می‌شود ولی خود نمونه‌ها می‌مانند." >
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn--sm btn--danger" type="submit">حذف</button>
                                    </form>
                                </td>
                            </tr>
                            <tr id="edit-{{ $tag->id }}" class="{{ $editing ? '' : 'hidden' }}">
                                <td colspan="4">
                                    <form method="POST" action="{{ route('dataset.tags.update', $tag) }}">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="form" value="edit:{{ $tag->id }}">

                                        <div class="tagform">
                                            <div class="field">
                                                <label class="label" for="color-{{ $tag->id }}">رنگ</label>
                                                <input class="input colorpick" type="color" id="color-{{ $tag->id }}" name="color"
                                                       value="{{ $editing ? old('color', $safeColor($tag->color)) : $safeColor($tag->color) }}">
                                            </div>

                                            <div class="field">
                                                <label class="label" for="name-{{ $tag->id }}">نام تگ <span class="label__req">*</span></label>
                                                <input class="input" type="text" id="name-{{ $tag->id }}" name="name" maxlength="60" required
                                                       value="{{ $editing ? old('name', $tag->name) : $tag->name }}">
                                            </div>

                                            <div class="field">
                                                <label class="label" for="desc-{{ $tag->id }}">توضیح</label>
                                                <input class="input" type="text" id="desc-{{ $tag->id }}" name="description_fa" maxlength="500"
                                                       value="{{ $editing ? old('description_fa', $tag->description_fa) : $tag->description_fa }}">
                                            </div>

                                            <div class="field">
                                                <label class="label">&nbsp;</label>
                                                <div class="row">
                                                    <button class="btn btn--primary btn--sm" type="submit">ذخیره</button>
                                                    <button class="btn btn--ghost btn--sm js-cancel" type="button"
                                                            data-target="edit-{{ $tag->id }}">انصراف</button>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card__foot">
                <span class="small muted">
                    برای دیدن نمونه‌های یک تگ، روی شمار نمونه‌اش بزنید تا فهرست با همان پالایه باز شود.
                </span>
            </div>
        @endif
    </div>

@endsection

@push('scripts')
    <script>
        (function () {
            var toggle = function (id, show) {
                var row = document.getElementById(id);
                if (row) { row.classList.toggle('hidden', !show); }
            };

            document.querySelectorAll('.js-edit').forEach(function (button) {
                button.addEventListener('click', function () {
                    var row = document.getElementById(button.dataset.target);
                    if (row) { toggle(button.dataset.target, row.classList.contains('hidden')); }
                });
            });

            document.querySelectorAll('.js-cancel').forEach(function (button) {
                button.addEventListener('click', function () { toggle(button.dataset.target, false); });
            });
        })();
    </script>
@endpush
