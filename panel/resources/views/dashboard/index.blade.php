@extends('layouts.panel')

@section('title', 'داشبورد')
@section('page_title', 'داشبورد')

@php
    use App\Support\PanelMenu;
    use App\Support\PersianValue;

    /** نگاشت وضعیت پرونده به رنگِ نشان — قرارداد مشترک صفحه‌های پرونده. */
    $statusTone = [
        'draft' => null,
        'submitted' => 'info',
        'processing' => 'info',
        'needs_review' => 'warn',
        'approved' => 'ok',
        'rejected' => 'bad',
    ];

    /**
     * سه مسیر اصلی سامانه. هر کارت فقط برای نقش‌های مجاز نمایش داده می‌شود و
     * اگر روتش هنوز ساخته نشده باشد (یا نشانی‌اش ساختنی نباشد) دکمه‌اش
     * غیرفعال و «به‌زودی» می‌شود.
     */
    $startGuide = [
        [
            'route' => 'dataset.samples.index',
            'icon' => '🗂',
            'title' => 'ساخت دیتاست آموزشی',
            'hint' => 'نمونه مدرک بسازید، تگ بزنید و خروجی آموزش OCR بگیرید.',
            'cta' => 'رفتن به دیتاست',
            'roles' => ['admin', 'data'],
        ],
        [
            'route' => 'testimage.create',
            'icon' => '🖼',
            'title' => 'ساخت تصویر تستی',
            'hint' => 'یک مدرک مصنوعی بسازید تا خروجی موتور OCR را بسنجید.',
            'cta' => 'ساخت تصویر',
            'roles' => ['admin', 'data', 'expert'],
        ],
        [
            'route' => 'cases.create',
            'icon' => '➕',
            'title' => 'ثبت درخواست خدمت',
            'hint' => 'مدارک متقاضی را بارگذاری کنید تا پیش‌اعتبارسنجی انجام شود.',
            'cta' => 'درخواست جدید',
            'roles' => ['admin', 'expert'],
        ],
    ];

    $userRole = $user->role ?? null;
    $startGuide = array_values(array_filter(
        $startGuide,
        fn ($step) => in_array($userRole, $step['roles'], true)
    ));

    // نشانی‌های پرونده فقط برای نقش‌های مجاز ساخته می‌شوند؛ وگرنه دکمه‌ای
    // نمایش می‌دهیم که کاربر با کلیک روی آن به ۴۰۳ می‌خورد.
    $casesIndexUrl = $canReviewCases ? PanelMenu::url('cases.index') : null;
    $casesCreateUrl = $canReviewCases ? PanelMenu::url('cases.create') : null;
    $annotateQueueUrl = $canManageDataset ? PanelMenu::url('dataset.annotate.index') : null;
    $reportsUrl = $canReviewCases ? PanelMenu::url('reports.index') : null;

    /**
     * فهرست پرونده‌ها با یک وضعیت مشخص. روت cases.index تسک دیگری است و ممکن
     * است هنوز ثبت نشده باشد؛ PanelMenu در آن حالت null می‌دهد و کارت به‌جای
     * لینک، یک کارت ساده می‌شود (نه خطای ۵۰۰).
     */
    $casesByStatusUrl = function (string $status) use ($canReviewCases): ?string {
        return $canReviewCases ? PanelMenu::url('cases.index', ['status' => $status]) : null;
    };

    // «صف بررسی» صفحه اختصاصی خودش را دارد؛ اگر نبود، فهرست فیلترشده جایش می‌نشیند.
    $reviewUrl = $canReviewCases
        ? (PanelMenu::url('cases.review') ?? $casesByStatusUrl('needs_review'))
        : null;

    /** نشانی صفحه یک پرونده — اگر روتش هنوز نیست، ردیف بدون لینک می‌ماند. */
    $caseShowUrl = function ($case) use ($canReviewCases): ?string {
        return $canReviewCases ? PanelMenu::url('cases.show', ['case' => $case->getKey()]) : null;
    };

    /** میانگین زمان پردازش، خوانا: زیر یک ثانیه میلی‌ثانیه، بالاتر ثانیه. */
    $durationText = function (?float $ms): ?string {
        if ($ms === null) {
            return null;
        }

        return $ms < 1000
            ? PersianValue::decimal($ms, 0).' میلی‌ثانیه'
            : PersianValue::decimal($ms / 1000, 1).' ثانیه';
    };

    $avgConfidence = $metrics['avg_confidence'];
    $avgProcessing = $durationText($metrics['avg_processing_ms']);

    /*
     | کارت‌های بالای صفحه. هرکدام url دارد و اگر url داشته باشد به فهرست
     | فیلترشدهٔ همان چیز می‌رود. هیچ عددی این‌جا ساخته نمی‌شود — همه از
     | کنترلر و کنترلر از دیتابیس می‌آید؛ «میانگین»ِ نداشته «—» است نه صفر.
     */
    $caseCards = [
        [
            'value' => $stats['cases_total'],
            'label' => 'کل پرونده‌ها',
            'note' => 'همه وضعیت‌ها، از ابتدای راه‌اندازی',
            'tone' => null,
            'url' => $casesIndexUrl,
        ],
        [
            'value' => $stats['cases_approved'],
            'label' => 'تاییدشده',
            'note' => 'تصمیم خودکار یا کارشناس: تایید',
            'tone' => $stats['cases_approved'] > 0 ? 'ok' : null,
            'url' => $casesByStatusUrl('approved'),
        ],
        [
            'value' => $stats['cases_rejected'],
            'label' => 'ردشده',
            'note' => 'امتیاز اطمینان زیر آستانه رد',
            'tone' => $stats['cases_rejected'] > 0 ? 'bad' : null,
            'url' => $casesByStatusUrl('rejected'),
        ],
        [
            'value' => $stats['cases_needs_review'],
            'label' => 'در انتظار بررسی',
            'note' => 'پرونده‌هایی که تصمیم خودکار نگرفته‌اند',
            'tone' => $stats['cases_needs_review'] > 0 ? 'warn' : null,
            'url' => $reviewUrl,
        ],
    ];

    $qualityCards = [
        [
            'value' => $avgConfidence === null ? '—' : PersianValue::decimal($avgConfidence, 1).'٪',
            'label' => 'میانگین امتیاز اطمینان',
            'note' => $avgConfidence === null
                ? 'هنوز پرونده‌ای امتیاز نگرفته است'
                : 'روی '.PersianValue::decimal($metrics['scored']).' پرونده امتیازدهی‌شده',
            'tone' => 'info',
            'url' => $reportsUrl,
        ],
        [
            'value' => $avgProcessing ?? '—',
            'label' => 'میانگین زمان پردازش',
            'note' => $avgProcessing === null
                ? 'هنوز پرونده‌ای پردازش نشده است'
                : 'روی '.PersianValue::decimal($metrics['timed']).' پرونده پردازش‌شده',
            'tone' => null,
            'url' => $reportsUrl,
        ],
        [
            'value' => $stats['dataset_samples'],
            'label' => 'نمونه دیتاست',
            'note' => 'تصاویر آموزشی ثبت‌شده',
            'tone' => null,
            'url' => $canManageDataset ? PanelMenu::url('dataset.samples.index') : null,
        ],
        [
            'value' => $stats['test_images'],
            'label' => 'تصویر تستی',
            'note' => 'مدارک مصنوعی ساخته‌شده',
            'tone' => null,
            'url' => PanelMenu::url('testimage.index'),
        ],
    ];
@endphp

@section('content')

    <div class="page-head">
        <h1>سلام، {{ $user->name }}</h1>
        <div class="page-head__actions">
            @if ($reportsUrl !== null)
                <a class="btn btn--sm btn--ghost" href="{{ $reportsUrl }}">گزارش خطاها</a>
            @endif
            <x-badge tone="info" dot :label="$user->roleLabel()" />
        </div>
        <p class="page-head__sub">
            نمای کلی سامانه پیش‌اعتبارسنجی و پایش مجوزهای حمل‌ونقل.
            اعداد این صفحه لحظه‌ای از دیتابیس خوانده می‌شوند و هر کارت به فهرست
            فیلترشدهٔ همان چیز می‌رود.
        </p>
    </div>

    @foreach ([$caseCards, $qualityCards] as $cardRow)
        <div class="grid grid--4">
            @foreach ($cardRow as $card)
                @if ($card['url'] !== null)
                    <a href="{{ $card['url'] }}"
                       style="display:flex;text-decoration:none"
                       title="رفتن به فهرست «{{ $card['label'] }}»">
                        <x-stat
                            style="flex:1"
                            :value="$card['value']"
                            :label="$card['label']"
                            :note="$card['note']"
                            :tone="$card['tone']" />
                    </a>
                @else
                    <x-stat
                        :value="$card['value']"
                        :label="$card['label']"
                        :note="$card['note']"
                        :tone="$card['tone']" />
                @endif
            @endforeach
        </div>
    @endforeach

    <div class="grid grid--2">

        <div class="card">
            <div class="card__head">
                <h2>راهنمای شروع</h2>
                <span class="spacer"></span>
                <span class="tiny faint">بر اساس نقش شما</span>
            </div>
            <div class="card__body">
                @forelse ($startGuide as $step)
                    @php
                        $stepUrl = PanelMenu::url($step['route']);
                    @endphp
                    <div class="row">
                        <span aria-hidden="true" style="font-size:26px;line-height:1">{{ $step['icon'] }}</span>
                        <div class="stack stack--sm" style="flex:1;min-width:180px">
                            <div class="strong">{{ $step['title'] }}</div>
                            <div class="small muted">{{ $step['hint'] }}</div>
                        </div>
                        @if ($stepUrl !== null)
                            <a class="btn btn--sm btn--primary" href="{{ $stepUrl }}">
                                {{ $step['cta'] }}
                            </a>
                        @else
                            <span class="btn btn--sm is-disabled" aria-disabled="true">به‌زودی</span>
                        @endif
                    </div>
                @empty
                    <x-empty-state
                        icon="🔒"
                        title="برای نقش شما مسیر شروعی تعریف نشده"
                        hint="با مدیر سامانه تماس بگیرید." />
                @endforelse
            </div>
        </div>

        <div class="card">
            <div class="card__head">
                <h2>توزیع وضعیت پرونده‌ها</h2>
            </div>
            <div class="card__body">
                @if ($casesTotal > 0)
                    <div class="stack">
                        @foreach (\App\Models\PermitCase::STATUSES as $statusKey => $statusLabel)
                            @php
                                $statusCount = (int) $statusCounts->get($statusKey, 0);
                                $statusPercent = $casesTotal > 0 ? ($statusCount * 100 / $casesTotal) : 0;
                                $statusUrl = $casesByStatusUrl($statusKey);
                            @endphp
                            <div class="stack stack--sm">
                                <div class="row">
                                    @if ($statusUrl !== null)
                                        <a href="{{ $statusUrl }}" title="فهرست پرونده‌های «{{ $statusLabel }}»">
                                            <x-badge :tone="$statusTone[$statusKey] ?? null" dot :label="$statusLabel" />
                                        </a>
                                    @else
                                        <x-badge :tone="$statusTone[$statusKey] ?? null" dot :label="$statusLabel" />
                                    @endif
                                    <span class="spacer"></span>
                                    <span class="small muted"><x-num :value="$statusCount" /> پرونده</span>
                                </div>
                                <x-bar
                                    :percent="$statusPercent"
                                    :tone="$statusTone[$statusKey] ?? null"
                                    :label="$statusLabel" />
                            </div>
                        @endforeach

                        {{-- وضعیت‌های بیرون از فهرست تعریف‌شده گم نشوند --}}
                        @if ($casesOther > 0)
                            <div class="stack stack--sm">
                                <div class="row">
                                    <x-badge dot label="سایر" />
                                    <span class="spacer"></span>
                                    <span class="small muted"><x-num :value="$casesOther" /> پرونده</span>
                                </div>
                                <x-bar :percent="$casesOther * 100 / $casesTotal" label="سایر" />
                                <div class="tiny faint">وضعیت‌هایی که در فهرست استاندارد سامانه نیستند.</div>
                            </div>
                        @endif
                    </div>
                @else
                    <x-empty-state
                        icon="📈"
                        title="هنوز پرونده‌ای برای نمودار نیست"
                        hint="با ثبت اولین درخواست خدمت، توزیع وضعیت‌ها همین‌جا نمایش داده می‌شود." />
                @endif
            </div>
        </div>

    </div>

    {{--
        صف بررسی انسانی — فوری‌ترین اول.

        «فوری» ترکیب دو چیز است (محاسبه در DashboardController::reviewQueue):
        ۶۰٪ مدت انتظار نسبت به سقف مجاز + ۴۰٪ نزدیکی امتیاز به آستانه تایید.
        پرونده‌ای که هم مانده و هم با کمترین کار تعیین‌تکلیف می‌شود، اول صف است.
    --}}
    @if ($canReviewCases)
        <div class="card">
            <div class="card__head">
                <h2>صف بررسی انسانی</h2>
                @if ($reviewQueueTotal > 0)
                    <x-badge tone="warn" dot><x-num :value="$reviewQueueTotal" /> پرونده</x-badge>
                @endif
                <span class="spacer"></span>
                @if ($reviewUrl !== null)
                    <a class="btn btn--sm btn--ghost" href="{{ $reviewUrl }}">همه صف بررسی</a>
                @endif
            </div>

            @if ($reviewQueue->isEmpty())
                <div class="card__body">
                    <x-empty-state
                        icon="✅"
                        title="صف بررسی انسانی خالی است"
                        hint="هیچ پرونده‌ای منتظر تصمیم کارشناس نیست؛ تصمیم بقیه خودکار گرفته شده است." />
                </div>
            @else
                <div class="scroll-x">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>فوریت</th>
                                <th>کد پرونده</th>
                                <th>متقاضی</th>
                                <th>نوع خدمت</th>
                                <th>امتیاز اطمینان</th>
                                <th>مدت انتظار</th>
                                <th>تاریخ ثبت</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reviewQueue as $row)
                                @php
                                    $queuedCase = $row['case'];
                                    $queuedUrl = $caseShowUrl($queuedCase);
                                    $urgencyTone = $row['urgency'] >= 60 ? 'bad' : ($row['urgency'] >= 30 ? 'warn' : null);
                                @endphp
                                <tr>
                                    <td style="min-width:120px">
                                        <div class="stack stack--sm">
                                            <span class="small nowrap"><x-num :value="$row['urgency']" :decimals="1" /></span>
                                            <x-bar :percent="$row['urgency']" :tone="$urgencyTone" label="امتیاز فوریت" />
                                        </div>
                                    </td>
                                    <td class="mono">
                                        @if ($queuedUrl !== null)
                                            <a href="{{ $queuedUrl }}">{{ $queuedCase->code }}</a>
                                        @else
                                            {{ $queuedCase->code }}
                                        @endif
                                    </td>
                                    <td>{{ $queuedCase->applicant_name ?: '—' }}</td>
                                    <td>{{ $queuedCase->serviceType?->label_fa ?? '—' }}</td>
                                    <td>
                                        @if ($queuedCase->confidence_score !== null)
                                            <span class="nowrap"><x-num :value="$queuedCase->confidence_score" :decimals="1" />٪</span>
                                        @else
                                            <span class="faint">—</span>
                                        @endif
                                    </td>
                                    <td class="nowrap"><x-num :value="$row['wait_hours']" /> ساعت</td>
                                    <td><x-jdate :value="$queuedCase->submitted_at ?? $queuedCase->created_at" time /></td>
                                    <td>
                                        @if ($queuedUrl !== null)
                                            <a class="btn btn--sm btn--primary" href="{{ $queuedUrl }}">بررسی</a>
                                        @else
                                            <span class="btn btn--sm is-disabled" aria-disabled="true">به‌زودی</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card__foot">
                    <span class="tiny faint">
                        امتیاز فوریت = ۶۰٪ مدت انتظار (نسبت به سقف
                        <x-num :value="\App\Http\Controllers\DashboardController::REVIEW_SLA_HOURS" /> ساعت)
                        + ۴۰٪ نزدیکی امتیاز اطمینان به آستانه تایید.
                    </span>
                </div>
            @endif
        </div>
    @endif

    {{-- داده پرونده فقط برای نقش‌هایی که اجازه بررسی پرونده دارند --}}
    @if ($canReviewCases)
        <div class="card">
            <div class="card__head">
                <h2>پرونده‌های اخیر</h2>
                <span class="spacer"></span>
                @if ($casesIndexUrl !== null)
                    <a class="btn btn--sm btn--ghost" href="{{ $casesIndexUrl }}">همه پرونده‌ها</a>
                @endif
            </div>

            @if ($recentCases->isEmpty())
                <div class="card__body">
                    <x-empty-state
                        icon="📂"
                        title="هنوز هیچ پرونده‌ای ثبت نشده است"
                        hint="پس از ثبت اولین درخواست خدمت، پنج پرونده آخر همین‌جا فهرست می‌شود.">
                        @if ($casesCreateUrl !== null)
                            <a class="btn btn--sm btn--primary" href="{{ $casesCreateUrl }}">ثبت درخواست جدید</a>
                        @endif
                    </x-empty-state>
                </div>
            @else
                <div class="scroll-x">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>کد پرونده</th>
                                <th>متقاضی</th>
                                <th>نوع خدمت</th>
                                <th>وضعیت</th>
                                <th>امتیاز اطمینان</th>
                                <th>تاریخ ثبت</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentCases as $case)
                                @php $caseUrl = $caseShowUrl($case); @endphp
                                <tr>
                                    <td class="mono">
                                        @if ($caseUrl !== null)
                                            <a href="{{ $caseUrl }}">{{ $case->code }}</a>
                                        @else
                                            {{ $case->code }}
                                        @endif
                                    </td>
                                    <td>{{ $case->applicant_name ?: '—' }}</td>
                                    <td>{{ $case->serviceType?->label_fa ?? '—' }}</td>
                                    <td>
                                        @php $rowStatusUrl = $casesByStatusUrl($case->status); @endphp
                                        @if ($rowStatusUrl !== null)
                                            <a href="{{ $rowStatusUrl }}" title="فهرست پرونده‌های «{{ $case->statusLabel() }}»">
                                                <x-badge
                                                    :tone="$statusTone[$case->status] ?? null"
                                                    dot
                                                    :label="$case->statusLabel()" />
                                            </a>
                                        @else
                                            <x-badge
                                                :tone="$statusTone[$case->status] ?? null"
                                                dot
                                                :label="$case->statusLabel()" />
                                        @endif
                                    </td>
                                    <td>
                                        @if ($case->confidence_score !== null)
                                            <span class="nowrap"><x-num :value="$case->confidence_score" :decimals="1" />٪</span>
                                        @else
                                            <span class="faint">—</span>
                                        @endif
                                    </td>
                                    <td><x-jdate :value="$case->created_at" time /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    {{-- کار باز نقش داده: نمونه‌هایی که هنوز برچسب کامل یا تایید ندارند --}}
    @if ($canManageDataset)
        <div class="card">
            <div class="card__head">
                <h2>نمونه‌های در انتظار تگ‌گذاری</h2>
                @if ($pendingSamplesTotal > 0)
                    <x-badge tone="warn" dot><x-num :value="$pendingSamplesTotal" /> نمونه</x-badge>
                @endif
                <span class="spacer"></span>
                @if ($annotateQueueUrl !== null)
                    <a class="btn btn--sm btn--ghost" href="{{ $annotateQueueUrl }}">رفتن به صف تگ‌گذاری</a>
                @endif
            </div>

            @if ($pendingSamples->isEmpty())
                <div class="card__body">
                    <x-empty-state
                        icon="🏷"
                        title="صف تگ‌گذاری خالی است"
                        hint="همه نمونه‌های موجود برچسب کامل دارند و تایید شده‌اند." />
                </div>
            @else
                <div class="scroll-x">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>شناسه</th>
                                <th>نوع مدرک</th>
                                <th>منبع</th>
                                <th>پیشرفت برچسب</th>
                                <th>تایید</th>
                                <th>تاریخ ثبت</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pendingSamples as $sample)
                                @php
                                    $fieldTotal = (int) ($sample->documentType?->fields_count ?? 0);
                                    $labelled = (int) $sample->annotations_count;
                                    $annotateUrl = PanelMenu::url('dataset.annotate.edit', ['sample' => $sample->getKey()]);
                                @endphp
                                <tr>
                                    <td class="mono"><x-num :value="$sample->id" /></td>
                                    <td>{{ $sample->documentType?->label_fa ?? '—' }}</td>
                                    <td>{{ $sample->source === 'uploaded' ? 'بارگذاری‌شده' : 'تولیدشده' }}</td>
                                    <td class="nowrap">
                                        <x-num :value="$labelled" /> از <x-num :value="$fieldTotal" /> فیلد
                                    </td>
                                    <td>
                                        @if ($sample->is_verified)
                                            <x-badge tone="ok" dot label="تاییدشده" />
                                        @else
                                            <x-badge tone="warn" dot label="تایید نشده" />
                                        @endif
                                    </td>
                                    <td><x-jdate :value="$sample->created_at" time /></td>
                                    <td>
                                        @if ($annotateUrl !== null)
                                            <a class="btn btn--sm btn--primary" href="{{ $annotateUrl }}">تگ‌گذاری</a>
                                        @else
                                            <span class="btn btn--sm is-disabled" aria-disabled="true">به‌زودی</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

@endsection
