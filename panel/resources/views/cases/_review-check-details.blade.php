{{--
    «چه دیدیم» — جزئیات یک ردیف اعتبارسنجی.
    ورودی: $details (همان ستون validation_results.details)

    پیام فارسی هر بررسی می‌گوید «نتیجه چه شد»؛ این‌جا مقادیر واقعیِ مقایسه‌شده
    نشان داده می‌شوند تا کارشناس بتواند خودش قضاوت کند، نه اینکه حرف سامانه را
    باور کند. سه شکلِ شناخته‌شده رندر می‌شود (تطابق بین مدارک، تاریخ‌ها، فیلد
    اجباریِ خالی) و هر شکل ناشناختهٔ دیگری بی‌سروصدا نادیده گرفته می‌شود تا
    افزودن قاعدهٔ تازه به DocumentValidator این صفحه را نشکند.
--}}
@php
    use App\Support\PersianValue;

    $d = is_array($details ?? null) ? $details : [];

    $verdictMeta = [
        'match' => ['tone' => 'ok', 'label' => 'یکی است'],
        'suspect' => ['tone' => 'warn', 'label' => 'نزدیک، ولی یکی نیست'],
        'mismatch' => ['tone' => 'bad', 'label' => 'متفاوت است'],
        'valid' => ['tone' => 'ok', 'label' => 'معتبر'],
        'expired' => ['tone' => 'bad', 'label' => 'منقضی'],
        'ok' => ['tone' => 'ok', 'label' => 'درست'],
        'future' => ['tone' => 'bad', 'label' => 'در آینده — ممکن نیست'],
        'invalid' => ['tone' => 'warn', 'label' => 'ناخوانا'],
        'unread' => ['tone' => 'info', 'label' => 'خوانده نشد'],
    ];

    $sourceLabel = fn (?string $s): string => match ($s) {
        'manual' => 'اصلاح کارشناس',
        'derived' => 'محاسبه‌شده',
        default => 'OCR',
    };

    // تطابق بین مدارک: مرجع + هر چیزی که با آن مقایسه شده
    $reference = is_array($d['reference'] ?? null) ? $d['reference'] : null;
    $compared = is_array($d['compared'] ?? null) ? $d['compared'] : [];

    // تاریخ‌ها
    $dateFields = is_array($d['fields'] ?? null) ? $d['fields'] : [];

    // فیلدهای اجباریِ خالی
    $missing = is_array($d['missing'] ?? null) ? $d['missing'] : [];
@endphp

@if ($reference !== null)
    <div class="scroll-x">
        <table class="table">
            <thead>
                <tr>
                    <th>مدرک</th>
                    <th>مقدار خوانده‌شده</th>
                    <th>منبع</th>
                    <th>اطمینان</th>
                    <th>نتیجهٔ مقایسه</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="small">{{ $reference['document_label'] ?? '—' }}</td>
                    <td class="num">{{ PersianValue::toPersianDigits((string) ($reference['value'] ?? '')) ?: '—' }}</td>
                    <td class="tiny faint">{{ $sourceLabel($reference['source'] ?? null) }}</td>
                    <td><x-num :value="$reference['confidence'] ?? 0" :decimals="0" /></td>
                    <td class="tiny faint">مبنای مقایسه</td>
                </tr>
                @foreach ($compared as $item)
                    @php
                        $meta = $verdictMeta[$item['verdict'] ?? ''] ?? ['tone' => 'info', 'label' => (string) ($item['verdict'] ?? '—')];
                    @endphp
                    <tr>
                        <td class="small">{{ $item['document_label'] ?? '—' }}</td>
                        <td class="num">{{ PersianValue::toPersianDigits((string) ($item['value'] ?? '')) ?: '—' }}</td>
                        <td class="tiny faint">{{ $sourceLabel($item['source'] ?? null) }}</td>
                        <td><x-num :value="$item['confidence'] ?? 0" :decimals="0" /></td>
                        <td>
                            <x-badge :tone="$meta['tone']" :label="$meta['label']" />
                            @if (($d['match_mode'] ?? '') === 'fuzzy' && isset($item['similarity']))
                                <span class="tiny faint">شباهت <x-num :value="$item['similarity']" :decimals="0" />٪</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if ($dateFields !== [])
    <div class="scroll-x">
        <table class="table">
            <thead>
                <tr>
                    <th>تاریخ</th>
                    <th>مقدار خوانده‌شده</th>
                    <th>اطمینان</th>
                    <th>نتیجه</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($dateFields as $entry)
                    @php
                        $meta = $verdictMeta[$entry['verdict'] ?? ''] ?? ['tone' => 'info', 'label' => (string) ($entry['verdict'] ?? '—')];
                    @endphp
                    <tr>
                        <td class="small">{{ $entry['label'] ?? '—' }}</td>
                        <td class="num">{{ PersianValue::toPersianDigits((string) ($entry['value'] ?? '')) ?: '—' }}</td>
                        <td>
                            @if (($entry['confidence'] ?? null) === null)
                                <span class="faint">—</span>
                            @else
                                <x-num :value="$entry['confidence']" :decimals="0" />
                            @endif
                        </td>
                        <td>
                            <x-badge :tone="$meta['tone']" :label="$meta['label']" />
                            @if (filled($entry['reason'] ?? null))
                                <span class="tiny faint">{{ $entry['reason'] }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if (filled($d['today'] ?? null))
        <p class="tiny faint">مبنای مقایسه، تاریخ امروز است: {{ PersianValue::toPersianDigits((string) $d['today']) }}</p>
    @endif
@endif

@if ($missing !== [])
    <p class="small">
        فیلدهای اجباریِ خالی:
        {{ implode('، ', array_map(fn ($m) => '«'.($m['label'] ?? $m['key'] ?? '؟').'»', $missing)) }}
    </p>
@endif

@if (isset($d['average_confidence']) && is_numeric($d['average_confidence']))
    <p class="tiny faint">
        میانگین اطمینان خواندنِ این مدرک: <x-num :value="$d['average_confidence']" :decimals="1" /> از ۱۰۰
        @if (isset($d['extracted_rows']))
            · <x-num :value="$d['extracted_rows']" /> فیلد خوانده شد
        @endif
    </p>
@endif
