@extends('layouts.panel')

@section('title', 'داشبورد')
@section('page_title', 'داشبورد')

@php
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
     * اگر روتش هنوز ساخته نشده باشد، دکمه‌اش غیرفعال و «به‌زودی» می‌شود.
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
@endphp

@section('content')

    <div class="page-head">
        <h1>سلام، {{ $user->name }}</h1>
        <div class="page-head__actions">
            <x-badge tone="info" dot :label="$user->roleLabel()" />
        </div>
        <p class="page-head__sub">
            نمای کلی سامانه پیش‌اعتبارسنجی و پایش مجوزهای حمل‌ونقل.
            اعداد این صفحه لحظه‌ای از دیتابیس خوانده می‌شوند.
        </p>
    </div>

    <div class="grid grid--4">
        <x-stat
            :value="$stats['cases_total']"
            label="کل پرونده‌ها"
            note="همه وضعیت‌ها، از ابتدای راه‌اندازی" />
        <x-stat
            :value="$stats['cases_needs_review']"
            label="در صف بررسی کارشناس"
            note="پرونده‌هایی که تصمیم خودکار نگرفته‌اند"
            :tone="$stats['cases_needs_review'] > 0 ? 'warn' : null" />
        <x-stat
            :value="$stats['dataset_samples']"
            label="نمونه دیتاست"
            note="تصاویر آموزشی ثبت‌شده"
            tone="info" />
        <x-stat
            :value="$stats['test_images']"
            label="تصویر تستی"
            note="مدارک مصنوعی ساخته‌شده" />
    </div>

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
                        $stepExists = \Illuminate\Support\Facades\Route::has($step['route']);
                    @endphp
                    <div class="row">
                        <span aria-hidden="true" style="font-size:26px;line-height:1">{{ $step['icon'] }}</span>
                        <div class="stack stack--sm" style="flex:1;min-width:180px">
                            <div class="strong">{{ $step['title'] }}</div>
                            <div class="small muted">{{ $step['hint'] }}</div>
                        </div>
                        @if ($stepExists)
                            <a class="btn btn--sm btn--primary" href="{{ route($step['route']) }}">
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
                            @endphp
                            <div class="stack stack--sm">
                                <div class="row">
                                    <x-badge :tone="$statusTone[$statusKey] ?? null" dot :label="$statusLabel" />
                                    <span class="spacer"></span>
                                    <span class="small muted"><x-num :value="$statusCount" /> پرونده</span>
                                </div>
                                <x-bar
                                    :percent="$statusPercent"
                                    :tone="$statusTone[$statusKey] ?? null"
                                    :label="$statusLabel" />
                            </div>
                        @endforeach
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

    <div class="card">
        <div class="card__head">
            <h2>پرونده‌های اخیر</h2>
            <span class="spacer"></span>
            @if (\Illuminate\Support\Facades\Route::has('cases.index'))
                <a class="btn btn--sm btn--ghost" href="{{ route('cases.index') }}">همه پرونده‌ها</a>
            @endif
        </div>

        @if ($recentCases->isEmpty())
            <div class="card__body">
                <x-empty-state
                    icon="📂"
                    title="هنوز هیچ پرونده‌ای ثبت نشده است"
                    hint="پس از ثبت اولین درخواست خدمت، پنج پرونده آخر همین‌جا فهرست می‌شود.">
                    @if (\Illuminate\Support\Facades\Route::has('cases.create'))
                        <a class="btn btn--sm btn--primary" href="{{ route('cases.create') }}">ثبت درخواست جدید</a>
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
                            <tr>
                                <td class="mono">{{ $case->code }}</td>
                                <td>{{ $case->applicant_name ?: '—' }}</td>
                                <td>{{ $case->serviceType?->label_fa ?? '—' }}</td>
                                <td>
                                    <x-badge
                                        :tone="$statusTone[$case->status] ?? null"
                                        dot
                                        :label="$case->statusLabel()" />
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

@endsection
