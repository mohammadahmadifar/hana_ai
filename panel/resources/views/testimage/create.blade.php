@extends('layouts.panel')

@section('title', 'ساخت تصویر تستی')
@section('page_title', 'ساخت تصویر تستی')

@section('topbar_actions')
    <a href="{{ route('testimage.index') }}" class="btn btn--ghost btn--sm">🗃 تصاویر من</a>
@endsection

@php
    /*
        راهنمای هر نوع مقدار — هم متن کمکی زیر ورودی، هم صفحه‌کلید موبایل.
        منبع value_type: جدول document_type_fields.
    */
    $typeMeta = [
        'national_id' => [
            'hint' => 'ده رقم با رقم کنترل معتبر. ارقام انگلیسی خودتان به فارسی تبدیل می‌شود.',
            'placeholder' => '۵۹۴۶۰۷۹۶۳۸',
            'ltr' => false,
            'mode' => 'numeric',
        ],
        'jalali_date' => [
            'hint' => 'به شکل سال/ماه/روز شمسی، مثل ۱۳۷۵/۰۴/۲۱.',
            'placeholder' => '۱۳۷۵/۰۴/۲۱',
            'ltr' => false,
            'mode' => 'numeric',
        ],
        'digits' => [
            'hint' => 'فقط رقم، بدون حرف و علامت.',
            'placeholder' => '۰۰۸۸۶۰۴۷۴۹',
            'ltr' => false,
            'mode' => 'numeric',
        ],
        'vin' => [
            'hint' => 'هفده نویسهٔ لاتین یا رقم. شمارهٔ شاسی روی کارت واقعی هم لاتین چاپ می‌شود، پس فارسی نمی‌شود.',
            'placeholder' => 'NAS123456M7654321',
            'ltr' => true,
            'mode' => 'text',
        ],
        'plate' => [
            'hint' => 'دو رقم، سه رقم، حرف، دو رقم — همان ترتیبی که روی کارت چاپ می‌شود.',
            'placeholder' => '۸۸ ۵۱۱ و ۳۵',
            'ltr' => false,
            'mode' => 'text',
        ],
        'text' => [
            'hint' => null,
            'placeholder' => null,
            'ltr' => false,
            'mode' => 'text',
        ],
    ];
@endphp

@section('content')

    @if ($types->isEmpty())
        <div class="card">
            <div class="card__body">
                <x-empty-state
                    icon="🖼"
                    title="هیچ نوع مدرک قابل تولیدی تعریف نشده"
                    hint="برای ساخت تصویر تستی باید دست‌کم یک نوع مدرک با قالب تصویری فعال باشد." />
            </div>
        </div>
    @else

        @if ($from)
            <div class="alert alert--info" role="status">
                <span class="alert__icon" aria-hidden="true">🔁</span>
                <div class="alert__body">
                    <strong>دادهٔ تصویر شمارهٔ {{ \App\Support\PersianValue::toPersianDigits((string) $from->id) }} پیش‌پر شد.</strong>
                    <span>فقط اعوجاج‌ها را تغییر بدهید تا ببینید OCR از کجا می‌شکند.</span>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('testimage.store') }}" id="ti-form" class="stack">
            @csrf

            {{-- ============================ داده ============================ --}}
            <div class="card">
                <div class="card__head">
                    <h2>۱ — دادهٔ مدرک</h2>
                    <div class="spacer"></div>
                    <button type="button" class="btn btn--ghost btn--sm" id="ti-random">🎲 تصادفی پر کن</button>
                </div>

                <div class="card__body">

                    <div class="field" style="max-width: 380px;">
                        <label class="label" for="ti-type">
                            نوع مدرک
                            <span class="label__req">*</span>
                        </label>
                        <select name="document_type_id" id="ti-type"
                                class="select @error('document_type_id') is-invalid @enderror">
                            @foreach ($types as $type)
                                <option value="{{ $type->id }}"
                                        data-key="{{ $type->key }}"
                                        @selected($state['selected'] === $type->id)>{{ $type->label_fa }}</option>
                            @endforeach
                        </select>
                        @error('document_type_id')
                            <span class="error">{{ $message }}</span>
                        @enderror
                        <span class="hint">با تغییر نوع مدرک، فیلدهای همان مدرک نشان داده می‌شوند. مقدارهایی که در مدرک دیگر نوشته‌اید پاک نمی‌شود.</span>
                    </div>

                    @foreach ($types as $type)
                        <div data-group="{{ $type->key }}" data-id="{{ $type->id }}"
                             @class(['ti-group', 'hidden' => $state['selected'] !== $type->id])>

                            <div class="formgrid">
                                @foreach ($type->fields as $field)
                                    @php
                                        $meta = $typeMeta[$field->value_type] ?? $typeMeta['text'];
                                        $inputName = "fields[{$type->key}][{$field->key}]";
                                        $errorKey = "fields.{$type->key}.{$field->key}";
                                        $inputId = 'f_'.$type->key.'_'.$field->key;
                                    @endphp

                                    <div class="field">
                                        <label class="label" for="{{ $inputId }}">
                                            {{ $field->label_fa }}
                                            @if ($field->is_required)
                                                <span class="label__req" title="الزامی">*</span>
                                            @endif
                                            @if ($field->is_cross_checked)
                                                <x-badge tone="info" label="تطبیق بین مدارک" class="tiny" />
                                            @endif
                                        </label>

                                        <input type="text"
                                               id="{{ $inputId }}"
                                               name="{{ $inputName }}"
                                               value="{{ $state['values'][$type->key][$field->key] ?? '' }}"
                                               data-field="{{ $field->key }}"
                                               data-value-type="{{ $field->value_type }}"
                                               inputmode="{{ $meta['mode'] }}"
                                               autocomplete="off"
                                               @if ($meta['placeholder']) placeholder="{{ $meta['placeholder'] }}" @endif
                                               class="input @if ($meta['ltr']) input--ltr @endif @error($errorKey) is-invalid @enderror">

                                        @error($errorKey)
                                            <span class="error">{{ $message }}</span>
                                        @enderror

                                        @if ($meta['hint'])
                                            <span class="hint">{{ $meta['hint'] }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    @error('engine')
                        <div class="alert alert--bad" role="alert">
                            <span class="alert__icon" aria-hidden="true">⛔</span>
                            <div class="alert__body"><span>{{ $message }}</span></div>
                        </div>
                    @enderror

                </div>
            </div>

            {{-- ========================= اعوجاج (۶۲۸) ========================= --}}
            <div class="card">
                <div class="card__head">
                    <h2>۲ — اعوجاج کنترل‌شده</h2>
                    <div class="spacer"></div>
                    <button type="button" class="btn btn--ghost btn--sm" id="ti-clean">✨ بدون اعوجاج</button>
                </div>

                <div class="card__body">

                    <div class="alert alert--info" role="note">
                        <span class="alert__icon" aria-hidden="true">🎯</span>
                        <div class="alert__body">
                            <strong>چرا این بخش وجود دارد؟</strong>
                            <span>برای پیدا کردن نقطه شکست OCR باید بشود یک خرابی مشخص را عمداً زیاد کرد.</span>
                            <span>یک اعوجاج را روشن کنید، تصویر بسازید، OCR بگیرید، بعد شدتش را کمی بالا ببرید و دوباره امتحان کنید. جایی که خواندن خراب می‌شود، همان آستانهٔ تحمل موتور است.</span>
                        </div>
                    </div>

                    <div class="grid grid--2">
                        @foreach ($augmentations as $name => $spec)
                            @php
                                $on = $state['aug'][$name]['enabled'] ?? false;
                                $value = $state['aug'][$name]['value'] ?? $spec['default'];
                            @endphp

                            <div class="card ti-aug" data-aug="{{ $name }}">
                                <div class="card__body">
                                    <div class="row">
                                        <label class="check">
                                            <input type="hidden" name="aug[{{ $name }}][enabled]" value="0">
                                            <input type="checkbox"
                                                   name="aug[{{ $name }}][enabled]"
                                                   value="1"
                                                   data-aug-toggle="{{ $name }}"
                                                   @checked($on)>
                                            <span class="strong">{{ $spec['icon'] }} {{ $spec['label'] }}</span>
                                        </label>
                                        <div class="spacer"></div>
                                        <output class="badge num nowrap"
                                                id="out_{{ $name }}"
                                                for="rng_{{ $name }}">—</output>
                                    </div>

                                    <input type="range"
                                           class="range"
                                           id="rng_{{ $name }}"
                                           name="aug[{{ $name }}][value]"
                                           min="{{ $spec['min'] }}"
                                           max="{{ $spec['max'] }}"
                                           step="{{ $spec['step'] }}"
                                           value="{{ $value }}"
                                           data-decimals="{{ $spec['decimals'] }}"
                                           data-unit="{{ $spec['unit'] }}"
                                           data-aug-range="{{ $name }}">

                                    <div class="row tiny faint">
                                        <span class="num">{{ \App\Support\PersianValue::decimal($spec['min'], $spec['decimals']) }}</span>
                                        <div class="spacer"></div>
                                        <span class="num">{{ \App\Support\PersianValue::decimal($spec['max'], $spec['decimals']) }}</span>
                                    </div>

                                    <p class="hint">{{ $spec['why'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <p class="hint">
                        اعوجاج‌ها به همین ترتیب روی هم اعمال می‌شوند: چرخش، روشنایی، تاری، نویز، سایه.
                        تصویر تمیز همیشه جداگانه ذخیره می‌شود تا بتوانید دو نسخه را کنار هم ببینید.
                    </p>

                </div>

                <div class="card__foot">
                    <button type="submit" class="btn btn--primary">🖼 بساز</button>
                    <span class="hint">ساخت تصویر چند صدم ثانیه طول می‌کشد؛ بعد از آن در صفحهٔ نتیجه دکمهٔ «OCR بگیر» را بزنید.</span>
                </div>
            </div>

        </form>
    @endif

@endsection

@push('scripts')
<script>
(function () {
    var form = document.getElementById('ti-form');
    if (!form) { return; }

    var FA = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];

    function fa(text) {
        return String(text).replace(/[0-9]/g, function (d) { return FA[+d]; });
    }

    // ---------- نمایش گروه فیلدهای نوع مدرک انتخاب‌شده ----------
    var select = document.getElementById('ti-type');
    var groups = Array.prototype.slice.call(form.querySelectorAll('.ti-group'));

    function activeGroup() {
        var id = select.value;
        for (var i = 0; i < groups.length; i++) {
            if (groups[i].dataset.id === id) { return groups[i]; }
        }
        return null;
    }

    function syncGroups() {
        var id = select.value;
        groups.forEach(function (group) {
            group.classList.toggle('hidden', group.dataset.id !== id);
        });
    }

    select.addEventListener('change', syncGroups);
    syncGroups();

    // ---------- اسلایدرهای اعوجاج ----------
    function paintRange(range) {
        var out = document.getElementById('out_' + range.dataset.augRange);
        if (!out) { return; }

        var toggle = form.querySelector('[data-aug-toggle="' + range.dataset.augRange + '"]');
        var on = toggle ? toggle.checked : false;

        var decimals = parseInt(range.dataset.decimals, 10) || 0;
        var number = parseFloat(range.value);
        var text = decimals > 0 ? number.toFixed(decimals) : String(Math.round(number));

        out.textContent = on
            ? fa(text).replace('.', '\u066B') + ' ' + range.dataset.unit
            : 'خاموش';

        out.classList.toggle('badge--info', on);
        out.classList.toggle('faint', !on);

        var card = range.closest('.ti-aug');
        if (card) { card.classList.toggle('is-on', on); }
    }

    var ranges = Array.prototype.slice.call(form.querySelectorAll('[data-aug-range]'));

    ranges.forEach(function (range) {
        // جابه‌جا کردن اسلایدرِ یک اعوجاج خاموش، خودش آن را روشن می‌کند
        range.addEventListener('input', function () {
            var toggle = form.querySelector('[data-aug-toggle="' + range.dataset.augRange + '"]');
            if (toggle && !toggle.checked) { toggle.checked = true; }
            paintRange(range);
        });
    });

    form.querySelectorAll('[data-aug-toggle]').forEach(function (box) {
        box.addEventListener('change', function () {
            var range = document.getElementById('rng_' + box.dataset.augToggle);
            if (range) { paintRange(range); }
        });
    });

    ranges.forEach(paintRange);

    // ---------- دکمهٔ «بدون اعوجاج» ----------
    var cleanButton = document.getElementById('ti-clean');

    if (cleanButton) {
        cleanButton.addEventListener('click', function () {
            form.querySelectorAll('[data-aug-toggle]').forEach(function (box) {
                box.checked = false;
                box.dispatchEvent(new Event('change'));
            });
        });
    }

    // ---------- پرکردن تصادفی ----------
    var randomButton = document.getElementById('ti-random');
    var tokenTag = document.querySelector('meta[name="csrf-token"]');

    if (randomButton && tokenTag) {
        randomButton.addEventListener('click', function () {
            var group = activeGroup();
            if (!group) { return; }

            var original = randomButton.textContent;
            randomButton.disabled = true;
            randomButton.textContent = 'در حال ساخت…';

            fetch({!! json_encode(route('testimage.random')) !!}, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': tokenTag.getAttribute('content'),
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    window.alert((data && data.message) || 'ساخت دادهٔ تصادفی ممکن نشد.');
                    return;
                }

                group.querySelectorAll('input[data-field]').forEach(function (input) {
                    var value = data.person[input.dataset.field];
                    if (value !== undefined && value !== null && value !== '') {
                        input.value = value;
                        input.classList.remove('is-invalid');
                    }
                });
            })
            .catch(function () {
                window.alert('ارتباط با سرور برقرار نشد.');
            })
            .then(function () {
                randomButton.disabled = false;
                randomButton.textContent = original;
            });
        });
    }
})();
</script>
@endpush
