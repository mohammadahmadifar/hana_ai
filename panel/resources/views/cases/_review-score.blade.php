{{--
    امتیاز اطمینان و «این عدد از کجا آمد».
    ورودی: $case، $components (ردیف‌های score_components)

    آستانه‌ها از تنظیمات خوانده می‌شوند نه از کد، تا اگر مدیر آستانهٔ تایید را
    عوض کرد، همین صفحه هم همان را بگوید.
--}}
@php
    use App\Services\Cases\CaseScorer;
    use App\Support\PersianValue;

    $thresholds = CaseScorer::thresholds();
    $score = $case->confidence_score === null ? null : (float) $case->confidence_score;

    $scoreTone = $score === null
        ? 'bad'
        : ($score >= $thresholds['approve_at'] ? 'ok' : ($score < $thresholds['reject_below'] ? 'bad' : 'warn'));
@endphp

<div class="card">
    <div class="card__head">
        <h2>امتیاز اطمینان و مؤلفه‌هایش</h2>
        <div class="spacer"></div>
        <span class="tiny faint">
            تایید از <x-num :value="$thresholds['approve_at']" /> به بالا ·
            رد زیر <x-num :value="$thresholds['reject_below'] " /> ·
            بین این دو: بررسی انسانی
        </span>
    </div>

    <div class="card__body">

        <div class="gauge">
            <span class="gauge__num text-{{ $scoreTone }}">
                @if ($score === null)
                    —
                @else
                    <x-num :value="$score" :decimals="1" />
                @endif
            </span>
            <div class="gauge__body">
                <x-bar :percent="$score ?? 0" :tone="$scoreTone" label="امتیاز اطمینان پرونده" />
                <span class="gauge__cap">
                    @if ($score === null)
                        امتیازی محاسبه نشده است — پردازش این پرونده هنوز اجرا نشده.
                    @else
                        از ۱۰۰ · جمعِ سهم سه مؤلفهٔ زیر
                    @endif
                </span>
            </div>
        </div>

        @if ($components->isEmpty())
            <x-empty-state
                icon="🧮"
                title="مؤلفه‌های امتیاز هنوز محاسبه نشده‌اند"
                hint="امتیازدهی پس از OCR و اعتبارسنجی اجرا می‌شود. با اصلاح هر فیلد هم دوباره محاسبه می‌شود." />
        @else
            <div class="scroll-x">
                <table class="table">
                    <thead>
                        <tr>
                            <th>مؤلفه</th>
                            <th>مقدار (از ۱۰۰)</th>
                            <th>وزن</th>
                            <th>سهم در امتیاز</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($components as $part)
                            @php
                                $value = (float) $part->value;
                                $tone = $value >= 70 ? 'ok' : ($value >= 45 ? 'warn' : 'bad');
                            @endphp
                            <tr>
                                <td>
                                    <div class="strong small">{{ $part->label_fa }}</div>
                                    @if (filled($part->note_fa))
                                        <div class="tiny faint">{{ $part->note_fa }}</div>
                                    @endif
                                </td>
                                <td style="min-width: 140px;">
                                    <div class="stack stack--sm">
                                        <span class="nowrap"><x-num :value="$value" :decimals="1" /></span>
                                        <x-bar :percent="$value" :tone="$tone" label="{{ $part->label_fa }}" />
                                    </div>
                                </td>
                                <td class="nowrap"><x-num :value="$part->weight" :decimals="0" /></td>
                                <td class="nowrap strong"><x-num :value="$part->contribution" :decimals="2" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

    </div>
</div>
