{{--
    «حالا چه کار کنم؟» — بالای صفحهٔ نتیجه، پیش از امتیاز و فهرست ایرادها.

    فهرست ایرادها می‌گوید «چه شد»؛ این کارت می‌گوید «چه کن». ترتیب آیتم‌ها را
    CaseReviewController::nextActions() تعیین می‌کند: اول کاری که خودِ بیننده
    می‌تواند انجام دهد.

    اگر کاری نمانده باشد اصلاً رندر نمی‌شود — کارت خالیِ «همه‌چیز خوب است» فقط
    صفحه را شلوغ می‌کند و جملهٔ «پرونده تایید شد» را از قبل داریم.

    بدون کلاس تازه: فقط stack / stack--sm / row که در app.css هستند (این پروژه
    build فرانت ندارد و CSS دست‌نویس است).
--}}
@if (! empty($actions))
    <div class="card">
        <div class="card__head">
            <h2>حالا چه کار کنم؟</h2>
            <div class="spacer"></div>
            <span class="tiny faint">
                @if ($canReview)
                    آنچه برای بستن این پرونده لازم است
                @else
                    کوتاه‌ترین راه برای کامل‌شدن پرونده
                @endif
            </span>
        </div>
        <div class="card__body">
            <div class="stack">
                @foreach ($actions as $action)
                    <div class="stack stack--sm">
                        <div class="row">
                            <span aria-hidden="true">{{ $action['icon'] }}</span>
                            <strong>{{ $action['title'] }}</strong>
                            @if (filled($action['document']))
                                <span class="tiny faint">— {{ $action['document'] }}</span>
                            @endif
                        </div>
                        <span class="small muted">{{ $action['detail'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
