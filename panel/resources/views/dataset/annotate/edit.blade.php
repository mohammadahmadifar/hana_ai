@extends('layouts.panel')

@section('title', 'تگ‌گذاری نمونهٔ ' . $sample->id)
@section('page_title', 'تگ‌گذاری تصویری')

@section('topbar_actions')
    <a class="btn btn--ghost btn--sm" href="{{ route('dataset.annotate.index') }}">بازگشت به صف</a>
@endsection

@push('head')
<style>
    /* ------------------------------------------------------------------
       ابزار تگ‌گذاری — همهٔ رنگ‌ها از متغیرهای سامانه می‌آید تا تم روشن و
       تیره هر دو درست کار کند. هیچ انیمیشن سنگینی هم در کار نیست.
    ------------------------------------------------------------------ */
    .anno { display: grid; grid-template-columns: minmax(0, 1fr) 384px; gap: 16px; align-items: start; }
    @media (max-width: 1040px) { .anno { grid-template-columns: minmax(0, 1fr); } }

    .anno__bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .anno__bar .spacer { flex: 1; }

    .anno__stage {
        position: relative;
        overflow: auto;
        background: var(--surface-2);
        border: 1px solid var(--rule);
        border-radius: var(--r);
        max-height: 74vh;
        overscroll-behavior: contain;
    }
    .anno__canvas { position: relative; width: 100%; user-select: none; -webkit-user-select: none; }
    .anno__canvas img { display: block; width: 100%; height: auto; pointer-events: none; }
    .anno__canvas.is-arm { cursor: crosshair; }
    .anno__canvas.is-quiet .abox { display: none; }

    .anno__layer { position: absolute; inset: 0; pointer-events: none; }

    .abox {
        position: absolute;
        border: 2px solid var(--ink-faint);
        border-radius: 2px;
        opacity: .45;
        cursor: move;
        pointer-events: auto;
        touch-action: none;
    }
    .abox::after { content: ''; position: absolute; inset: 0; background: var(--ink-faint); opacity: .12; }
    .abox.is-active { border-color: var(--accent); opacity: 1; z-index: 3; }
    .abox.is-active::after { background: var(--accent); opacity: .16; }

    .abox__tag {
        position: absolute;
        bottom: 100%;
        left: 0;
        margin-bottom: 3px;
        padding: 0 5px;
        font-size: 11px;
        line-height: 1.75;
        white-space: nowrap;
        border-radius: 4px;
        background: var(--surface);
        border: 1px solid var(--rule);
        color: var(--ink-soft);
        pointer-events: none;
    }
    .abox.is-active .abox__tag { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }
    .abox--tagin .abox__tag { bottom: auto; top: 100%; margin: 3px 0 0; }

    .abox__h {
        position: absolute;
        width: 12px; height: 12px;
        background: var(--accent);
        border: 2px solid var(--surface);
        border-radius: 3px;
        display: none;
        z-index: 4;
        touch-action: none;
    }
    .abox.is-active .abox__h { display: block; }
    .abox__h[data-h="nw"] { left: -7px; top: -7px; cursor: nwse-resize; }
    .abox__h[data-h="ne"] { right: -7px; top: -7px; cursor: nesw-resize; }
    .abox__h[data-h="sw"] { left: -7px; bottom: -7px; cursor: nesw-resize; }
    .abox__h[data-h="se"] { right: -7px; bottom: -7px; cursor: nwse-resize; }

    .adraft {
        position: absolute;
        border: 2px dashed var(--accent);
        background: var(--accent-wash);
        opacity: .6;
        pointer-events: none;
        z-index: 5;
    }

    .flds { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
    .fld {
        border: 1px solid var(--rule);
        border-radius: var(--r-sm);
        background: var(--surface);
        padding: 9px 11px;
        display: flex; flex-direction: column; gap: 7px;
        cursor: pointer;
    }
    .fld:hover { border-color: var(--accent); }
    .fld.is-active { border-color: var(--accent); background: var(--accent-wash); }
    .fld__top { display: flex; align-items: center; gap: 7px; }
    .fld__top .spacer { flex: 1; }
    .fld__i {
        flex: 0 0 21px; width: 21px; height: 21px; border-radius: 50%;
        background: var(--surface-3); color: var(--ink-soft);
        font-size: 11px; display: grid; place-items: center;
    }
    .fld.is-active .fld__i { background: var(--accent); color: var(--accent-ink); }
    .fld__label { font-size: 13.5px; font-weight: 700; cursor: pointer; }
    .fld__acts { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

    .fld-foot {
        position: sticky; bottom: 8px;
        display: flex; flex-direction: column; gap: 10px;
        background: var(--surface);
        border: 1px solid var(--rule);
        border-radius: var(--r);
        padding: 12px;
        box-shadow: var(--shadow);
    }

    .keys { display: flex; gap: 6px; flex-wrap: wrap; }
    .kbd {
        font-size: 11px; padding: 1px 6px; border-radius: 4px;
        border: 1px solid var(--rule); background: var(--surface-2);
        color: var(--ink-soft); font-family: "JetBrains Mono", monospace; direction: ltr;
    }

    /* کلاس alert مقدار display: flex دارد و بر ویژگی hidden غلبه می‌کند؛
       این قاعده هشدار را تا وقتی لازم نشده واقعاً پنهان نگه می‌دارد. */
    #annoClipWarn[hidden] { display: none; }

    @media (prefers-reduced-motion: reduce) {
        .abox, .fld, .btn, .bar__fill { transition: none !important; }
    }
</style>
@endpush

@section('content')
    @php
        $fa = fn ($n) => strtr((string) $n, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
        $typeHints = [
            'national_id' => 'کد ملی — ۱۰ رقم',
            'jalali_date' => 'تاریخ شمسی — ۱۴۰۴/۰۵/۳۰',
            'digits' => 'فقط رقم',
            'plate' => 'شمارهٔ پلاک',
            'vin' => 'شمارهٔ شاسی (لاتین)',
            'text' => 'متن فارسی',
        ];
        $sourceLabel = $sample->source === 'uploaded' ? 'آپلودشده' : 'تولیدشده';
    @endphp

    <div class="page-head">
        <div>
            <h1>نمونهٔ #{{ $fa($sample->id) }} — {{ $sample->documentType->label_fa }}</h1>
        </div>
        <div class="page-head__actions">
            @if ($next)
                <a class="btn btn--ghost btn--sm" href="{{ route('dataset.annotate.edit', $next) }}">
                    رد کردن و رفتن به بعدی
                </a>
            @endif
        </div>
        <p class="page-head__sub">
            <span class="badge">{{ $sourceLabel }}</span>
            @if ($sample->original_name)
                <span class="badge badge--info">{{ \Illuminate\Support\Str::limit($sample->original_name, 34) }}</span>
            @endif
            @if ($sample->augmentation)
                <span class="badge badge--warn">اعوجاج: {{ $sample->augmentation }}</span>
            @endif
            @if ($sample->width)
                <span class="badge">{{ $fa($sample->width) }}×{{ $fa($sample->height) }} پیکسل</span>
            @endif
            <span class="badge {{ $sample->is_verified ? 'badge--ok' : 'badge--warn' }} badge--dot">
                {{ $sample->is_verified ? 'تاییدشده' : 'تاییدنشده' }}
            </span>
            <span class="badge badge--info">{{ $fa($remaining) }} نمونه در صف</span>
        </p>
    </div>

    @if ($imageMissing)
        <div class="alert alert--bad" role="alert">
            <span class="alert__icon" aria-hidden="true">⛔</span>
            <div class="alert__body">
                <strong>فایل تصویر این نمونه در دسترس نیست</strong>
                <span>مسیر ثبت‌شده: <span class="ltr mono">{{ $sample->disk }}:{{ $sample->path }}</span></span>
                <span>می‌توانید مقدار فیلدها را ثبت کنید، ولی کشیدن کادر ممکن نیست.</span>
            </div>
        </div>
    @endif

    @if ($orphans->isNotEmpty())
        <div class="alert alert--warn" role="alert">
            <span class="alert__icon" aria-hidden="true">⚠️</span>
            <div class="alert__body">
                <strong>برچسب‌های بی‌صاحب</strong>
                <span>
                    این کلیدها در تعریف فعلی «{{ $sample->documentType->label_fa }}» نیستند و دست‌نخورده می‌مانند:
                    <span class="ltr mono">{{ $orphans->pluck('field_key')->join('، ') }}</span>
                </span>
            </div>
        </div>
    @endif

    {{-- کادرهایی که بیرون از تصویر ذخیره شده‌اند و برای ذخیره‌شدن به لبه چسبیده‌اند --}}
    <div class="alert alert--warn" id="annoClipWarn" role="alert" hidden>
        <span class="alert__icon" aria-hidden="true">✂️</span>
        <div class="alert__body">
            <strong>کادر چند فیلد بیرون از تصویر بود</strong>
            <span>
                کادر این فیلدها به لبهٔ تصویر چسبانده شد تا قابل ذخیره باشد:
                <span id="annoClipList"></span>
            </span>
            <span>اگر اندازه‌شان درست نیست، دوباره بکشید؛ تا وقتی ذخیره نزنید چیزی در دیتابیس عوض نمی‌شود.</span>
        </div>
    </div>

    <noscript>
        <div class="alert alert--warn" role="alert">
            <span class="alert__icon" aria-hidden="true">⚠️</span>
            <div class="alert__body">
                <strong>جاوااسکریپت خاموش است</strong>
                <span>ابزار کشیدن کادر بدون جاوااسکریپت کار نمی‌کند. لطفاً آن را روشن کنید.</span>
            </div>
        </div>
    </noscript>

    <form id="annoForm" method="POST" action="{{ route('dataset.annotate.update', $sample) }}">
        @csrf
        <input type="hidden" name="payload" id="annoPayload" value="">
        <input type="hidden" name="action" id="annoAction" value="save">

        <div class="anno" id="anno">

            {{-- ستون راست: بوم تصویر --}}
            <section class="card">
                <div class="card__head">
                    <h2>بوم تصویر</h2>
                    <span class="spacer"></span>
                    <label class="check tiny" for="annoQuiet">
                        <input type="checkbox" id="annoQuiet">
                        پنهان کردن کادرها
                    </label>
                </div>

                <div class="card__body">
                    <div class="anno__bar">
                        <button class="btn btn--sm" type="button" id="annoZoomOut" aria-label="کوچک‌نمایی">−</button>
                        <span class="badge num" id="annoZoomLabel">۱۰۰٪</span>
                        <button class="btn btn--sm" type="button" id="annoZoomIn" aria-label="بزرگ‌نمایی">+</button>
                        <button class="btn btn--ghost btn--sm" type="button" id="annoZoomReset">اندازهٔ اصلی</button>
                        <span class="spacer"></span>
                        <span class="tiny faint" id="annoHint">فیلد فعال: —</span>
                    </div>

                    @if ($imageMissing)
                        <x-empty-state icon="🖼" title="تصویری برای نمایش نیست"
                                       hint="فایل نمونه روی دیسک پیدا نشد." />
                    @else
                        <div class="anno__stage" id="annoStage">
                            <div class="anno__canvas" id="annoCanvas"
                                 @if ($sample->width && $sample->height) style="aspect-ratio: {{ $sample->width }} / {{ $sample->height }};" @endif>
                                <img src="{{ $imageUrl }}" alt="تصویر نمونهٔ {{ $sample->documentType->label_fa }}" draggable="false">
                                <div class="anno__layer" id="annoBoxes"></div>
                                <div class="adraft" id="annoDraft" hidden></div>
                            </div>
                        </div>

                        <div class="keys">
                            <span class="tiny faint">میان‌برها:</span>
                            <span class="kbd">Ctrl</span><span class="tiny faint">+</span><span class="kbd">S</span>
                            <span class="tiny faint">ذخیره</span>
                            <span class="kbd">Delete</span>
                            <span class="tiny faint">حذف کادر انتخاب‌شده</span>
                            <span class="kbd">Esc</span>
                            <span class="tiny faint">لغو کشیدن</span>
                        </div>
                    @endif
                </div>
            </section>

            {{-- ستون چپ: فیلدهای همین نوع مدرک --}}
            <aside class="card">
                <div class="card__head">
                    <h2>فیلدها</h2>
                    <span class="spacer"></span>
                    <span class="badge badge--info" id="annoCounter">
                        {{ $fa($boxedCount) }} از {{ $fa($fields->count()) }} کادر
                    </span>
                </div>

                <div class="card__body">
                    @if (! empty($prefill))
                        <div class="row">
                            <button class="btn btn--sm" type="button" id="annoPrefillBtn">پر کردن از دادهٔ تولید</button>
                            <span class="tiny faint">فقط فیلدهای خالی را پر می‌کند؛ کادرها دست نمی‌خورند.</span>
                        </div>
                    @endif

                    <ol class="flds" id="annoFieldsList">
                        @foreach ($fields as $index => $field)
                            <li class="fld" data-key="{{ $field['key'] }}" tabindex="0" role="button" aria-pressed="false">
                                <div class="fld__top">
                                    <span class="fld__i num" aria-hidden="true">{{ $fa($index + 1) }}</span>
                                    <label class="fld__label" for="annoVal-{{ $field['key'] }}">
                                        {{ $field['label'] }}
                                        @if ($field['required'])
                                            <span class="label__req" title="الزامی">*</span>
                                        @endif
                                    </label>
                                    <span class="spacer"></span>
                                    <span class="badge {{ $field['bbox'] ? 'badge--ok' : 'badge--warn' }} badge--dot" data-mark>
                                        {{ $field['bbox'] ? 'کادر دارد' : 'کادر ندارد' }}
                                    </span>
                                </div>

                                <input class="input {{ $field['value_type'] === 'vin' ? 'input--ltr' : '' }}"
                                       id="annoVal-{{ $field['key'] }}"
                                       data-value-for="{{ $field['key'] }}"
                                       type="text"
                                       maxlength="500"
                                       autocomplete="off"
                                       value="{{ $field['value'] }}"
                                       placeholder="{{ $typeHints[$field['value_type']] ?? 'مقدار فیلد' }}">

                                <div class="fld__acts">
                                    <button class="btn btn--sm" type="button" data-draw>کادر بکش</button>
                                    <button class="btn btn--ghost btn--sm" type="button" data-clear
                                            @disabled(! $field['bbox'])>حذف کادر</button>
                                    <span class="spacer" style="flex:1"></span>
                                    <span class="tiny faint">{{ $typeHints[$field['value_type']] ?? $field['value_type'] }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ol>

                    @if ($fields->isEmpty())
                        <x-empty-state icon="🧩" title="این نوع مدرک هیچ فیلدی ندارد"
                                       hint="ابتدا فیلدهای نوع مدرک باید تعریف شوند." />
                    @endif
                </div>

                <div class="card__foot">
                    <div class="fld-foot">
                        {{-- فیلد پنهان تا «تیک برداشته‌شده» هم به سرور برسد --}}
                        <input type="hidden" name="verify" value="0">
                        <label class="check" for="annoVerify">
                            <input type="checkbox" id="annoVerify" name="verify" value="1"
                                   @checked(old('verify', $sample->is_verified ? '1' : '0') === '1')>
                            تایید نهایی این نمونه (از صف خارج می‌شود)
                        </label>
                        <div class="row" style="display:flex; gap:8px; flex-wrap:wrap">
                            <button class="btn btn--primary" type="button" id="annoSave">ذخیره</button>
                            <button class="btn" type="button" id="annoSaveNext" @disabled($next === null)>
                                ذخیره و بعدی
                            </button>
                        </div>
                        <span class="tiny faint">
                            @if ($next)
                                نمونهٔ بعدی صف: #{{ $fa($next->id) }}
                            @else
                                نمونهٔ دیگری در صف نمانده است.
                            @endif
                        </span>
                    </div>
                </div>
            </aside>

        </div>
    </form>

    <script type="application/json" id="annoFieldsData">@json($fields)</script>
    <script type="application/json" id="annoPrefillData">@json((object) $prefill)</script>
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    var root = document.getElementById('anno');
    if (!root) { return; }

    var form = document.getElementById('annoForm');
    var payloadInput = document.getElementById('annoPayload');
    var actionInput = document.getElementById('annoAction');
    var canvas = document.getElementById('annoCanvas');
    var stage = document.getElementById('annoStage');
    var layer = document.getElementById('annoBoxes');
    var draft = document.getElementById('annoDraft');
    var hint = document.getElementById('annoHint');
    var counter = document.getElementById('annoCounter');
    var zoomLabel = document.getElementById('annoZoomLabel');

    var FIELDS = JSON.parse(document.getElementById('annoFieldsData').textContent || '[]');
    var PREFILL = JSON.parse(document.getElementById('annoPrefillData').textContent || '{}');

    // کوچک‌ترین ضلع مجاز؛ هم‌ارز MIN_SIDE در کنترلر است.
    var MIN = 0.004;
    var TOTAL = FIELDS.length;

    var state = Object.create(null);   // key -> {label, required, bbox|null}
    var rows = Object.create(null);    // key -> <li>
    var inputs = Object.create(null);  // key -> <input>
    var boxEls = Object.create(null);  // key -> <div class="abox">
    var active = null;
    var zoom = 1;
    var drag = null;
    var dirty = false;

    /* ---------------- ابزار ---------------- */

    function fa(value) {
        return String(value).replace(/[0-9]/g, function (digit) {
            return '۰۱۲۳۴۵۶۷۸۹'.charAt(Number(digit));
        });
    }

    function clamp(value, low, high) {
        if (high < low) { return low; }
        return value < low ? low : (value > high ? high : value);
    }

    function round6(value) { return Math.round(value * 1000000) / 1000000; }

    function tidy(box) {
        var x = clamp(Number(box.x) || 0, 0, 1 - MIN);
        var y = clamp(Number(box.y) || 0, 0, 1 - MIN);
        var w = clamp(Number(box.w) || 0, MIN, 1 - x);
        var h = clamp(Number(box.h) || 0, MIN, 1 - y);
        return { x: round6(x), y: round6(y), w: round6(w), h: round6(h) };
    }

    // رواداری کمی بزرگ‌تر از خطای round6 تا گرد کردن، «تغییر» شمرده نشود.
    function sameBox(a, b) {
        if (!a || !b) { return !a && !b; }

        return ['x', 'y', 'w', 'h'].every(function (axis) {
            return Math.abs((Number(a[axis]) || 0) - (Number(b[axis]) || 0)) <= 0.000002;
        });
    }

    function percent(value) { return (value * 100).toFixed(4) + '%'; }

    function place(el, box) {
        el.style.left = percent(box.x);
        el.style.top = percent(box.y);
        el.style.width = percent(box.w);
        el.style.height = percent(box.h);
    }

    function pointAt(event) {
        var rect = canvas.getBoundingClientRect();
        return {
            x: clamp((event.clientX - rect.left) / (rect.width || 1), 0, 1),
            y: clamp((event.clientY - rect.top) / (rect.height || 1), 0, 1)
        };
    }

    function rectBetween(a, b) {
        return {
            x: Math.min(a.x, b.x),
            y: Math.min(a.y, b.y),
            w: Math.abs(b.x - a.x),
            h: Math.abs(b.y - a.y)
        };
    }

    /* ---------------- وضعیت اولیه ---------------- */

    // کادرهایی که در دیتابیس کمی بیرون از تصویر بوده‌اند اینجا به لبه
    // چسبانده می‌شوند (وگرنه سرور ذخیره را رد می‌کند)، ولی دیگر بی‌صدا
    // نیست: نامشان بالای صفحه به کاربر نشان داده می‌شود.
    var clipped = [];

    FIELDS.forEach(function (field) {
        var box = field.bbox ? tidy(field.bbox) : null;

        if (box && !sameBox(field.bbox, box)) { clipped.push(field.label); }

        state[field.key] = {
            label: field.label,
            required: !!field.required,
            bbox: box
        };
    });

    Array.prototype.forEach.call(root.querySelectorAll('.fld'), function (li) {
        rows[li.getAttribute('data-key')] = li;
    });

    Array.prototype.forEach.call(root.querySelectorAll('[data-value-for]'), function (input) {
        inputs[input.getAttribute('data-value-for')] = input;
    });

    /* ---------------- رسم ---------------- */

    function drawBoxes() {
        if (!layer) { return; }

        layer.textContent = '';
        boxEls = Object.create(null);

        FIELDS.forEach(function (field) {
            var box = state[field.key].bbox;
            if (!box) { return; }

            var el = document.createElement('div');
            el.className = 'abox' + (field.key === active ? ' is-active' : '') + (box.y < 0.06 ? ' abox--tagin' : '');
            el.setAttribute('data-key', field.key);
            el.setAttribute('title', field.label);

            var tag = document.createElement('span');
            tag.className = 'abox__tag';
            tag.textContent = field.label;
            el.appendChild(tag);

            ['nw', 'ne', 'sw', 'se'].forEach(function (corner) {
                var grip = document.createElement('span');
                grip.className = 'abox__h';
                grip.setAttribute('data-h', corner);
                el.appendChild(grip);
            });

            place(el, box);
            layer.appendChild(el);
            boxEls[field.key] = el;
        });
    }

    function syncRow(key) {
        var li = rows[key];
        if (!li) { return; }

        var has = !!state[key].bbox;
        var mark = li.querySelector('[data-mark]');

        if (mark) {
            mark.textContent = has ? 'کادر دارد' : 'کادر ندارد';
            mark.className = 'badge badge--dot ' + (has ? 'badge--ok' : 'badge--warn');
        }

        var clear = li.querySelector('[data-clear]');
        if (clear) { clear.disabled = !has; }
    }

    function syncCounter() {
        if (!counter) { return; }

        var boxed = 0;
        FIELDS.forEach(function (field) { if (state[field.key].bbox) { boxed++; } });
        counter.textContent = fa(boxed) + ' از ' + fa(TOTAL) + ' کادر';
    }

    function setActive(key, scrollRow) {
        active = key;

        Object.keys(rows).forEach(function (rowKey) {
            var on = rowKey === key;
            rows[rowKey].classList.toggle('is-active', on);
            rows[rowKey].setAttribute('aria-pressed', on ? 'true' : 'false');
        });

        if (canvas) { canvas.classList.toggle('is-arm', !!key); }

        if (hint) {
            hint.textContent = key
                ? 'فیلد فعال: ' + state[key].label + (state[key].bbox ? ' — کادر دارد' : ' — روی تصویر کادر بکشید')
                : 'فیلد فعال: —';
        }

        drawBoxes();

        if (scrollRow && rows[key]) {
            rows[key].scrollIntoView({ block: 'nearest' });
        }
    }

    function markDirty() { dirty = true; }

    function setBox(key, box) {
        state[key].bbox = box ? tidy(box) : null;
        syncRow(key);
        syncCounter();
        markDirty();
    }

    /* ---------------- کشیدن، جابه‌جایی، تغییر اندازه ---------------- */

    function resized(origin, corner, dx, dy) {
        var x0 = origin.x;
        var y0 = origin.y;
        var x1 = origin.x + origin.w;
        var y1 = origin.y + origin.h;

        if (corner.indexOf('w') !== -1) { x0 += dx; }
        if (corner.indexOf('e') !== -1) { x1 += dx; }
        if (corner.indexOf('n') !== -1) { y0 += dy; }
        if (corner.indexOf('s') !== -1) { y1 += dy; }

        return rectBetween({ x: x0, y: y0 }, { x: x1, y: y1 });
    }

    function onPointerDown(event) {
        if (event.button !== undefined && event.button !== 0) { return; }
        if (canvas.classList.contains('is-quiet')) { return; }

        var target = event.target;
        var grip = target.closest ? target.closest('.abox__h') : null;
        var box = target.closest ? target.closest('.abox') : null;
        var point = pointAt(event);

        if (grip && box) {
            var gripKey = box.getAttribute('data-key');
            setActive(gripKey, true);
            drag = {
                mode: 'resize',
                key: gripKey,
                corner: grip.getAttribute('data-h'),
                origin: state[gripKey].bbox,
                start: point
            };
        } else if (box) {
            var moveKey = box.getAttribute('data-key');
            setActive(moveKey, true);
            drag = { mode: 'move', key: moveKey, origin: state[moveKey].bbox, start: point };
        } else {
            if (!active) {
                if (hint) { hint.textContent = 'اول یک فیلد را از ستون کناری انتخاب کنید.'; }
                return;
            }
            drag = { mode: 'draw', key: active, start: point };
            place(draft, { x: point.x, y: point.y, w: 0, h: 0 });
            draft.hidden = false;
        }

        try { canvas.setPointerCapture(event.pointerId); } catch (error) { /* پشتیبانی نشد */ }
        event.preventDefault();
    }

    function onPointerMove(event) {
        if (!drag) { return; }

        var point = pointAt(event);

        if (drag.mode === 'draw') {
            place(draft, rectBetween(drag.start, point));
        } else if (drag.mode === 'move') {
            var origin = drag.origin;
            var nx = clamp(origin.x + (point.x - drag.start.x), 0, 1 - origin.w);
            var ny = clamp(origin.y + (point.y - drag.start.y), 0, 1 - origin.h);
            var moved = { x: nx, y: ny, w: origin.w, h: origin.h };
            state[drag.key].bbox = moved;
            if (boxEls[drag.key]) { place(boxEls[drag.key], moved); }
        } else if (drag.mode === 'resize') {
            var next = resized(drag.origin, drag.corner, point.x - drag.start.x, point.y - drag.start.y);
            next = tidy(next);
            state[drag.key].bbox = next;
            if (boxEls[drag.key]) { place(boxEls[drag.key], next); }
        }

        event.preventDefault();
    }

    function finishDrag(event, cancelled) {
        if (!drag) { return; }

        var job = drag;
        drag = null;
        draft.hidden = true;

        try { canvas.releasePointerCapture(event.pointerId); } catch (error) { /* بی‌اهمیت */ }

        if (cancelled) {
            if (job.mode !== 'draw') { state[job.key].bbox = job.origin; }
            drawBoxes();
            return;
        }

        if (job.mode === 'draw') {
            var rect = rectBetween(job.start, pointAt(event));

            if (rect.w < MIN || rect.h < MIN) {
                // کلیک ساده روی بوم: چیزی ساخته نمی‌شود.
                drawBoxes();
                return;
            }

            setBox(job.key, rect);
        } else {
            setBox(job.key, state[job.key].bbox);
        }

        drawBoxes();
        setActive(job.key, false);
    }

    if (canvas) {
        canvas.addEventListener('pointerdown', onPointerDown);
        canvas.addEventListener('pointermove', onPointerMove);
        canvas.addEventListener('pointerup', function (event) { finishDrag(event, false); });
        canvas.addEventListener('pointercancel', function (event) { finishDrag(event, true); });
        canvas.addEventListener('dragstart', function (event) { event.preventDefault(); });
    }

    /* ---------------- ستون فیلدها ---------------- */

    Object.keys(rows).forEach(function (key) {
        var li = rows[key];

        li.addEventListener('click', function () { setActive(key, false); });

        li.addEventListener('keydown', function (event) {
            if (event.target === li && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                setActive(key, false);
            }
        });

        var input = inputs[key];
        if (input) {
            input.addEventListener('focus', function () { setActive(key, false); });
            input.addEventListener('input', markDirty);
        }

        var drawBtn = li.querySelector('[data-draw]');
        if (drawBtn) {
            drawBtn.addEventListener('click', function (event) {
                event.stopPropagation();
                setActive(key, false);
                if (stage) { stage.scrollIntoView({ block: 'nearest' }); }
                if (hint) { hint.textContent = 'روی تصویر برای «' + state[key].label + '» کادر بکشید.'; }
            });
        }

        var clearBtn = li.querySelector('[data-clear]');
        if (clearBtn) {
            clearBtn.addEventListener('click', function (event) {
                event.stopPropagation();
                setBox(key, null);
                setActive(key, false);
            });
        }
    });

    /* ---------------- بزرگ‌نمایی ---------------- */

    function setZoom(value) {
        if (!canvas || !stage) { return; }

        var centerX = (stage.scrollLeft + stage.clientWidth / 2) / (canvas.offsetWidth || 1);
        var centerY = (stage.scrollTop + stage.clientHeight / 2) / (canvas.offsetHeight || 1);

        zoom = clamp(value, 1, 3);
        canvas.style.width = (zoom * 100) + '%';

        stage.scrollLeft = centerX * canvas.offsetWidth - stage.clientWidth / 2;
        stage.scrollTop = centerY * canvas.offsetHeight - stage.clientHeight / 2;

        if (zoomLabel) { zoomLabel.textContent = fa(Math.round(zoom * 100)) + '٪'; }
    }

    var zoomIn = document.getElementById('annoZoomIn');
    var zoomOut = document.getElementById('annoZoomOut');
    var zoomReset = document.getElementById('annoZoomReset');
    var quiet = document.getElementById('annoQuiet');

    if (zoomIn) { zoomIn.addEventListener('click', function () { setZoom(zoom + 0.25); }); }
    if (zoomOut) { zoomOut.addEventListener('click', function () { setZoom(zoom - 0.25); }); }
    if (zoomReset) { zoomReset.addEventListener('click', function () { setZoom(1); }); }

    if (quiet && canvas) {
        quiet.addEventListener('change', function () {
            canvas.classList.toggle('is-quiet', quiet.checked);
        });
    }

    /* ---------------- پر کردن از دادهٔ تولید ---------------- */

    var prefillBtn = document.getElementById('annoPrefillBtn');

    if (prefillBtn) {
        prefillBtn.addEventListener('click', function () {
            var filled = 0;

            Object.keys(PREFILL).forEach(function (key) {
                var input = inputs[key];
                if (!input || input.value.trim() !== '') { return; }
                input.value = PREFILL[key];
                filled++;
            });

            if (filled > 0) { markDirty(); }

            if (hint) {
                hint.textContent = filled > 0
                    ? fa(filled) + ' فیلد از دادهٔ تولید پر شد.'
                    : 'فیلد خالی‌ای برای پر کردن نبود.';
            }
        });
    }

    /* ---------------- ذخیره ---------------- */

    function collect() {
        return FIELDS.map(function (field) {
            var input = inputs[field.key];
            var box = state[field.key].bbox;

            return {
                field_key: field.key,
                value: input ? input.value.trim() : '',
                bbox: box ? { x: box.x, y: box.y, w: box.w, h: box.h } : null
            };
        });
    }

    function submit(action) {
        payloadInput.value = JSON.stringify(collect());
        actionInput.value = action;
        dirty = false;
        form.submit();
    }

    var saveBtn = document.getElementById('annoSave');
    var nextBtn = document.getElementById('annoSaveNext');

    if (saveBtn) { saveBtn.addEventListener('click', function () { submit('save'); }); }
    if (nextBtn) { nextBtn.addEventListener('click', function () { submit('next'); }); }

    var verify = document.getElementById('annoVerify');
    if (verify) { verify.addEventListener('change', markDirty); }

    /* ---------------- میان‌برهای صفحه‌کلید ---------------- */

    document.addEventListener('keydown', function (event) {
        if ((event.ctrlKey || event.metaKey) && (event.key === 's' || event.key === 'S')) {
            event.preventDefault();
            submit('save');
            return;
        }

        if (event.key === 'Escape' && drag) {
            finishDrag({ pointerId: -1 }, true);
            return;
        }

        var target = event.target || {};
        var tag = (target.tagName || '').toLowerCase();
        var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || target.isContentEditable;

        if (!typing && (event.key === 'Delete' || event.key === 'Backspace')) {
            if (active && state[active].bbox) {
                event.preventDefault();
                setBox(active, null);
                setActive(active, false);
            }
        }
    });

    window.addEventListener('beforeunload', function (event) {
        if (!dirty) { return; }
        event.preventDefault();
        event.returnValue = '';
    });

    /* ---------------- راه‌اندازی ---------------- */

    var firstEmpty = null;

    FIELDS.forEach(function (field) {
        if (firstEmpty === null && !state[field.key].bbox) { firstEmpty = field.key; }
        syncRow(field.key);
    });

    syncCounter();
    setActive(firstEmpty || (FIELDS.length ? FIELDS[0].key : null), false);
    setZoom(1);

    var clipWarn = document.getElementById('annoClipWarn');
    var clipList = document.getElementById('annoClipList');

    if (clipped.length && clipWarn && clipList) {
        clipList.textContent = clipped.join('\u060C ');
        clipWarn.hidden = false;
    }
})();
</script>
@endpush
