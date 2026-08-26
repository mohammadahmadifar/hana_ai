@extends('layouts.panel')

@section('title', 'تنظیمات امتیازدهی')
@section('page_title', 'تنظیمات امتیازدهی')

@php
    // مقدارِ نمایشی: اگر فرم خطا داده، همان چیزی که کاربر نوشته بود برمی‌گردد.
    $shown = fn (string $key, $fallback) => old($key, $fallback);

    $crossVeto = (bool) old('cross_fail_rejects', $thresholds['cross_fail_rejects']);
    $unreadHold = (bool) old('unread_required_holds', $thresholds['unread_required_holds'] ?? true);
    $expiredVeto = (bool) old('expired_rejects', $thresholds['expired_rejects'] ?? true);
    $approveNow = (float) $shown('approve_at', $thresholds['approve_at']);
    $rejectNow = (float) $shown('reject_below', $thresholds['reject_below']);
@endphp

@push('head')
    <style>
        /* خط‌کش تصمیم: سه بازهٔ رد / بررسی / تایید روی محور ۰ تا ۱۰۰ */
        .ruler { display: flex; height: 30px; border-radius: var(--r-sm); overflow: hidden; border: 1px solid var(--rule); }
        .ruler__seg { display: flex; align-items: center; justify-content: center; font-size: 12px; white-space: nowrap; overflow: hidden; }
        .ruler__seg--bad { background: var(--bad-wash); color: var(--bad); }
        .ruler__seg--warn { background: var(--warn-wash); color: var(--warn); }
        .ruler__seg--ok { background: var(--ok-wash); color: var(--ok); }
        .sumline { display: flex; align-items: center; gap: 8px; }
        .sumline__box { min-width: 62px; text-align: center; border-radius: var(--r-sm); padding: 4px 8px; border: 1px solid var(--rule); }
        .sumline__box.is-bad { border-color: var(--bad); background: var(--bad-wash); color: var(--bad); }
        .sumline__box.is-ok { border-color: var(--ok); background: var(--ok-wash); color: var(--ok); }
    </style>
@endpush

@section('content')

    <div class="page-head">
        <div>
            <h1>تنظیمات امتیازدهی</h1>
        </div>
        <div class="page-head__actions">
            <a class="btn btn--ghost" href="{{ route('admin.users.index') }}">کاربران</a>
        </div>
        <p class="page-head__sub">
            امتیاز اطمینان هر پرونده از سه مؤلفه ساخته می‌شود و تصمیم «تایید / نیاز به بررسی / رد»
            روی همین آستانه‌ها گرفته می‌شود. این اعداد در کد نوشته نشده‌اند؛ هرچه این‌جا ذخیره کنید،
            از همان لحظه روی پرونده‌های تازه‌پردازش‌شده اعمال می‌شود.
        </p>
    </div>

    <div class="grid grid--4">
        <x-stat :value="$impact['total']" label="پرونده‌های امتیازخورده" note="پایهٔ محاسبهٔ اثر زیر" />
        <x-stat :value="$impact['approved']" label="در بازهٔ تایید" tone="ok"
                note="امتیاز ≥ آستانهٔ تایید فعلی" />
        <x-stat :value="$impact['needs_review']" label="در بازهٔ بررسی" tone="warn"
                note="بین دو آستانه" />
        <x-stat :value="$impact['rejected']" label="در بازهٔ رد" tone="bad"
                note="امتیاز < آستانهٔ رد فعلی" />
    </div>

    <form method="POST" action="{{ route('admin.settings.scoring.update') }}">
        @csrf
        @method('PUT')

        <div class="card">
            <div class="card__head">
                <h2>وزن سه مؤلفهٔ امتیاز</h2>
                <span class="muted small">جمع سه وزن باید دقیقاً ۱۰۰ باشد</span>
            </div>
            <div class="card__body">

                <div class="formgrid">
                    @foreach ($labels as $key => $label)
                        <div class="field">
                            <label class="label" for="w-{{ $key }}">
                                {{ $label }} <span class="label__req">*</span>
                            </label>
                            <input class="input input--ltr js-weight @error('weights.'.$key) is-invalid @enderror"
                                   id="w-{{ $key }}" type="number" name="weights[{{ $key }}]"
                                   dir="ltr" min="0" max="100" step="any" required
                                   value="{{ $shown('weights.'.$key, $weights[$key] ?? 0) }}">
                            <span class="hint">
                                @switch($key)
                                    @case('ocr_quality')
                                        میانگین اطمینان OCR روی فیلدهای استخراج‌شدهٔ پرونده.
                                        @break
                                    @case('validation')
                                        سلامت بررسی‌های فایل، تک‌مدرکی و تطابق بین مدارک: از ۱۰۰ منهای جریمهٔ ایرادها.
                                        @break
                                    @default
                                        آمدن همهٔ مدارک لازمِ آن نوع خدمت و خوانده‌شدن فیلدهای اجباری‌شان.
                                @endswitch
                                (پیش‌فرض: <x-num :value="$defaultWeights[$key] ?? 0" />)
                            </span>
                            @error('weights.'.$key)
                                <span class="error">{{ $message }}</span>
                            @enderror
                        </div>
                    @endforeach
                </div>

                <div class="sumline">
                    <span class="muted small">جمع فعلی:</span>
                    <strong class="sumline__box" id="weight-sum">—</strong>
                    <span class="muted small">از ۱۰۰</span>
                </div>

                @error('weights')
                    <span class="error">{{ $message }}</span>
                @enderror

            </div>
        </div>

        <div class="card">
            <div class="card__head">
                <h2>آستانه‌های تصمیم و شدت ایرادها</h2>
                <span class="muted small">امتیاز بالا ← تایید، امتیاز پایین ← رد، بینشان ← بررسی انسانی</span>
            </div>
            <div class="card__body">

                <div class="formgrid">
                    <div class="field">
                        <label class="label" for="approve-at">آستانهٔ تایید <span class="label__req">*</span></label>
                        <input class="input input--ltr js-threshold @error('approve_at') is-invalid @enderror"
                               id="approve-at" type="number" name="approve_at"
                               dir="ltr" min="0" max="100" step="any" required
                               value="{{ $shown('approve_at', $thresholds['approve_at']) }}">
                        <span class="hint">
                            پرونده‌ای که امتیازش از این عدد کمتر نباشد خودکار تایید می‌شود.
                            (پیش‌فرض: <x-num :value="$defaultThresholds['approve_at']" />)
                        </span>
                        @error('approve_at')
                            <span class="error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="field">
                        <label class="label" for="reject-below">آستانهٔ رد <span class="label__req">*</span></label>
                        <input class="input input--ltr js-threshold @error('reject_below') is-invalid @enderror"
                               id="reject-below" type="number" name="reject_below"
                               dir="ltr" min="0" max="100" step="any" required
                               value="{{ $shown('reject_below', $thresholds['reject_below']) }}">
                        <span class="hint">
                            امتیاز کمتر از این عدد یعنی رد. باید از آستانهٔ تایید کوچک‌تر باشد.
                            (پیش‌فرض: <x-num :value="$defaultThresholds['reject_below']" />)
                        </span>
                        @error('reject_below')
                            <span class="error">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="ruler" id="decision-ruler" aria-hidden="true">
                    <div class="ruler__seg ruler__seg--bad" style="flex: {{ max(1, $rejectNow) }}">رد</div>
                    <div class="ruler__seg ruler__seg--warn" style="flex: {{ max(1, $approveNow - $rejectNow) }}">نیاز به بررسی</div>
                    <div class="ruler__seg ruler__seg--ok" style="flex: {{ max(1, 100 - $approveNow) }}">تایید</div>
                </div>

                <p class="hint">
                    مؤلفهٔ «نتیجهٔ بررسی‌های اعتبارسنجی» از ۱۰۰ شروع می‌شود و به ازای هر ایرادِ ثبت‌شده
                    کم می‌شود (در این سامانه فقط ایراد ثبت می‌شود، نه بررسی‌های سالم).
                </p>

                <div class="formgrid">
                    <div class="field">
                        <label class="label" for="p-failed">جریمهٔ هر ایراد جدی <span class="label__req">*</span></label>
                        <input class="input input--ltr @error('penalties.failed') is-invalid @enderror"
                               id="p-failed" type="number" name="penalties[failed]"
                               dir="ltr" min="1" max="100" step="any" required
                               value="{{ $shown('penalties.failed', $penalties['failed']) }}">
                        <span class="hint">
                            مؤلفهٔ اعتبارسنجی از ۱۰۰ شروع می‌شود و برای هر ایراد جدی این عدد کم می‌شود.
                            (پیش‌فرض: <x-num :value="$defaultPenalties['failed']" />)
                        </span>
                        @error('penalties.failed')
                            <span class="error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="field">
                        <label class="label" for="p-warning">جریمهٔ هر هشدار <span class="label__req">*</span></label>
                        <input class="input input--ltr @error('penalties.warning') is-invalid @enderror"
                               id="p-warning" type="number" name="penalties[warning]"
                               dir="ltr" min="0" max="100" step="any" required
                               value="{{ $shown('penalties.warning', $penalties['warning']) }}">
                        <span class="hint">
                            باید از جریمهٔ ایراد جدی کمتر باشد.
                            (پیش‌فرض: <x-num :value="$defaultPenalties['warning']" />)
                        </span>
                        @error('penalties.warning')
                            <span class="error">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="field">
                    <span class="label">ناهمخوانی بین مدارک</span>
                    <input type="hidden" name="cross_fail_rejects" value="0">
                    <label class="check">
                        <input type="checkbox" name="cross_fail_rejects" value="1" @checked($crossVeto)>
                        <span>پروندهٔ دارای ناهمخوانی (کد ملی یا نام یکسان نیست) مستقل از امتیاز رد شود</span>
                    </label>
                    <span class="hint">
                        ناهمخوانی بین دو مدرک با امتیاز خوبِ بقیهٔ مؤلفه‌ها جبران نمی‌شود؛ برعکس، هرچه
                        اطمینان OCR بالاتر باشد مغایرت واقعی‌تر است. با برداشتن این تیک، چنین پرونده‌ای
                        فقط از راه مؤلفهٔ اعتبارسنجی امتیاز کم می‌گیرد و به صف بررسی انسانی می‌رود.
                        @if ($impact['cross_failed'] > 0)
                            <strong>هم‌اکنون <x-num :value="$impact['cross_failed']" /> پرونده ناهمخوانی ثبت‌شده دارد.</strong>
                        @endif
                    </span>
                </div>

                <div class="field">
                    <span class="label">فیلد اجباریِ خوانده‌نشده</span>
                    <input type="hidden" name="unread_required_holds" value="0">
                    <label class="check">
                        <input type="checkbox" name="unread_required_holds" value="1" @checked($unreadHold)>
                        <span>پرونده‌ای که یک فیلد اجباری‌اش خوانده نشده، حتی با امتیاز بالا خودکار تایید نشود</span>
                    </label>
                    <span class="hint">
                        وقتی موتور مقداری را خوانده ولی شکلش معتبر نبوده (نمونهٔ روشنش شمارهٔ پلاک)، ایرادش
                        «مشکوک» است نه «رد قطعی» — چون مدرکِ متقاضی ناقص نیست، ما نتوانستیم بخوانیمش. همان
                        تخفیف بدون این تیک پرونده را از بررسی انسانی به تایید خودکار می‌بَرد، یعنی صدور مجوز
                        با فیلدی که هیچ‌کس ندیده است. با این تیک پرونده فقط به کارشناس می‌رود؛ هرگز رد نمی‌شود.
                        @if (($impact['held_unread'] ?? 0) > 0)
                            <strong>هم‌اکنون حدود <x-num :value="$impact['held_unread']" /> پرونده با امتیاز
                            بالای آستانه به همین دلیل نگه داشته می‌شوند.</strong>
                        @endif
                    </span>
                </div>

                <div class="field">
                    <span class="label">مدرک منقضی</span>
                    <input type="hidden" name="expired_rejects" value="0">
                    <label class="check">
                        <input type="checkbox" name="expired_rejects" value="1" @checked($expiredVeto)>
                        <span>پرونده‌ای که تاریخ انقضای یکی از مدارکش گذشته، مستقل از امتیاز رد شود</span>
                    </label>
                    <span class="hint">
                        انقضا یک واقعیت دوحالته است، نه سنجه‌ای درجه‌دار: مدرکی که تاریخش گذشته با کیفیت
                        خوبِ تصویر و تطابق کامل بین مدارک جبران نمی‌شود. بدون این تیک، پروندهٔ سالمی که
                        فقط گواهینامه‌اش باطل شده بالای آستانهٔ تایید می‌نشیند و خودکار تایید می‌شود.
                        تاریخی که با اطمینان پایین خوانده شده هرگز رد نمی‌شود؛ آن پرونده فقط به کارشناس
                        می‌رود تا تاریخ را از روی تصویر بخواند.
                        @if (($impact['expired'] ?? 0) > 0)
                            <strong>هم‌اکنون <x-num :value="$impact['expired']" /> پرونده مدرک منقضیِ
                            ثبت‌شده دارد.</strong>
                        @endif
                    </span>
                </div>

            </div>
            <div class="card__foot">
                <div class="row row--end">
                    <button class="btn btn--primary" type="submit">ذخیره تنظیمات</button>
                </div>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card__head">
            <h2>سابقهٔ تغییر</h2>
        </div>
        <div class="card__body">
            <div class="scroll-x">
                <table class="table">
                    <thead>
                        <tr>
                            <th>کلید تنظیم</th>
                            <th>مقدار فعلی</th>
                            <th>آخرین تغییر توسط</th>
                            <th>در تاریخ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>وزن‌های امتیاز اطمینان</td>
                            <td class="small">
                                @foreach ($labels as $key => $label)
                                    {{ $label }}: <x-num :value="$weights[$key] ?? 0" />@if (! $loop->last)،@endif
                                @endforeach
                            </td>
                            <td>{{ $weightsMeta['user'] ?? '— (مقدار اولیهٔ سامانه)' }}</td>
                            <td><x-jdate :value="$weightsMeta['at']" time /></td>
                        </tr>
                        <tr>
                            <td>جریمهٔ ایرادها</td>
                            <td class="small">
                                ایراد جدی: منهای <x-num :value="$penalties['failed']" />،
                                هشدار: منهای <x-num :value="$penalties['warning']" />
                            </td>
                            <td>{{ $penaltiesMeta['user'] ?? '— (مقدار اولیهٔ سامانه)' }}</td>
                            <td><x-jdate :value="$penaltiesMeta['at']" time /></td>
                        </tr>
                        <tr>
                            <td>آستانه‌های تصمیم</td>
                            <td class="small">
                                تایید از <x-num :value="$thresholds['approve_at']" /> به بالا،
                                رد زیر <x-num :value="$thresholds['reject_below'] " />،
                                رد خودکار ناهمخوانی: {{ $thresholds['cross_fail_rejects'] ? 'فعال' : 'غیرفعال' }}،
                                نگه‌داشتن فیلد اجباریِ خوانده‌نشده: {{ ($thresholds['unread_required_holds'] ?? true) ? 'فعال' : 'غیرفعال' }}،
                                رد خودکار مدرک منقضی: {{ ($thresholds['expired_rejects'] ?? true) ? 'فعال' : 'غیرفعال' }}
                            </td>
                            <td>{{ $thresholdsMeta['user'] ?? '— (مقدار اولیهٔ سامانه)' }}</td>
                            <td><x-jdate :value="$thresholdsMeta['at']" time /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="hint">
                هر تغییر با مقدار قبلی و جدید و نام کاربر در لاگ سامانه ثبت می‌شود
                (<span class="num" dir="ltr">storage/logs</span>).
            </p>
        </div>
    </div>

@endsection

@push('scripts')
    <script>
        (function () {
            var weights = Array.prototype.slice.call(document.querySelectorAll('.js-weight'));
            var sumBox = document.getElementById('weight-sum');
            var ruler = document.getElementById('decision-ruler');
            var approve = document.getElementById('approve-at');
            var reject = document.getElementById('reject-below');

            var fa = function (n) {
                return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }).replace('.', '٫');
            };

            function refreshSum() {
                if (!sumBox) { return; }

                var sum = weights.reduce(function (total, input) {
                    var value = parseFloat(input.value);
                    return total + (isNaN(value) ? 0 : value);
                }, 0);

                sum = Math.round(sum * 100) / 100;
                sumBox.textContent = fa(sum);
                sumBox.classList.toggle('is-ok', Math.abs(sum - 100) < 0.01);
                sumBox.classList.toggle('is-bad', Math.abs(sum - 100) >= 0.01);
            }

            function refreshRuler() {
                if (!ruler || !approve || !reject) { return; }

                var a = Math.min(100, Math.max(0, parseFloat(approve.value) || 0));
                var r = Math.min(a, Math.max(0, parseFloat(reject.value) || 0));
                var segments = ruler.children;

                segments[0].style.flex = Math.max(1, r);
                segments[1].style.flex = Math.max(1, a - r);
                segments[2].style.flex = Math.max(1, 100 - a);
            }

            weights.forEach(function (input) { input.addEventListener('input', refreshSum); });
            [approve, reject].forEach(function (input) {
                if (input) { input.addEventListener('input', refreshRuler); }
            });

            refreshSum();
            refreshRuler();
        })();
    </script>
@endpush
