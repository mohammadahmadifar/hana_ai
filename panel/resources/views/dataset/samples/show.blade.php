@extends('layouts.panel')

@section('title', 'نمونهٔ شمارهٔ '.\App\Support\Jalali::digits($sample->id))
@section('page_title', 'جزئیات نمونهٔ دیتاست')

@php
    use App\Http\Controllers\Dataset\SampleController;
    use App\Support\Jalali;

    $fa = fn ($n) => Jalali::digits($n);
    $safeColor = fn (?string $c) => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c) ? $c : '#64748b';

    // مقدارهای تودرتو را خوانا می‌کند (آرایه → JSON فارسی‌خوان، بولین → بله/خیر).
    $readable = function ($value) {
        if (is_bool($value)) {
            return $value ? 'بله' : 'خیر';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $value === null || $value === '' ? '—' : (string) $value;
    };

    $imagePath = $tab === 'clean' ? $sample->clean_path : $sample->path;
    $boxed = $annotations->filter(fn ($a) => $a->hasBox());

    /*
     * پارامترهای اعوجاج شکل‌های مختلفی دارند و همه باید خوانا چاپ شوند:
     *   {"rotation": {"enabled": true, "angle": 7}}
     *   {"applied": [{"name": "rotation", "angle": 1.7}], "requested": {...}, "batch_id": 2}
     * این‌جا هر ساختاری به ردیف‌های یکدست «برچسب / گروه / وضعیت / مقدار» تبدیل می‌شود.
     */
    $augRows = [];

    $augGroupLabels = ['applied' => 'اعمال‌شده', 'requested' => 'درخواستی'];

    // نقشه‌ای که کلید enabled/name یا دست‌کم یک مقدار ساده دارد، خودش یک اعوجاج است؛
    // نقشه‌ای که همهٔ مقدارهایش آرایه‌اند، گروهی از اعوجاج‌هاست.
    $isAugDescriptor = function (array $node): bool {
        if (array_key_exists('enabled', $node) || array_key_exists('name', $node)) {
            return true;
        }

        foreach ($node as $value) {
            if (! is_array($value)) {
                return true;
            }
        }

        return $node === [];
    };

    $walkAug = function (array $node, ?string $group, int $depth = 0) use (&$walkAug, &$augRows, $isAugDescriptor, $augGroupLabels) {
        if ($depth > 3) {
            return;
        }

        foreach ($node as $key => $value) {
            $keyLabel = is_int($key) ? null : (string) $key;
            $groupLabel = $group ?? ($keyLabel === null ? null : ($augGroupLabels[$keyLabel] ?? $keyLabel));

            if (is_array($value) && array_is_list($value)) {
                // فهرست اعوجاج‌های اعمال‌شده: خودِ حضورشان یعنی اجرا شده‌اند.
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $name = isset($item['name']) && is_scalar($item['name']) ? (string) $item['name'] : ($keyLabel ?? '');
                        $augRows[] = [
                            'label' => SampleController::augmentationLabel($name),
                            'group' => $groupLabel,
                            'enabled' => array_key_exists('enabled', $item) ? (bool) $item['enabled'] : true,
                            'params' => collect($item)->except(['name', 'enabled'])->all(),
                            'value' => null,
                        ];
                    } else {
                        $augRows[] = [
                            'label' => SampleController::augmentationLabel((string) $item),
                            'group' => $groupLabel,
                            'enabled' => true,
                            'params' => [],
                            'value' => null,
                        ];
                    }
                }

                continue;
            }

            if (is_array($value)) {
                if (! $isAugDescriptor($value)) {
                    $walkAug($value, $groupLabel, $depth + 1);

                    continue;
                }

                $name = isset($value['name']) && is_scalar($value['name']) ? (string) $value['name'] : ($keyLabel ?? '');
                $augRows[] = [
                    'label' => SampleController::augmentationLabel($name),
                    'group' => $group,
                    'enabled' => array_key_exists('enabled', $value) ? (bool) $value['enabled'] : null,
                    'params' => collect($value)->except(['name', 'enabled'])->all(),
                    'value' => null,
                ];

                continue;
            }

            $augRows[] = [
                'label' => SampleController::augmentationLabel($keyLabel ?? ''),
                'group' => $group,
                'enabled' => null,
                'params' => [],
                'value' => $value,
            ];
        }
    };

    $walkAug($sample->augmentation_params ?: [], null);
@endphp

@push('head')
    <style>
        .shot { position: relative; display: block; line-height: 0; }
        .shot img { display: block; width: 100%; height: auto; }
        .shot .bx {
            position: absolute;
            border: 1.5px solid var(--accent);
            background: var(--accent-wash);
            opacity: .55;
            border-radius: 2px;
            pointer-events: none;
        }
        .shot.is-off .bx { display: none; }
        .kv { width: 100%; border-collapse: collapse; }
        .kv td { padding: 6px 0; vertical-align: top; border-bottom: 1px solid var(--rule-soft); font-size: 13.5px; }
        .kv tr:last-child td { border-bottom: 0; }
        .kv td:first-child { color: var(--ink-soft); width: 42%; }
        .tagdot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-inline-end: 5px; vertical-align: middle; }
    </style>
@endpush

@section('content')

    <div class="page-head">
        <div>
            <h1>نمونهٔ شمارهٔ {{ $fa($sample->id) }}</h1>
        </div>
        <div class="page-head__actions">
            <a class="btn btn--ghost" href="{{ route('dataset.samples.index') }}">بازگشت به فهرست</a>

            <form method="POST" action="{{ route('dataset.samples.verify', $sample) }}" style="display:inline">
                @csrf
                @if ($sample->is_verified)
                    <button class="btn" type="submit">لغو تایید</button>
                @else
                    <button class="btn btn--primary" type="submit">تایید برچسب‌ها</button>
                @endif
            </form>

            <form method="POST" action="{{ route('dataset.samples.destroy', $sample) }}" style="display:inline"
                  data-confirm="نمونهٔ شمارهٔ {{ $fa($sample->id) }} و فایل‌هایش برای همیشه حذف شود؟" >
                @csrf
                @method('DELETE')
                <button class="btn btn--danger" type="submit">حذف نمونه</button>
            </form>
        </div>
        <p class="page-head__sub">
            {{ $sample->documentType?->label_fa ?? 'نوع مدرک نامشخص' }} ·
            {{ $sources[$sample->source] ?? $sample->source }} ·
            ثبت در {{ Jalali::long($sample->created_at, true) }}
        </p>
    </div>

    <div class="grid grid--2">

        <div class="card">
            <div class="card__head">
                <h2>تصویر نمونه</h2>
                <span class="spacer"></span>
                @if ($boxed->isNotEmpty())
                    <label class="check tiny">
                        <input type="checkbox" id="boxes-toggle" checked>
                        نمایش کادر فیلدها
                    </label>
                @endif
            </div>

            <div class="card__body">
                @if (filled($sample->clean_path))
                    <nav class="tabs">
                        <a class="tab {{ $tab === 'final' ? 'is-active' : '' }}"
                           href="{{ route('dataset.samples.show', $sample) }}">تصویر نهایی</a>
                        <a class="tab {{ $tab === 'clean' ? 'is-active' : '' }}"
                           href="{{ route('dataset.samples.show', ['sample' => $sample, 'tab' => 'clean']) }}">تصویر تمیز</a>
                    </nav>
                @endif

                @if (filled($imagePath))
                    <div class="thumb">
                        <div class="shot" id="shot">
                            <img src="{{ route('media', ['disk' => $sample->disk, 'path' => $imagePath]) }}"
                                 alt="تصویر نمونهٔ {{ $fa($sample->id) }}">
                            @foreach ($boxed as $annotation)
                                <span class="bx"
                                      style="left: {{ round(max(0, min(1, $annotation->bbox_x)) * 100, 3) }}%;
                                             top: {{ round(max(0, min(1, $annotation->bbox_y)) * 100, 3) }}%;
                                             width: {{ round(max(0, min(1, $annotation->bbox_w)) * 100, 3) }}%;
                                             height: {{ round(max(0, min(1, $annotation->bbox_h)) * 100, 3) }}%"
                                      title="{{ $fieldLabels[$annotation->field_key] ?? $annotation->field_key }}"></span>
                            @endforeach
                        </div>
                    </div>
                @else
                    <x-empty-state icon="🖼" title="فایل تصویر این نما ثبت نشده" />
                @endif

                <div class="small muted">
                    ابعاد:
                    @if ($sample->width && $sample->height)
                        <span class="num">{{ $fa($sample->width) }}×{{ $fa($sample->height) }}</span> پیکسل
                    @else
                        نامشخص
                    @endif
                    · مسیر فایل: <span class="mono ltr tiny">{{ $imagePath ?: '—' }}</span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card__head">
                <h2>برچسب‌های فیلد</h2>
                <span class="spacer"></span>
                <span class="badge badge--info">{{ $fa($annotations->count()) }} برچسب</span>
                @if ($sample->is_verified)
                    <span class="badge badge--ok badge--dot">تاییدشده</span>
                @else
                    <span class="badge badge--warn badge--dot">تاییدنشده</span>
                @endif
            </div>

            @if ($annotations->isEmpty())
                <div class="card__body">
                    <x-empty-state
                        icon="🏷"
                        title="برای این نمونه برچسبی ثبت نشده"
                        hint="برچسب‌ها هنگام تولید ساخته می‌شوند یا در بخش «تگ‌گذاری» دستی وارد می‌شوند." />
                </div>
            @else
                <div class="scroll-x">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>فیلد</th>
                                <th>مقدار</th>
                                <th>کادر</th>
                                <th>منبع</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($annotations as $annotation)
                                <tr>
                                    <td>
                                        <span class="strong">{{ $fieldLabels[$annotation->field_key] ?? $annotation->field_key }}</span>
                                        <div class="tiny faint mono ltr">{{ $annotation->field_key }}</div>
                                    </td>
                                    <td>{{ $annotation->value ?: '—' }}</td>
                                    <td>
                                        @if ($annotation->hasBox())
                                            <span class="badge badge--ok">دارد</span>
                                        @else
                                            <span class="badge">ندارد</span>
                                        @endif
                                    </td>
                                    <td class="small muted">
                                        @switch($annotation->source)
                                            @case('generated') تولیدشده @break
                                            @case('manual') دستی @break
                                            @case('correction') اصلاح‌شده @break
                                            @default {{ $annotation->source }}
                                        @endswitch
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

    </div>

    <div class="grid grid--2">

        <div class="card">
            <div class="card__head">
                <h2>مشخصات نمونه</h2>
            </div>
            <div class="card__body">
                <table class="kv">
                    <tbody>
                        <tr>
                            <td>نوع مدرک</td>
                            <td>{{ $sample->documentType?->label_fa ?? '—' }}</td>
                        </tr>
                        <tr>
                            <td>منبع</td>
                            <td>{{ $sources[$sample->source] ?? $sample->source }}</td>
                        </tr>
                        <tr>
                            <td>بخش دیتاست</td>
                            <td>{{ $splits[$sample->split] ?? $sample->split }}</td>
                        </tr>
                        <tr>
                            <td>اعوجاج</td>
                            <td>{{ SampleController::augmentationLabel($sample->augmentation) }}</td>
                        </tr>
                        <tr>
                            <td>نام فایل اصلی</td>
                            <td class="ltr mono tiny">{{ $sample->original_name ?: '—' }}</td>
                        </tr>
                        <tr>
                            <td>ثبت‌کننده</td>
                            <td>{{ $creatorName ?? '—' }}</td>
                        </tr>
                        <tr>
                            <td>تاریخ ثبت</td>
                            <td>{{ Jalali::format($sample->created_at, true) }}</td>
                        </tr>
                        <tr>
                            <td>تایید برچسب</td>
                            <td>
                                @if ($sample->is_verified)
                                    توسط {{ $verifierName ?? 'کاربر حذف‌شده' }}
                                    در {{ Jalali::format($sample->verified_at, true) }}
                                @else
                                    <span class="faint">هنوز تایید نشده</span>
                                @endif
                            </td>
                        </tr>
                        @if (filled($sample->notes))
                            <tr>
                                <td>یادداشت</td>
                                <td>{{ $sample->notes }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card__head">
                <h2>پارامترهای اعوجاج</h2>
            </div>
            <div class="card__body">
                @if (empty($augRows))
                    <x-empty-state icon="🎛" title="پارامتری ثبت نشده"
                                   hint="این نمونه بدون اعوجاج ساخته شده یا پارامترهایش ذخیره نشده است." />
                @else
                    <table class="kv">
                        <tbody>
                            @foreach ($augRows as $augRow)
                                <tr>
                                    <td>
                                        {{ $augRow['label'] }}
                                        @if (filled($augRow['group']))
                                            <span class="tiny faint">({{ $augRow['group'] }})</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($augRow['enabled'] !== null)
                                            <x-badge :tone="$augRow['enabled'] ? 'ok' : null"
                                                     :label="$augRow['enabled'] ? 'فعال' : 'غیرفعال'" />
                                        @endif

                                        @if ($augRow['value'] !== null)
                                            <span class="mono ltr tiny">{{ $readable($augRow['value']) }}</span>
                                        @endif

                                        @if (! empty($augRow['params']))
                                            <span class="small muted">
                                                @foreach ($augRow['params'] as $paramKey => $paramValue)
                                                    <span class="nowrap mono ltr tiny">{{ $paramKey }}={{ $readable($paramValue) }}</span>@if (! $loop->last) · @endif
                                                @endforeach
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

    </div>

    <div class="grid grid--2">

        <div class="card">
            <div class="card__head">
                <h2>دادهٔ تولید</h2>
                <span class="spacer"></span>
                <span class="tiny faint">مقادیری که روی قالب چاپ شده‌اند</span>
            </div>
            <div class="card__body">
                @php $payload = $sample->generation_payload ?: []; @endphp

                @if (empty($payload))
                    <x-empty-state icon="🧾" title="دادهٔ تولیدی ثبت نشده"
                                   hint="نمونه‌های آپلودی معمولاً این بخش را ندارند." />
                @else
                    <table class="kv">
                        <tbody>
                            @foreach ($payload as $key => $value)
                                <tr>
                                    <td>
                                        {{ $fieldLabels[$key] ?? $key }}
                                        <div class="tiny faint mono ltr">{{ $key }}</div>
                                    </td>
                                    <td>{{ $readable($value) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card__head">
                <h2>تگ‌های نمونه</h2>
                <span class="spacer"></span>
                <a class="btn btn--sm btn--ghost" href="{{ route('dataset.tags.index') }}">مدیریت تگ‌ها</a>
            </div>
            <div class="card__body">
                @if ($sample->tags->isEmpty())
                    <p class="small muted">هنوز تگی به این نمونه نخورده است.</p>
                @else
                    <div class="row">
                        @foreach ($sample->tags as $tag)
                            <form method="POST" action="{{ route('dataset.samples.tags.detach', ['sample' => $sample, 'tag' => $tag]) }}">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn--sm" type="submit" title="برداشتن تگ «{{ $tag->name }}»">
                                    <span class="tagdot" style="background: {{ $safeColor($tag->color) }}"></span>{{ $tag->name }}
                                    <span aria-hidden="true">×</span>
                                </button>
                            </form>
                        @endforeach
                    </div>
                @endif

                @if ($availableTags->isEmpty())
                    <p class="small faint">
                        تگ دیگری برای افزودن نمانده است.
                    </p>
                @else
                    <form method="POST" action="{{ route('dataset.samples.tags.attach', $sample) }}" class="row">
                        @csrf
                        <select class="select" name="tag_id" aria-label="تگ تازه" style="max-width:220px">
                            @foreach ($availableTags as $tag)
                                <option value="{{ $tag->id }}">{{ $tag->name }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn--sm btn--primary" type="submit">افزودن تگ</button>
                    </form>
                @endif
            </div>
        </div>

    </div>

@endsection

@push('scripts')
    <script>
        (function () {
            var toggle = document.getElementById('boxes-toggle');
            var shot = document.getElementById('shot');

            if (!toggle || !shot) { return; }

            toggle.addEventListener('change', function () {
                shot.classList.toggle('is-off', !toggle.checked);
            });
        })();
    </script>
@endpush
