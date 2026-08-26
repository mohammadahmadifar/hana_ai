@extends('layouts.panel')

@section('title', 'تصویر تستی #'.$image->id)
@section('page_title', 'تصویر تستی — '.($image->documentType?->label_fa ?? 'نامشخص'))

@section('topbar_actions')
    <a href="{{ route('testimage.create', ['from' => $image->id]) }}" class="btn btn--ghost btn--sm">
        🔁 همین را با اعوجاج دیگر بساز
    </a>
    <a href="{{ route('testimage.index') }}" class="btn btn--ghost btn--sm">🗃 تصاویر من</a>
@endsection

@php
    use App\Support\PersianValue;

    $mediaAugmented = route('media', ['disk' => $image->disk, 'path' => $image->path]);
    $mediaClean = filled($image->clean_path)
        ? route('media', ['disk' => $image->disk, 'path' => $image->clean_path])
        : null;

    $hasAugmentation = $applied !== [];
@endphp

@section('content')

    @error('ocr')
        <div class="alert alert--bad" role="alert">
            <span class="alert__icon" aria-hidden="true">⛔</span>
            <div class="alert__body"><span>{{ $message }}</span></div>
        </div>
    @enderror

    @error('dataset')
        <div class="alert alert--bad" role="alert">
            <span class="alert__icon" aria-hidden="true">⛔</span>
            <div class="alert__body"><span>{{ $message }}</span></div>
        </div>
    @enderror

    <div class="stack">

        {{-- ===================== تصویرها ===================== --}}
        <div class="card">
            <div class="card__head">
                <h2>تصویر ساخته‌شده</h2>
                <div class="spacer"></div>

                @if ($hasAugmentation)
                    @foreach ($applied as $item)
                        <x-badge tone="warn" label="{{ $item['icon'] }} {{ $item['label'] }} {{ $item['value'] }} {{ $item['unit'] }}" />
                    @endforeach
                @else
                    <x-badge tone="ok" label="✨ بدون اعوجاج" />
                @endif
            </div>

            <div class="card__body">
                <div class="grid grid--2">

                    <div class="stack stack--sm">
                        <div class="row">
                            <strong class="small">
                                {{ $hasAugmentation ? 'با اعوجاج — همین به OCR داده می‌شود' : 'تصویر نهایی' }}
                            </strong>
                        </div>
                        <div class="thumb">
                            <img src="{{ $mediaAugmented }}"
                                 alt="تصویر تستی {{ $image->documentType?->label_fa }}"
                                 loading="lazy">
                        </div>
                        <a class="btn btn--ghost btn--sm btn--block"
                           href="{{ route('testimage.download', $image) }}">⬇ دانلود این تصویر</a>
                    </div>

                    <div class="stack stack--sm">
                        <div class="row">
                            <strong class="small">تصویر تمیز — مرجع مقایسه</strong>
                        </div>
                        @if ($mediaClean)
                            <div class="thumb">
                                <img src="{{ $mediaClean }}" alt="تصویر تمیز" loading="lazy">
                            </div>
                            <a class="btn btn--ghost btn--sm btn--block"
                               href="{{ route('testimage.download', ['testImage' => $image, 'clean' => 1]) }}">⬇ دانلود تصویر تمیز</a>
                        @else
                            <x-empty-state icon="🖼" title="نسخهٔ تمیز ذخیره نشده" />
                        @endif
                    </div>

                </div>

                <div class="row tiny faint">
                    <span>ابعاد: <x-num :value="$image->width" />×<x-num :value="$image->height" /> پیکسل</span>
                    <span>·</span>
                    <span>ساخت: <x-jdate :value="$image->created_at" time /></span>
                    @if ($image->user)
                        <span>·</span>
                        <span>سازنده: {{ $image->user->name }}</span>
                    @endif
                </div>
            </div>

            <div class="card__foot">
                <form method="POST" action="{{ route('testimage.ocr', $image) }}">
                    @csrf
                    <button type="submit" class="btn btn--primary">🔍 OCR بگیر</button>
                </form>

                @if ($caseServices->isNotEmpty())
                    {{-- تسک ۶۲۷: همین تصویر مستقیم وارد فرایند بررسی شود، بدون
                         دانلود و بارگذاری دستی. یک پروندهٔ پیش‌نویس تازه ساخته
                         می‌شود و این تصویر مدرکِ اولش است. --}}
                    <form method="POST" action="{{ route('cases.fromTestImage', $image) }}" class="row">
                        @csrf
                        @if ($caseServices->count() > 1)
                            <select name="service_type_id" id="case-service" class="select"
                                    aria-label="نوع خدمت پرونده">
                                @foreach ($caseServices as $service)
                                    <option value="{{ $service->id }}">{{ $service->label_fa }}</option>
                                @endforeach
                            </select>
                        @else
                            <input type="hidden" name="service_type_id" value="{{ $caseServices->first()->id }}">
                        @endif
                        <button type="submit" class="btn btn--ghost">📤 بفرست به فرایند بررسی</button>
                    </form>
                @endif

                @if (auth()->user()->canManageDataset())
                    @if ($inDataset)
                        <x-badge tone="ok" label="✔ در دیتاست ثبت شده" />
                    @else
                        <form method="POST" action="{{ route('testimage.dataset', $image) }}">
                            @csrf
                            <button type="submit" class="btn btn--ghost">➕ افزودن به دیتاست</button>
                        </form>
                    @endif
                @endif

                <div class="spacer"></div>

                <form method="POST" action="{{ route('testimage.destroy', $image) }}"
                      data-confirm="این تصویر تستی و فایل‌هایش حذف شوند؟" >
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn--danger btn--sm">🗑 حذف</button>
                </form>
            </div>
        </div>

        {{-- ===================== دادهٔ چاپ‌شده ===================== --}}
        <div class="card">
            <div class="card__head">
                <h2>چه چیزی روی مدرک چاپ شد</h2>
                <div class="spacer"></div>
                <span class="tiny faint">این‌ها همان مقادیر مرجع (ground truth) برای سنجش OCR هستند.</span>
            </div>

            <div class="card__body">
                @if ($rows === [])
                    <x-empty-state icon="📄" title="هیچ مقداری ثبت نشده" />
                @else
                    <div class="scroll-x">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>فیلد</th>
                                    <th>مقدار چاپ‌شده</th>
                                    @if ($ocr)
                                        <th>در متن OCR</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $ocrByKey = collect($ocr['checks'] ?? [])->keyBy('key');
                                @endphp
                                @foreach ($rows as $row)
                                    <tr>
                                        <th scope="row">{{ $row['label'] }}</th>
                                        <td class="{{ $row['type'] === 'vin' ? 'mono ltr' : 'num' }}">
                                            {{ $row['value'] }}
                                            @unless ($row['printed'])
                                                <x-badge tone="warn" label="روی قالب چاپ نشد" />
                                            @endunless
                                        </td>
                                        @if ($ocr)
                                            <td>
                                                @if (! $row['printed'])
                                                    <x-badge tone="warn" label="— سنجیده نشد" />
                                                @elseif ($ocrByKey->has($row['key']) && $ocrByKey[$row['key']]['found'])
                                                    <x-badge tone="ok" label="✔ پیدا شد" />
                                                @else
                                                    <x-badge tone="bad" label="✘ پیدا نشد" />
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @php
                        $unprinted = array_values(array_map(
                            static fn (array $row): string => $row['label'],
                            array_filter($rows, static fn (array $row): bool => ! $row['printed']),
                        ));
                    @endphp

                    @if ($unprinted !== [])
                        <p class="hint">
                            «{{ implode('»، «', $unprinted) }}» در چیدمان این قالب جایی برای چاپ ندارد،
                            پس روی تصویر نیامده است و در سنجش OCR هم شمرده نمی‌شود.
                        </p>
                    @endif
                @endif
            </div>
        </div>

        {{-- ===================== نتیجهٔ OCR ===================== --}}
        <div class="card">
            <div class="card__head">
                <h2>نتیجهٔ OCR</h2>
                <div class="spacer"></div>
                @if ($ocr)
                    <x-badge tone="info" label="زمان: {{ PersianValue::toPersianDigits((string) $ocr['duration_ms']) }} میلی‌ثانیه" />
                @endif
            </div>

            <div class="card__body">
                @if (! $ocr)
                    <x-empty-state
                        icon="🔍"
                        title="هنوز OCR گرفته نشده"
                        hint="دکمهٔ «OCR بگیر» بالا را بزنید تا موتور همین تصویر را بخواند و بگوید هر فیلد پیدا شد یا نه.">
                        <form method="POST" action="{{ route('testimage.ocr', $image) }}">
                            @csrf
                            <button type="submit" class="btn btn--primary btn--sm">🔍 OCR بگیر</button>
                        </form>
                    </x-empty-state>
                @else
                    @php
                        $total = max(1, (int) $ocr['total']);
                        $percent = round(($ocr['found'] / $total) * 100);
                        $tone = $percent >= 70 ? 'ok' : ($percent >= 35 ? 'warn' : 'bad');
                    @endphp

                    <div class="grid grid--3">
                        <x-stat :value="PersianValue::toPersianDigits($ocr['found'].' از '.$ocr['total'])"
                                label="فیلد پیداشده در متن" :tone="$tone" />
                        <x-stat :value="$ocr['char_count']" label="نویسهٔ خوانده‌شده" />
                        <x-stat :value="$ocr['line_count']" label="خط خوانده‌شده" />
                    </div>

                    <x-bar :percent="$percent" :tone="$tone" label="سهم فیلدهای پیداشده" />

                    <div class="row">
                        @foreach ($ocr['checks'] as $check)
                            <x-badge :tone="$check['found'] ? 'ok' : 'bad'" dot
                                     label="{{ $check['label'] }}" />
                        @endforeach
                    </div>

                    @if ($percent < 100)
                        <div class="alert alert--info" role="note">
                            <span class="alert__icon" aria-hidden="true">🎯</span>
                            <div class="alert__body">
                                <strong>این عدد پایین، خودش نتیجه است.</strong>
                                <span>هر فیلدی که «پیدا نشد» یک مورد برای بهبود پیش‌پردازش یا قالب است. با کم و زیاد کردن یک اعوجاج و ساخت دوبارهٔ همین داده، آستانهٔ تحمل موتور برای آن خرابی به‌دست می‌آید.</span>
                            </div>
                        </div>
                    @endif

                    <div class="stack stack--sm">
                        <strong class="small">متن خام برگشتی از Tesseract</strong>
                        <pre class="readout">{{ trim($ocr['raw_text']) !== '' ? $ocr['raw_text'] : 'موتور هیچ متنی نخواند.' }}</pre>
                    </div>

                    @if (filled($ocr['extra']['vin'] ?? null) || filled($ocr['extra']['plate'] ?? null))
                        <div class="scroll-x">
                            <table class="table">
                                <thead>
                                    <tr><th>استخراج ویژهٔ کارت خودرو</th><th>مقدار خوانده‌شده</th></tr>
                                </thead>
                                <tbody>
                                    @if (filled($ocr['extra']['vin'] ?? null))
                                        <tr><th scope="row">شمارهٔ شاسی</th><td class="mono ltr">{{ $ocr['extra']['vin'] }}</td></tr>
                                    @endif
                                    @if (filled($ocr['extra']['plate'] ?? null))
                                        <tr><th scope="row">پلاک</th><td><x-plate :value="$ocr['extra']['plate']" size="sm" /></td></tr>
                                    @endif
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if (filled($ocr['preprocessed'] ?? null))
                        <div class="stack stack--sm">
                            <strong class="small">تصویری که OCR واقعاً می‌بیند (بعد از پیش‌پردازش)</strong>
                            <div class="thumb">
                                <img src="{{ route('media', ['disk' => $image->disk, 'path' => $ocr['preprocessed']]) }}"
                                     alt="تصویر پیش‌پردازش‌شده" loading="lazy">
                            </div>
                        </div>
                    @endif
                @endif
            </div>
        </div>

        {{-- ===================== اعوجاج‌های اعمال‌شده ===================== --}}
        @if ($hasAugmentation)
            <div class="card">
                <div class="card__head"><h2>اعوجاج‌های اعمال‌شده</h2></div>
                <div class="card__body">
                    <div class="scroll-x">
                        <table class="table">
                            <thead>
                                <tr><th>اعوجاج</th><th>شدت</th><th>چرا در دنیای واقعی پیش می‌آید</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($applied as $item)
                                    <tr>
                                        <th scope="row">{{ $item['icon'] }} {{ $item['label'] }}</th>
                                        <td class="num nowrap">{{ $item['value'] }} {{ $item['unit'] }}</td>
                                        <td class="small muted">{{ $augmentations[$item['key']]['why'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="hint">
                        همین داده را می‌توانید با شدت دیگری بسازید:
                        <a href="{{ route('testimage.create', ['from' => $image->id]) }}">ساخت دوباره با اعوجاج دیگر</a>
                    </p>
                </div>
            </div>
        @endif

    </div>

@endsection
