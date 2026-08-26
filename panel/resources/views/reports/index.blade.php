@extends('layouts.panel')

@section('title', 'گزارش خطاها')
@section('page_title', 'گزارش خطاها')

@php
    use App\Support\PanelMenu;
    use App\Support\PersianValue;

    $dashboardUrl = PanelMenu::url('dashboard');

    /** رنگ میانگین اطمینان: زیر آستانه «ضعیف»، نزدیک آن «هشدار»، بالاتر «خوب». */
    $confidenceTone = function (?float $value) use ($lowConfidence): ?string {
        if ($value === null) {
            return null;
        }

        return $value < $lowConfidence ? 'bad' : ($value < $lowConfidence + 20 ? 'warn' : 'ok');
    };

    // ضعیف‌ترین فیلدی که واقعاً خواندن OCR دارد — تیتر کارت بالای صفحه.
    $worstField = $weakFields->first(fn ($row) => $row->avg_confidence !== null);
@endphp

@section('content')

    <div class="page-head">
        <h1>گزارش خطاها</h1>
        <div class="page-head__actions">
            @if ($dashboardUrl !== null)
                <a class="btn btn--sm btn--ghost" href="{{ $dashboardUrl }}">بازگشت به داشبورد</a>
            @endif
        </div>
        <p class="page-head__sub">
            دو پرسش را جواب می‌دهد: پرونده‌ها بیشتر سرِ چه چیزی رد می‌شوند، و موتور
            کدام فیلد را بدتر می‌خواند. همه اعداد لحظه‌ای از دیتابیس خوانده می‌شوند و
            هر ردیف به فهرست همان مورد می‌رود.
        </p>
    </div>

    <div class="grid grid--4">
        <x-stat
            :value="$failedTotal"
            label="ایراد ثبت‌شده"
            note="ردیف‌های اعتبارسنجی با نتیجه «رد»"
            :tone="$failedTotal > 0 ? 'bad' : null" />
        <x-stat
            :value="$warningTotal"
            label="هشدار ثبت‌شده"
            note="ایرادهایی که پرونده را زمین نمی‌زنند"
            :tone="$warningTotal > 0 ? 'warn' : null" />
        <x-stat
            :value="$ocrAverage === null ? '—' : PersianValue::decimal($ocrAverage, 1).'٪'"
            label="میانگین اطمینان OCR"
            :note="$ocrAverage === null ? 'هنوز فیلدی استخراج نشده است' : 'روی همه فیلدهای خوانده‌شده موتور'"
            :tone="$confidenceTone($ocrAverage)" />
        <x-stat
            :value="$worstField ? ($fieldLabels[$worstField->field_key] ?? $worstField->field_key) : '—'"
            label="ضعیف‌ترین فیلد"
            :note="$worstField
                ? 'میانگین '.PersianValue::decimal((float) $worstField->avg_confidence, 1).'٪ روی '.PersianValue::decimal((int) $worstField->ocr_samples).' نمونه'
                : 'هنوز داده‌ای برای مقایسه نیست'"
            :tone="$worstField ? 'bad' : null" />
    </div>

    {{-- ۱) پرفراوان‌ترین دلایل رد --}}
    <div class="card">
        <div class="card__head">
            <h2>پرفراوان‌ترین دلایل رد</h2>
            <span class="spacer"></span>
            <span class="tiny faint">
                {{ $failedRules->count() ? 'پرتکرارترین ' . PersianValue::decimal($failedRules->count()) . ' قانون' : '' }}
            </span>
        </div>

        @if ($failedRules->isEmpty())
            <div class="card__body">
                <x-empty-state
                    icon="🎉"
                    title="هیچ ایرادی ثبت نشده است"
                    hint="یا هنوز پرونده‌ای اعتبارسنجی نشده، یا همه بررسی‌ها تایید شده‌اند. سامانه فقط برای ایراد ردیف می‌نویسد." />
            </div>
        @else
            <div class="scroll-x">
                <table class="table">
                    <thead>
                        <tr>
                            <th>قانون</th>
                            <th>حوزه</th>
                            <th>تعداد رد</th>
                            <th>سهم</th>
                            <th>پرونده درگیر</th>
                            <th>نمونه پیام</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($failedRules as $rule)
                            @php
                                $share = $failedTotal > 0 ? ($rule->failures * 100 / $failedTotal) : 0;
                                $ruleUrl = route('reports.rule', ['key' => $rule->rule_key]);
                            @endphp
                            <tr>
                                <td class="mono"><a href="{{ $ruleUrl }}">{{ $rule->rule_key }}</a></td>
                                <td>
                                    <x-badge dot :label="$scopeLabels[$rule->scope] ?? $rule->scope" />
                                </td>
                                <td class="nowrap"><x-num :value="$rule->failures" /></td>
                                <td style="min-width:110px">
                                    <div class="stack stack--sm">
                                        <span class="tiny faint nowrap"><x-num :value="$share" :decimals="1" />٪</span>
                                        <x-bar :percent="$share" tone="bad" label="سهم از کل ایرادها" />
                                    </div>
                                </td>
                                <td class="nowrap"><x-num :value="$rule->cases_affected" /></td>
                                <td class="small muted">{{ $rule->sample_message ?: '—' }}</td>
                                <td>
                                    <a class="btn btn--sm btn--ghost" href="{{ $ruleUrl }}">پرونده‌ها</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card__foot">
                <span class="tiny faint">
                    سامانه فقط برای «ایراد» ردیف اعتبارسنجی می‌نویسد، نه برای بررسی‌های پاس‌شده؛
                    پس این جدول دقیقاً فهرست دلایل رد است.
                </span>
            </div>
        @endif
    </div>

    {{-- ۲) ضعیف‌ترین فیلدها در OCR — مهم‌ترین خروجی این صفحه --}}
    <div class="card">
        <div class="card__head">
            <h2>ضعیف‌ترین فیلدها در OCR</h2>
            <span class="spacer"></span>
            <span class="tiny faint">میانگین اطمینان به تفکیک فیلد، ضعیف‌ترین اول</span>
        </div>

        @if ($weakFields->isEmpty())
            <div class="card__body">
                <x-empty-state
                    icon="🔎"
                    title="هنوز فیلدی از مدارک استخراج نشده است"
                    hint="پس از اولین اجرای OCR روی یک پرونده، میانگین اطمینان هر فیلد همین‌جا می‌آید." />
            </div>
        @else
            <div class="scroll-x">
                <table class="table">
                    <thead>
                        <tr>
                            <th>فیلد</th>
                            <th>میانگین اطمینان</th>
                            <th>تعداد نمونه</th>
                            <th>کمینه</th>
                            <th>زیر {{ PersianValue::decimal($lowConfidence) }}٪</th>
                            <th>اصلاح دستی</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($weakFields as $field)
                            @php
                                $average = $field->avg_confidence === null ? null : (float) $field->avg_confidence;
                                $ocrSamples = (int) $field->ocr_samples;
                                $thin = $ocrSamples > 0 && $ocrSamples < $thinSample;
                                $fieldUrl = route('reports.field', ['key' => $field->field_key]);
                            @endphp
                            <tr>
                                <td>
                                    <a href="{{ $fieldUrl }}">{{ $fieldLabels[$field->field_key] ?? $field->field_key }}</a>
                                    <div class="tiny faint mono">{{ $field->field_key }}</div>
                                </td>
                                <td style="min-width:130px">
                                    @if ($average === null)
                                        <span class="faint">—</span>
                                    @else
                                        <div class="stack stack--sm">
                                            <span class="nowrap"><x-num :value="$average" :decimals="1" />٪</span>
                                            <x-bar
                                                :percent="$average"
                                                :tone="$confidenceTone($average)"
                                                label="میانگین اطمینان" />
                                        </div>
                                    @endif
                                </td>
                                <td class="nowrap">
                                    <x-num :value="$ocrSamples" />
                                    @if ($thin)
                                        <x-badge tone="warn" dot label="نمونه کم" />
                                    @endif
                                </td>
                                <td class="nowrap">
                                    @if ($field->min_confidence === null)
                                        <span class="faint">—</span>
                                    @else
                                        <x-num :value="$field->min_confidence" :decimals="1" />٪
                                    @endif
                                </td>
                                <td class="nowrap"><x-num :value="$field->low_samples" /></td>
                                <td class="nowrap"><x-num :value="$field->corrected_samples" /></td>
                                <td>
                                    <a class="btn btn--sm btn--ghost" href="{{ $fieldUrl }}">نمونه‌ها</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card__foot">
                <span class="tiny faint">
                    میانگین فقط روی خواندن‌های موتور (source=ocr) حساب می‌شود؛ مقداری که کارشناس
                    دستی اصلاح کرده کیفیت موتور را نشان نمی‌دهد. ستون «تعداد نمونه» را نادیده نگیرید:
                    میانگین روی یکی‌دو نمونه تصمیم‌ساز نیست و با نشان «نمونه کم» علامت خورده است.
                </span>
            </div>
        @endif
    </div>

    {{-- ۳) همان سنجه، یک قدم ریزتر: کدام فیلد روی کدام قالب مدرک ضعیف است --}}
    @if ($weakFieldsByDocument->isNotEmpty())
        <div class="card">
            <div class="card__head">
                <h2>ضعیف‌ترین ترکیب مدرک × فیلد</h2>
                <span class="spacer"></span>
                <span class="tiny faint">کلید فیلد بین مدرک‌ها مشترک است؛ این جدول قالب مقصر را نشان می‌دهد</span>
            </div>
            <div class="scroll-x">
                <table class="table">
                    <thead>
                        <tr>
                            <th>نوع مدرک</th>
                            <th>فیلد</th>
                            <th>میانگین اطمینان</th>
                            <th>تعداد نمونه</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($weakFieldsByDocument as $row)
                            @php
                                $rowAverage = (float) $row->avg_confidence;
                                $rowSamples = (int) $row->samples;
                                $rowUrl = route('reports.field', ['key' => $row->field_key]);
                            @endphp
                            <tr>
                                <td>{{ $row->document_label }}</td>
                                <td>
                                    {{ $fieldLabels[$row->field_key] ?? $row->field_key }}
                                    <div class="tiny faint mono">{{ $row->field_key }}</div>
                                </td>
                                <td style="min-width:130px">
                                    <div class="stack stack--sm">
                                        <span class="nowrap"><x-num :value="$rowAverage" :decimals="1" />٪</span>
                                        <x-bar :percent="$rowAverage" :tone="$confidenceTone($rowAverage)" label="میانگین اطمینان" />
                                    </div>
                                </td>
                                <td class="nowrap">
                                    <x-num :value="$rowSamples" />
                                    @if ($rowSamples < $thinSample)
                                        <x-badge tone="warn" dot label="نمونه کم" />
                                    @endif
                                </td>
                                <td>
                                    <a class="btn btn--sm btn--ghost" href="{{ $rowUrl }}">نمونه‌ها</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

@endsection
