{{--
    یک مدرک روی صفحهٔ نتیجه: تصویر مدرک در یک ستون، فیلدهای خوانده‌شده در ستون
    کناری. ورودی: $panel، $case، $reviewable، $correctors، $editingDocument

    چرا کنار هم: کارشناس باید بتواند «۸۱۴۳۰۳۷۳۸۱» را با همان چیزی که روی کارت
    چاپ شده بسنجد. جدول فیلدِ بی‌تصویر، بررسی انسانی را به تاییدِ چشم‌بسته
    تبدیل می‌کند.

    تصویر فقط از روت محافظت‌شدهٔ media می‌آید — فایل مدرک هرگز روی دیسک عمومی
    نیست (قانون پروژه).
--}}
@php
    use App\Support\PersianValue;

    $type = $panel['type'];
    $document = $panel['document'];
    $fields = $panel['fields'];

    $stateMeta = [
        'missing' => ['tone' => 'warn', 'label' => 'بارگذاری نشده', 'icon' => '⬜'],
        'pending' => ['tone' => 'info', 'label' => 'در انتظار بررسی', 'icon' => '⏳'],
        'rejected' => ['tone' => 'bad', 'label' => 'فایل پذیرفته نشد', 'icon' => '⛔'],
        'ready' => ['tone' => 'ok', 'label' => 'فایل سالم', 'icon' => '✅'],
    ][$panel['state']];

    // خطاهای فرم متعلق به همان مدرکی است که کارشناس داشت ذخیره‌اش می‌کرد؛
    // کلید فیلدها بین مدارک تکراری است (national_id روی هر سه مدرک هست)، پس
    // بدون این قید، خطای «کد ملی» روی هر سه کارت تکرار می‌شد.
    $isEditing = $document !== null && (int) $editingDocument === (int) $document->id;

    $editable = $reviewable && $document !== null;

    $imageId = 'doc-image-'.($document?->id ?? $type->id);

    $confidenceTone = fn (?float $c): string => $c === null ? 'bad' : ($c >= 70 ? 'ok' : ($c >= 45 ? 'warn' : 'bad'));

    // پلاک فقط وقتی به شکل پلاک نشان داده می‌شود که واقعاً با الگو بخواند
    // (تسک ۷۴۱)؛ مقدار ناخوانا در همان کادر readout آشنا می‌ماند، چون کادرِ
    // پلاکِ نصفه‌کاره به کارشناس می‌گوید «این را خواندیم».
    $plateParts = fn (?string $value): ?array => PersianValue::plateParts($value);

    $ocrStatusLabel = [
        'pending' => 'در انتظار OCR',
        'queued' => 'در صف OCR',
        'running' => 'در حال OCR',
        'done' => 'OCR انجام شد',
        'failed' => 'OCR شکست خورد',
    ][(string) ($document?->ocr_status ?? '')] ?? null;
@endphp

<div class="card">
    <div class="card__head">
        <h3>{{ $type->label_fa }}</h3>
        @unless ($panel['required'])
            <span class="tiny faint">اختیاری</span>
        @endunless
        <div class="spacer"></div>
        @if ($ocrStatusLabel !== null)
            <span class="tiny faint">{{ $ocrStatusLabel }}</span>
        @endif
        <x-badge :tone="$stateMeta['tone']" label="{{ $stateMeta['icon'] }} {{ $stateMeta['label'] }}" />
    </div>

    <div class="card__body">

        @if ($document === null)
            <x-empty-state
                icon="📄"
                title="این مدرک بارگذاری نشده است"
                hint="بدون این مدرک، مؤلفهٔ «کامل بودن مدارک» امتیاز کامل نمی‌گیرد و تطابق بین مدارک هم روی فیلدهای آن بررسی نمی‌شود.">
                <a href="{{ route('cases.documents.edit', $case) }}" class="btn btn--ghost btn--sm">📎 رفتن به صفحهٔ مدارک</a>
            </x-empty-state>
        @else

            <div class="grid grid--2">

                {{-- ستون یک: تصویر مدرک --}}
                <div class="stack stack--sm">
                    @if ($panel['image'] !== null)
                        <div class="scroll-x">
                            <span class="thumb">
                                <img id="{{ $imageId }}"
                                     src="{{ $panel['image'] }}"
                                     alt="تصویر {{ $type->label_fa }} پروندهٔ {{ PersianValue::toPersianDigits($case->code) }}"
                                     loading="lazy">
                            </span>
                        </div>
                        <div class="row">
                            <button type="button" class="btn btn--ghost btn--sm"
                                    data-zoom="{{ $imageId }}"
                                    data-zoom-in="🔍 بزرگ‌نمایی تصویر"
                                    data-zoom-out="🔎 اندازهٔ عادی">🔍 بزرگ‌نمایی تصویر</button>
                            <span class="tiny faint">برای خواندن ارقام ریز، تصویر را بزرگ کنید و افقی بکشید.</span>
                        </div>
                    @else
                        <div class="alert alert--warn" role="status">
                            <span class="alert__icon" aria-hidden="true">🗎</span>
                            <div class="alert__body">
                                <strong>این فایل تصویر نیست، پس پیش‌نمایشی ندارد.</strong>
                                <span>مقادیر زیر را نمی‌شود با چشم با مدرک سنجید؛ برای بررسی، فایل سالمِ تصویری از متقاضی بخواهید.</span>
                            </div>
                        </div>
                    @endif

                    <div class="row tiny faint">
                        <span class="nowrap">{{ $document->original_name ?: 'بدون نام' }}</span>
                        @if ($document->width && $document->height)
                            <span>·</span>
                            <span class="nowrap num">{{ PersianValue::toPersianDigits($document->width.'×'.$document->height) }}</span>
                        @endif
                        @if ($document->blur_score !== null)
                            <span>·</span>
                            <span class="nowrap">وضوح <x-num :value="$document->blur_score" :decimals="0" /></span>
                        @endif
                        @if ($document->brightness_score !== null)
                            <span>·</span>
                            <span class="nowrap">روشنایی <x-num :value="$document->brightness_score" :decimals="0" /></span>
                        @endif
                    </div>

                    @foreach ($panel['issues'] as $issue)
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

                    @if ($panel['ocr'] !== null && filled($panel['ocr']->raw_text))
                        <details>
                            <summary class="tiny">متن خامی که OCR خواند</summary>
                            <pre class="readout">{{ $panel['ocr']->raw_text }}</pre>
                        </details>
                    @endif
                </div>

                {{-- ستون دو: فیلدهای خوانده‌شده --}}
                <div class="stack stack--sm">
                    @if ($fields === [])
                        <p class="small muted">برای این نوع مدرک هیچ فیلدی در دادهٔ مرجع تعریف نشده است.</p>
                    @else
                        <form method="POST" action="{{ route('cases.fields.update', [$case, $document]) }}">
                            @csrf

                            @foreach ($fields as $field)
                                @php
                                    $errorKey = 'fields.'.$field['key'];
                                    $hasError = $isEditing && $errors->has($errorKey);
                                    $shown = $isEditing ? old($errorKey, $field['display']) : $field['display'];
                                    $corrector = $field['row']?->corrected_by
                                        ? ($correctors[$field['row']->corrected_by] ?? null)
                                        : null;
                                @endphp

                                <div class="field">
                                    <label class="label" for="f-{{ $document->id }}-{{ $field['key'] }}">
                                        {{ $field['label'] }}
                                        @if ($field['required'])
                                            <span class="text-bad" title="فیلد اجباری">*</span>
                                        @endif
                                        @if ($field['cross_checked'])
                                            <span class="tiny faint">· با بقیهٔ مدارک مقایسه می‌شود</span>
                                        @endif
                                    </label>

                                    @if ($editable)
                                        <input type="text"
                                               class="input {{ $hasError ? 'is-invalid' : '' }}"
                                               id="f-{{ $document->id }}-{{ $field['key'] }}"
                                               name="fields[{{ $field['key'] }}]"
                                               value="{{ $shown }}"
                                               dir="{{ $field['value_type'] === 'vin' ? 'ltr' : 'rtl' }}"
                                               autocomplete="off"
                                               placeholder="{{ $field['hint'] }}">

                                        {{--
                                            پلاک زیر ورودی هم به شکل خودش نشان داده می‌شود (تسک ۷۴۱):
                                            کارشناس باید بتواند مقدار تایپ‌شده را با خودِ تصویر مدرک
                                            مقایسه کند، و «۱۲ ب ۳۴۵ ایران ۶۷» در یک input راست‌چین
                                            همان شکلی نیست که روی کارت است.
                                        --}}
                                        @if ($field['value_type'] === 'plate' && $plateParts($shown) !== null)
                                            <span class="row">
                                                <x-plate :value="$shown" size="sm" />
                                            </span>
                                        @endif
                                    @elseif ($field['value_type'] === 'plate' && $plateParts($shown) !== null)
                                        <div><x-plate :value="$shown" /></div>
                                    @else
                                        <div class="readout num">{{ $shown !== '' ? $shown : 'خوانده نشد' }}</div>
                                    @endif

                                    <div class="row tiny">
                                        @if ($field['source'] === 'manual')
                                            <x-badge tone="ok" label="✎ اصلاح کارشناس" />
                                            @if ($corrector !== null)
                                                <span class="faint">{{ $corrector }}</span>
                                            @endif
                                            @if ($field['row']?->corrected_at)
                                                <x-jdate :value="$field['row']->corrected_at" time class="faint" />
                                            @endif
                                        @elseif ($field['source'] === 'derived')
                                            {{-- تسک ۷۲۵: تاریخی که روی مدرک چاپ نشده و از فیلد دیگری درآمده --}}
                                            <x-badge tone="info" label="◷ محاسبه‌شده" />
                                            <span class="faint">از تاریخ صدور + ۱۰ سال — روی مدرک چاپ نشده است.</span>
                                        @elseif ($field['source'] === 'ocr')
                                            <x-badge tone="info" label="OCR" />
                                            <span class="faint">اطمینان</span>
                                            <span class="nowrap"><x-num :value="$field['confidence']" :decimals="0" /></span>
                                            <span style="flex: 1; min-width: 60px;">
                                                <x-bar :percent="$field['confidence'] ?? 0"
                                                       :tone="$confidenceTone($field['confidence'])"
                                                       label="اطمینان خواندن {{ $field['label'] }}" />
                                            </span>
                                        @else
                                            <x-badge tone="warn" label="خوانده نشد" />
                                            <span class="faint">اگر مقدارش روی تصویر خواناست، همین‌جا بنویسید.</span>
                                        @endif
                                    </div>

                                    @if ($hasError)
                                        <div class="error">{{ $errors->first($errorKey) }}</div>
                                    @endif
                                </div>
                            @endforeach

                            @if ($editable)
                                <div class="row row--end">
                                    <span class="tiny faint">
                                        فقط فیلدی که واقعاً عوض کنید ذخیره می‌شود؛ بقیه دست‌نخورده می‌مانند.
                                    </span>
                                    <div class="spacer"></div>
                                    <button type="submit" class="btn btn--primary btn--sm">💾 ذخیرهٔ اصلاح‌ها</button>
                                </div>
                            @endif
                        </form>
                    @endif

                    @if ($panel['checks']->isNotEmpty())
                        <div class="stack stack--sm">
                            <span class="tiny faint">بررسی‌های همین مدرک:</span>
                            @foreach ($panel['checks'] as $check)
                                @php
                                    $tone = \App\Http\Controllers\Cases\CaseReviewController::CHECK_TONES[$check->status] ?? 'info';
                                @endphp
                                <div class="row tiny">
                                    <x-badge :tone="$tone"
                                             label="{{ \App\Http\Controllers\Cases\CaseReviewController::CHECK_LABELS[$check->status] ?? $check->status }}" />
                                    <span>{{ $check->message_fa }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

            </div>
        @endif

    </div>
</div>
