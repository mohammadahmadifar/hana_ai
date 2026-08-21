@extends('layouts.panel')

@section('title', 'درخواست جدید')
@section('page_title', 'درخواست خدمت جدید')

@section('topbar_actions')
    <a href="{{ route('cases.index') }}" class="btn btn--ghost btn--sm">📂 پرونده‌ها</a>
@endsection

@section('content')

    <div class="stack">

        @include('cases._steps', ['current' => 1])

        @if ($services->isEmpty())
            <div class="card">
                <div class="card__body">
                    <x-empty-state
                        icon="🧾"
                        title="هیچ نوع خدمتی تعریف نشده است"
                        hint="تا وقتی دست‌کم یک نوع خدمت فعال در سامانه ثبت نشده باشد، پرونده‌ای ساخته نمی‌شود. با مدیر سامانه تماس بگیرید." />
                </div>
            </div>
        @else

            <form method="POST" action="{{ route('cases.store') }}" class="stack" id="service-form">
                @csrf

                <div class="card">
                    <div class="card__head">
                        <h2>۱. نوع خدمت را انتخاب کنید</h2>
                        <div class="spacer"></div>
                        <span class="tiny faint">مدارک لازم با انتخاب شما تغییر می‌کند</span>
                    </div>

                    <div class="card__body">
                        <p class="small muted">
                            فهرست مدارک هر خدمت از همین‌جا مشخص می‌شود؛ پیش از انتخاب، ببینید چه مدارکی باید آماده داشته باشید.
                        </p>

                        <div class="grid grid--2">
                            @foreach ($services as $service)
                                @php
                                    $isChosen = (int) old('service_type_id', $services->first()->id) === (int) $service->id;
                                @endphp

                                <label class="card" for="service-{{ $service->id }}"
                                       data-service-card
                                       style="cursor: pointer; {{ $isChosen ? 'border-color: var(--accent);' : '' }}">
                                    <div class="card__body">
                                        <div class="check">
                                            <input type="radio"
                                                   id="service-{{ $service->id }}"
                                                   name="service_type_id"
                                                   value="{{ $service->id }}"
                                                   data-service-radio
                                                   @checked($isChosen)
                                                   required>
                                            <span class="strong">{{ $service->label_fa }}</span>
                                            <div class="spacer"></div>
                                            <x-badge tone="info"
                                                     label="{{ \App\Support\PersianValue::toPersianDigits((string) $service->documentTypes->count()) }} مدرک" />
                                        </div>

                                        @if (filled($service->description_fa))
                                            <p class="small muted">{{ $service->description_fa }}</p>
                                        @endif

                                        <div class="stack stack--sm">
                                            <span class="tiny faint">مدارک لازم:</span>
                                            @foreach ($service->documentTypes as $type)
                                                <div class="row small">
                                                    <span aria-hidden="true">📄</span>
                                                    <span>{{ $type->label_fa }}</span>
                                                    @unless ($type->pivot->is_required)
                                                        <span class="tiny faint">(اختیاری)</span>
                                                    @endunless
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </label>
                            @endforeach
                        </div>

                        @error('service_type_id')
                            <span class="error">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="card">
                    <div class="card__head">
                        <h2>۲. مشخصات متقاضی (اختیاری)</h2>
                    </div>

                    <div class="card__body">
                        <p class="small muted">
                            اگر این دو مقدار را وارد کنید، هنگام بررسی با آنچه از روی مدارک خوانده می‌شود مقایسه می‌شوند.
                            خالی گذاشتنشان مانع ساخت پرونده نیست.
                        </p>

                        <div class="formgrid">
                            <div class="field">
                                <label class="label" for="applicant_name">نام و نام خانوادگی متقاضی</label>
                                <input class="input @error('applicant_name') is-invalid @enderror"
                                       id="applicant_name" type="text" name="applicant_name" maxlength="120"
                                       value="{{ old('applicant_name') }}">
                                <span class="hint">همان‌طور که روی کارت ملی نوشته شده است.</span>
                                @error('applicant_name')
                                    <span class="error">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="field">
                                <label class="label" for="applicant_national_id">کد ملی متقاضی</label>
                                <input class="input @error('applicant_national_id') is-invalid @enderror"
                                       id="applicant_national_id" type="text" name="applicant_national_id"
                                       maxlength="20" inputmode="numeric"
                                       value="{{ old('applicant_national_id') }}">
                                <span class="hint">ده رقم. ارقام انگلیسی خودشان به فارسی تبدیل می‌شوند.</span>
                                @error('applicant_national_id')
                                    <span class="error">{{ $message }}</span>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="card__foot">
                        <button type="submit" class="btn btn--primary btn--lg">➕ ساخت پرونده و رفتن به بارگذاری مدارک</button>
                        <div class="spacer"></div>
                        <span class="tiny faint">پرونده در وضعیت «پیش‌نویس» ساخته می‌شود و تا بارگذاری همهٔ مدارک ثبت نمی‌شود.</span>
                    </div>
                </div>
            </form>

        @endif

    </div>

@endsection

@push('scripts')
<script>
    /* برجسته‌کردن کارت خدمتِ انتخاب‌شده — فقط با توکن رنگ خود سامانه. */
    (function () {
        var cards = document.querySelectorAll('[data-service-card]');

        function paint() {
            cards.forEach(function (card) {
                var radio = card.querySelector('[data-service-radio]');
                card.style.borderColor = (radio && radio.checked) ? 'var(--accent)' : '';
            });
        }

        cards.forEach(function (card) {
            var radio = card.querySelector('[data-service-radio]');
            if (radio) {
                radio.addEventListener('change', paint);
            }
        });

        paint();
    })();
</script>
@endpush
