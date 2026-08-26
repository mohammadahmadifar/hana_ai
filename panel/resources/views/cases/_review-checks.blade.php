{{--
    فهرست دلایل: کدام بررسی پاس شد و کدام رد.
    ورودی: $checksByScope (حوزه => ردیف‌ها)، $checkTotals

    سه حوزه دقیقاً همان قرارداد rule_key پایپ‌لاین‌اند:
      file     اعتبارسنجی اولیهٔ فایل (فقط ایراد ثبت می‌شود)
      document بررسی تک‌مدرکی        (پاس هم ثبت می‌شود)
      cross    تطابق بین مدارک        (پاس هم ثبت می‌شود)
    پیام سبزِ «کد ملی روی هر ۳ مدرک یکی است» برای کارشناسِ ضدجعل به‌اندازهٔ
    پیام قرمز ارزش دارد، پس ردیف‌های پاس هم نمایش داده می‌شوند.
--}}
@php
    use App\Http\Controllers\Cases\CaseReviewController as Review;

    $scopeHints = [
        'file' => 'کیفیت خودِ فایل: فرمت، حجم، ابعاد، تاری و روشنایی. این‌جا فقط ایراد ثبت می‌شود؛ نبودِ ردیف یعنی فایل سالم بوده.',
        'document' => 'هر مدرک جدا: تاریخ‌ها معتبرند و فیلدهای اجباری‌اش پر شده‌اند؟',
        'cross' => 'مهم‌ترین بررسی ضدجعل: کد ملی و نام روی همهٔ مدارک یکی است؟',
    ];
@endphp

<div class="card">
    <div class="card__head">
        <h2>دلایل — چه بررسی شد و نتیجه چه بود</h2>
        <div class="spacer"></div>
        <span class="tiny faint">
            <x-num :value="$checkTotals['passed']" /> تایید ·
            <x-num :value="$checkTotals['warning']" /> مشکوک ·
            <x-num :value="$checkTotals['failed']" /> رد ·
            <x-num :value="$checkTotals['skipped']" /> بررسی‌نشده
        </span>
    </div>

    <div class="card__body">
        @if ($checksByScope === [])
            <x-empty-state
                icon="🧪"
                title="هنوز هیچ بررسی‌ای روی این پرونده اجرا نشده است"
                hint="بررسی‌ها بعد از OCR و استخراج فیلد اجرا می‌شوند. اگر پرونده تازه ثبت شده، چند لحظه دیگر همین صفحه را دوباره باز کنید." />
        @else
            <div class="stack">
                @foreach ($checksByScope as $scope => $rows)
                    <div class="stack stack--sm">
                        <div class="row">
                            <strong>{{ Review::SCOPE_LABELS[$scope] ?? $scope }}</strong>
                            <span class="tiny faint">{{ $scopeHints[$scope] ?? '' }}</span>
                        </div>

                        @foreach ($rows as $check)
                            @php
                                $tone = Review::CHECK_TONES[$check->status] ?? 'info';
                                $statusLabel = Review::CHECK_LABELS[$check->status] ?? $check->status;
                                $hasDetails = is_array($check->details) && $check->details !== [];
                            @endphp

                            <div class="alert alert--{{ $tone }}" role="{{ $check->status === 'failed' ? 'alert' : 'status' }}">
                                <span class="alert__icon" aria-hidden="true">
                                    @switch($check->status)
                                        @case('passed') ✅ @break
                                        @case('warning') ⚠️ @break
                                        @case('failed') ⛔ @break
                                        @default ➖
                                    @endswitch
                                </span>
                                <div class="alert__body">
                                    <strong>{{ $statusLabel }} — {{ $check->message_fa }}</strong>

                                    @if ($hasDetails)
                                        <details>
                                            <summary class="tiny">چه دیدیم؟</summary>
                                            @include('cases._review-check-details', ['details' => $check->details])
                                        </details>
                                    @endif

                                    <span class="tiny faint">کلید قاعده: <span dir="ltr">{{ $check->rule_key }}</span></span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
