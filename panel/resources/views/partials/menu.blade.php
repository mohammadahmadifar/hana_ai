{{--
    منوی کناری — کاملاً داده‌محور از روی config/panel_menu.php ساخته می‌شود.

    قاعده مهم: ساخت نشانی هر آیتم از App\Support\PanelMenu رد می‌شود. اگر روت
    ثبت نشده باشد — یا ثبت شده ولی پارامتر اجباری داشته باشد و نشانی‌اش ساختنی
    نباشد — به‌جای لینک، یک آیتم غیرفعال با برچسب «به‌زودی» نشان داده می‌شود.
    پس این فایل نه خطای «Route [...] not defined» می‌دهد و نه UrlGenerationException؛
    یعنی یک روت ناقصِ ناحیهٔ دیگر نمی‌تواند همه صفحه‌های پنل را ۵۰۰ کند.
--}}
@php
    use App\Support\PanelMenu;

    $menuRole = auth()->user()->role ?? null;

    /** فیلتر نقش: آرایه خالی یعنی «برای همه». */
    $menuAllows = static fn (array $roles): bool => $roles === [] || in_array($menuRole, $roles, true);

    /**
     * شمارنده‌های badge. فقط وقتی محاسبه می‌شوند که آیتمِ صاحبِ شمارنده
     * هم برای این نقش قابل دیدن باشد و هم لینکش واقعاً ساختنی باشد.
     */
    $menuCounter = static fn (string $key): int => match ($key) {
        'cases_needs_review' => \App\Models\PermitCase::where('status', 'needs_review')->count(),
        default => 0,
    };
@endphp

@foreach (config('panel_menu.groups', []) as $group)
    @php
        $groupItems = collect($group['items'] ?? [])
            ->filter(fn ($item) => $menuAllows($item['roles'] ?? []));
    @endphp

    @continue(! $menuAllows($group['roles'] ?? []))
    @continue($groupItems->isEmpty())

    <div class="navgroup">{{ $group['label'] }}</div>

    @foreach ($groupItems as $item)
        @php
            $routeName = $item['route'] ?? null;
            $itemUrl = PanelMenu::url($routeName, (array) ($item['params'] ?? []));
            $patterns = (array) ($item['active'] ?? $routeName ?? []);
            $isActive = $itemUrl !== null && $patterns !== [] && request()->routeIs(...$patterns);
            $count = ($itemUrl !== null && ! empty($item['counter'])) ? $menuCounter($item['counter']) : 0;
        @endphp

        @if ($itemUrl !== null)
            <a href="{{ $itemUrl }}"
               class="navlink{{ $isActive ? ' is-active' : '' }}"
               @if ($isActive) aria-current="page" @endif>
                <span class="navlink__icon" aria-hidden="true">{{ $item['icon'] ?? '•' }}</span>
                <span>{{ $item['label'] }}</span>
                @if ($count > 0)
                    <span class="navlink__badge" title="تعداد در انتظار بررسی">
                        <x-num :value="$count" />
                    </span>
                @endif
            </a>
        @else
            <span class="navlink" aria-disabled="true" style="opacity:.5"
                  title="این بخش هنوز ساخته نشده است">
                <span class="navlink__icon" aria-hidden="true">{{ $item['icon'] ?? '•' }}</span>
                <span>{{ $item['label'] }}</span>
                <span class="spacer"></span>
                <span class="tiny faint">به‌زودی</span>
            </span>
        @endif
    @endforeach
@endforeach
