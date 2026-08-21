{{--
    کارت یک مدرک در گام «دریافت مدارک».
    ورودی‌ها: $row (یک ردیف چک‌لیست)، $case، $editable

    نکتهٔ مهم: پیش‌نمایش فقط از روت محافظت‌شدهٔ media گرفته می‌شود؛ فایل مدرک
    هرگز روی دیسک عمومی نیست.
--}}
@php
    use App\Support\PersianValue;

    $type = $row['type'];
    $document = $row['document'];
    $state = $row['state'];

    $stateMeta = [
        'missing' => ['tone' => 'warn', 'label' => 'بارگذاری نشده', 'icon' => '⬜'],
        'pending' => ['tone' => 'info', 'label' => 'در انتظار بررسی', 'icon' => '⏳'],
        'rejected' => ['tone' => 'bad', 'label' => 'پذیرفته نشد', 'icon' => '⛔'],
        'ready' => ['tone' => 'ok', 'label' => 'آماده', 'icon' => '✅'],
    ][$state];

    $isImage = $document && is_string($document->mime) && str_starts_with($document->mime, 'image/');

    $sizeText = null;
    if ($document && $document->size_bytes) {
        $sizeText = $document->size_bytes >= 1048576
            ? PersianValue::decimal($document->size_bytes / 1048576, 1).' مگابایت'
            : PersianValue::decimal($document->size_bytes / 1024, 0).' کیلوبایت';
    }

    $inputId = 'doc-file-'.$type->id;
@endphp

<div class="card">
    <div class="card__head">
        <h3>{{ $type->label_fa }}</h3>
        @if (! $row['required'])
            <span class="tiny faint">اختیاری</span>
        @endif
        <div class="spacer"></div>
        <x-badge :tone="$stateMeta['tone']" label="{{ $stateMeta['icon'] }} {{ $stateMeta['label'] }}" />
    </div>

    <div class="card__body">

        @if ($document)
            @if ($isImage)
                <span class="thumb">
                    <img src="{{ route('media', ['disk' => $document->disk, 'path' => $document->path, 'w' => 320]) }}"
                         alt="پیش‌نمایش {{ $type->label_fa }}" loading="lazy">
                </span>
            @else
                <div class="alert alert--warn" role="status">
                    <span class="alert__icon" aria-hidden="true">🗎</span>
                    <div class="alert__body">
                        <span>این فایل تصویر نیست، پس پیش‌نمایشی از آن نمایش داده نمی‌شود.</span>
                    </div>
                </div>
            @endif

            <div class="row tiny faint">
                <span class="nowrap">{{ $document->original_name }}</span>
                @if ($sizeText)
                    <span>·</span>
                    <span class="nowrap">{{ $sizeText }}</span>
                @endif
                @if ($document->width && $document->height)
                    <span>·</span>
                    <span class="nowrap num">{{ PersianValue::toPersianDigits($document->width.'×'.$document->height) }}</span>
                @endif
                <span>·</span>
                <x-jdate :value="$document->updated_at" time />
            </div>
        @else
            <p class="small muted">
                هنوز فایلی برای این مدرک بارگذاری نشده است. تا وقتی این مدرک نیاید، پرونده «ناقص» می‌ماند.
            </p>
        @endif

        @foreach ($row['issues'] as $issue)
            <div class="alert alert--{{ ($issue['severity'] ?? 'error') === 'warning' ? 'warn' : 'bad' }}" role="alert">
                <span class="alert__icon" aria-hidden="true">{{ ($issue['severity'] ?? 'error') === 'warning' ? '⚠️' : '⛔' }}</span>
                <div class="alert__body">
                    <strong>{{ $issue['message_fa'] ?? 'ایراد نامشخص' }}</strong>
                    @if (filled($issue['hint_fa'] ?? null))
                        <span>{{ $issue['hint_fa'] }}</span>
                    @endif
                </div>
            </div>
        @endforeach

        @if ($editable)
            <form method="POST" action="{{ route('cases.documents.store', $case) }}"
                  enctype="multipart/form-data" data-upload-form>
                @csrf
                <input type="hidden" name="document_type_id" value="{{ $type->id }}">

                <label class="drop" for="{{ $inputId }}" data-drop data-input="{{ $inputId }}">
                    <div class="drop__icon" aria-hidden="true">{{ $document ? '🔁' : '⬆️' }}</div>
                    <div class="small strong" data-drop-title>
                        {{ $document ? 'فایل تازه را این‌جا بگذارید تا جایگزین شود' : 'فایل مدرک را این‌جا بکشید یا کلیک کنید' }}
                    </div>
                    <div class="tiny faint">JPG یا PNG یا WEBP — عکس واضح و کامل از کل مدرک</div>

                    <input class="hidden" id="{{ $inputId }}" type="file" name="file"
                           accept="image/jpeg,image/png,image/webp" data-upload-input>
                </label>

                <noscript>
                    <div class="row row--end" style="margin-top: 10px;">
                        <button type="submit" class="btn btn--primary btn--sm">بارگذاری</button>
                    </div>
                </noscript>
            </form>
        @endif

    </div>

    @if ($editable && $document)
        <div class="card__foot">
            <span class="tiny faint">برای جایگزینی، فایل تازه را روی کادر بالا بگذارید.</span>
            <div class="spacer"></div>
            <form method="POST" action="{{ route('cases.documents.destroy', [$case, $document]) }}"
                  data-confirm="فایل این مدرک حذف شود؟ پرونده دوباره ناقص می‌شود.">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn--danger btn--sm">🗑 حذف فایل</button>
            </form>
        </div>
    @endif
</div>
