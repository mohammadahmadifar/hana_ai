{{--
    تصمیم نهایی کارشناس.
    ورودی: $case، $reviewable، $canReview

    $reviewable  = پرونده از «پیش‌نویس» گذشته و چیزی برای تصمیم هست.
    $canReview   = این کاربر اجازهٔ تصمیم دارد. متقاضی همین کارت را می‌بیند
                   ولی فقط نتیجه و دلیلش را، بدون فرم.

    نکتهٔ قراردادی: تصمیم انسانی پرچم decision_is_manual را می‌گذارد و از آن
    لحظه CaseScorer دیگر تصمیم را بازنویسی نمی‌کند (امتیاز به‌روز می‌شود ولی
    تصمیم می‌ماند). چون چنین پرچمی پاک‌شدنی نیست مگر دستی، دکمهٔ «پس‌گرفتن
    تصمیم» هم این‌جاست، وگرنه یک تصمیم اشتباه تا ابد روی پرونده می‌ماند.
--}}
@php
    use App\Support\PersianValue;

    // پیش‌فرض true تا هر include قدیمی که این کلید را نمی‌فرستد رفتار قبلی را
    // داشته باشد؛ صفحهٔ نتیجه صریحاً مقدارش را می‌دهد.
    $canReview = $canReview ?? true;

    $decisionTone = [
        'approved' => 'ok',
        'rejected' => 'bad',
        'needs_review' => 'warn',
    ][$case->decision] ?? 'info';
@endphp

<div class="card" id="decision">
    <div class="card__head">
        <h2>تصمیم نهایی</h2>
        <div class="spacer"></div>
        @if (filled($case->decision))
            <x-badge :tone="$decisionTone"
                     label="{{ \App\Models\PermitCase::DECISIONS[$case->decision] ?? $case->decision }}" />
        @endif
        @if ($case->decision_is_manual)
            <x-badge tone="info" label="✎ تصمیم انسانی" />
        @elseif (filled($case->decision))
            <x-badge tone="info" label="🤖 پیشنهاد ماشین" />
        @endif
    </div>

    <div class="card__body">

        @if (filled($case->decision_reason))
            <div class="alert alert--{{ $decisionTone }}" role="status">
                <span class="alert__icon" aria-hidden="true">📝</span>
                <div class="alert__body">
                    <strong>دلیل تصمیم فعلی</strong>
                    <span>{{ $case->decision_reason }}</span>
                    @if ($case->decision_is_manual)
                        <span class="tiny faint">
                            ثبت‌شده توسط {{ $case->decidedBy?->name ?? 'کارشناس' }}
                            @if ($case->decided_at)
                                · <x-jdate :value="$case->decided_at" time />
                            @endif
                        </span>
                    @endif
                </div>
            </div>
        @else
            <p class="small muted">هنوز هیچ تصمیمی — نه ماشینی نه انسانی — روی این پرونده ثبت نشده است.</p>
        @endif

        @if (! $reviewable)
            <div class="alert alert--info" role="status">
                <span class="alert__icon" aria-hidden="true">🔒</span>
                <div class="alert__body">
                    <strong>این پرونده هنوز «پیش‌نویس» است.</strong>
                    <span>تا وقتی مدارک کامل و پرونده ثبت نشده باشد، چیزی برای تصمیم‌گیری وجود ندارد.</span>
                </div>
            </div>
        @elseif (! $canReview)
            <div class="alert alert--info" role="status">
                <span class="alert__icon" aria-hidden="true">👤</span>
                <div class="alert__body">
                    <strong>تصمیم‌گیری روی این پرونده با کارشناس بررسی است.</strong>
                    <span>
                        شما نتیجه و دلیلش را می‌بینید ولی نمی‌توانید مقدار فیلدها یا تصمیم را عوض کنید.
                        اگر مقداری اشتباه خوانده شده، مدرک واضح‌تری بارگذاری کنید.
                    </span>
                </div>
            </div>
        @else
            <form method="POST" action="{{ route('cases.decide', $case) }}"
                  data-confirm="تصمیم شما روی این پرونده ثبت شود؟ پس از آن، اجرای دوبارهٔ پردازش تصمیم شما را عوض نمی‌کند.">
                @csrf

                <div class="field">
                    <label class="label" for="decision-reason">توضیح تصمیم</label>
                    <textarea class="textarea {{ $errors->has('reason') ? 'is-invalid' : '' }}"
                              id="decision-reason"
                              name="reason"
                              maxlength="1000"
                              placeholder="مثلاً: کد ملی روی هر سه مدرک یکی است و تصویرها خوانا هستند؛ اختلافِ گزارش‌شده خطای OCR بود و اصلاح شد."
                    >{{ old('reason') }}</textarea>
                    <div class="hint">
                        برای «تایید» نوشتنش اختیاری است، ولی برای «رد» اجباری —
                        متقاضی باید بداند چه چیزی را اصلاح کند.
                    </div>
                    @if ($errors->has('reason'))
                        <div class="error">{{ $errors->first('reason') }}</div>
                    @endif
                </div>

                <div class="row row--end">
                    <span class="tiny faint">
                        پیش از تصمیم، مقدار هر فیلد را با تصویر همان مدرک بسنجید و اشتباه‌ها را اصلاح کنید؛
                        اصلاح شما امتیاز را دوباره حساب می‌کند.
                    </span>
                    <div class="spacer"></div>
                    <button type="submit" name="decision" value="rejected" class="btn btn--danger">⛔ رد پرونده</button>
                    <button type="submit" name="decision" value="approved" class="btn btn--primary">✅ تایید پرونده</button>
                </div>
            </form>

            @if ($case->decision_is_manual)
                <div class="row" style="margin-top: 14px;">
                    <span class="tiny faint">
                        با پس‌گرفتن تصمیم، پرونده دوباره بر پایهٔ امتیاز ماشین ارزیابی می‌شود
                        و ممکن است به صف بررسی برگردد.
                    </span>
                    <div class="spacer"></div>
                    <form method="POST" action="{{ route('cases.decide', $case) }}"
                          data-confirm="تصمیم دستی پس گرفته شود و قضاوت دوباره به ماشین سپرده شود؟">
                        @csrf
                        <input type="hidden" name="decision" value="reset">
                        <button type="submit" class="btn btn--ghost btn--sm">↩ پس‌گرفتن تصمیم</button>
                    </form>
                </div>
            @endif
        @endif

    </div>
</div>
