<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PermitCase;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cases\CaseScorer;
use App\Support\PersianValue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

/**
 * تنظیمات امتیازدهی — فقط برای نقش «مدیر سامانه» (تسک ۶۳۴).
 *
 * قانون پروژه: هیچ وزن و آستانه‌ای در کد هاردکد نیست. CaseScorer همین دو کلید
 * را از جدول settings می‌خواند، پس تغییر این صفحه بلافاصله روی تصمیم پرونده‌های
 * بعدی اثر می‌گذارد. هر تغییر با مقدار قبلی و جدید و شناسهٔ کاربر لاگ می‌شود و
 * روی خود صفحه هم «آخرین تغییر توسط ... در ...» نمایش داده می‌شود.
 */
class SettingsController extends Controller
{
    private const KEY_WEIGHTS = 'scoring.weights';

    private const KEY_THRESHOLDS = 'scoring.thresholds';

    private const KEY_PENALTIES = 'scoring.penalties';

    /** صفحهٔ ویرایش وزن‌ها و آستانه‌ها. */
    public function scoring(): View
    {
        // مقدارهای مؤثر (نه مقدار خام دیتابیس): اگر کلیدی نبود یا خراب بود،
        // همان چیزی نمایش داده می‌شود که موتور امتیازدهی واقعاً استفاده می‌کند.
        $weights = CaseScorer::weights();
        $thresholds = CaseScorer::thresholds();

        return view('admin.settings.scoring', [
            'weights' => $weights,
            'labels' => CaseScorer::COMPONENT_LABELS,
            'thresholds' => $thresholds,
            'penalties' => CaseScorer::penalties(),
            'defaultWeights' => CaseScorer::DEFAULT_WEIGHTS,
            'defaultThresholds' => CaseScorer::DEFAULT_THRESHOLDS,
            'defaultPenalties' => CaseScorer::DEFAULT_PENALTIES,
            'weightsMeta' => $this->meta(self::KEY_WEIGHTS),
            'thresholdsMeta' => $this->meta(self::KEY_THRESHOLDS),
            'penaltiesMeta' => $this->meta(self::KEY_PENALTIES),
            'impact' => $this->impact($thresholds),
        ]);
    }

    /** ذخیرهٔ وزن‌ها و آستانه‌ها. */
    public function updateScoring(Request $request): RedirectResponse
    {
        $validator = validator($request->all(), [
            'weights' => ['required', 'array'],
            'weights.ocr_quality' => ['required', 'numeric', 'min:0', 'max:100'],
            'weights.validation' => ['required', 'numeric', 'min:0', 'max:100'],
            'weights.completeness' => ['required', 'numeric', 'min:0', 'max:100'],
            'approve_at' => ['required', 'numeric', 'min:0', 'max:100'],
            'reject_below' => ['required', 'numeric', 'min:0', 'max:100'],
            'penalties' => ['required', 'array'],
            'penalties.failed' => ['required', 'numeric', 'min:1', 'max:100'],
            'penalties.warning' => ['required', 'numeric', 'min:0', 'max:100'],
        ], self::messages(), self::attributes());

        $validator->after(function (Validator $check) use ($request): void {
            $this->checkWeightSum($check, $request);
            $this->checkThresholdOrder($check, $request);
            $this->checkPenaltyOrder($check, $request);
        });

        $data = $validator->validate();

        $newWeights = [
            'ocr_quality' => (float) $data['weights']['ocr_quality'],
            'validation' => (float) $data['weights']['validation'],
            'completeness' => (float) $data['weights']['completeness'],
        ];

        $newThresholds = [
            'approve_at' => (float) $data['approve_at'],
            'reject_below' => (float) $data['reject_below'],
            'cross_fail_rejects' => $request->boolean('cross_fail_rejects'),
            'unread_required_holds' => $request->boolean('unread_required_holds'),
            'expired_rejects' => $request->boolean('expired_rejects'),
        ];

        $newPenalties = [
            'failed' => (float) $data['penalties']['failed'],
            'warning' => (float) $data['penalties']['warning'],
        ];

        $userId = (int) $request->user()->id;

        $changed = [];

        if ($this->store(self::KEY_WEIGHTS, $newWeights, $userId, $request)) {
            $changed[] = 'وزن‌ها';
        }

        if ($this->store(self::KEY_THRESHOLDS, $newThresholds, $userId, $request)) {
            $changed[] = 'آستانه‌ها';
        }

        if ($this->store(self::KEY_PENALTIES, $newPenalties, $userId, $request)) {
            $changed[] = 'جریمهٔ ایرادها';
        }

        $note = $changed === []
            ? 'تنظیمات امتیازدهی تغییری نکرد؛ همان مقدارهای قبلی ثبت است.'
            : implode(' و ', $changed).' ذخیره شد و از همین حالا روی پرونده‌های تازه‌پردازش‌شده اعمال می‌شود.';

        return redirect()->route('admin.settings.scoring')->with('success', $note);
    }

    /**
     * ذخیرهٔ یک کلید + لاگ تغییر.
     *
     * لاگ فقط وقتی نوشته می‌شود که مقدار واقعاً عوض شده باشد؛ وگرنه فایل لاگ
     * پر از ردیف بی‌معنی می‌شود و ردِ تغییر واقعی گم می‌شود.
     */
    private function store(string $key, array $value, int $userId, Request $request): bool
    {
        $old = Setting::get($key);

        if (is_array($old) && $this->sameValue($old, $value)) {
            return false;
        }

        Setting::put($key, $value, $userId);

        Log::info('تغییر تنظیمات امتیازدهی', [
            'key' => $key,
            'old' => $old,
            'new' => $value,
            'user_id' => $userId,
            'user_email' => $request->user()->email,
            'ip' => $request->ip(),
        ]);

        return true;
    }

    /** مقایسهٔ مقدار قدیم و جدید بدون حساسیت به نوعِ عددی (۸۰ و «۸۰» یکی‌اند). */
    private function sameValue(array $old, array $new): bool
    {
        $flatten = static function (array $row): array {
            ksort($row);

            return array_map(
                static fn ($item) => is_bool($item) ? $item : (is_numeric($item) ? (float) $item : $item),
                $row,
            );
        };

        return $flatten($old) === $flatten($new);
    }

    /** جمع وزن‌ها باید دقیقاً ۱۰۰ باشد، وگرنه امتیاز نهایی معنی ندارد. */
    private function checkWeightSum(Validator $check, Request $request): void
    {
        $weights = $request->input('weights');

        if (! is_array($weights)) {
            return;
        }

        $parts = [];

        foreach (array_keys(CaseScorer::DEFAULT_WEIGHTS) as $key) {
            if (! isset($weights[$key]) || ! is_numeric($weights[$key])) {
                return; // خطای «الزامی/عددی» را همان قاعدهٔ اصلی می‌دهد.
            }

            $parts[] = (float) $weights[$key];
        }

        $sum = round(array_sum($parts), 2);

        if (abs($sum - 100.0) > 0.01) {
            $check->errors()->add('weights', 'جمع سه وزن باید دقیقاً ۱۰۰ باشد، ولی الان '
                .PersianValue::decimal($sum, 2).' است. مثلاً ۴۰ و ۴۰ و ۲۰.');
        }
    }

    /** آستانهٔ رد باید زیر آستانهٔ تایید باشد، وگرنه «نیاز به بررسی» ناممکن می‌شود. */
    private function checkThresholdOrder(Validator $check, Request $request): void
    {
        $approve = $request->input('approve_at');
        $reject = $request->input('reject_below');

        if (! is_numeric($approve) || ! is_numeric($reject)) {
            return;
        }

        if ((float) $reject >= (float) $approve) {
            $check->errors()->add('reject_below', 'آستانهٔ رد ('
                .PersianValue::decimal((float) $reject, 0).') باید کمتر از آستانهٔ تایید ('
                .PersianValue::decimal((float) $approve, 0).') باشد؛ وگرنه هیچ پرونده‌ای به '
                .'«نیاز به بررسی» نمی‌رسد. عدد کوچک‌تری برای آستانهٔ رد بگذارید.');
        }
    }

    /** هشدار نباید گران‌تر از ایراد جدی تمام شود، وگرنه شدت‌ها وارونه می‌شوند. */
    private function checkPenaltyOrder(Validator $check, Request $request): void
    {
        $failed = $request->input('penalties.failed');
        $warning = $request->input('penalties.warning');

        if (! is_numeric($failed) || ! is_numeric($warning)) {
            return;
        }

        if ((float) $warning > (float) $failed) {
            $check->errors()->add('penalties.warning', 'جریمهٔ هشدار ('
                .PersianValue::decimal((float) $warning, 0).') نباید از جریمهٔ ایراد جدی ('
                .PersianValue::decimal((float) $failed, 0).') بیشتر باشد؛ وگرنه یک هشدار ساده '
                .'بیشتر از یک ایراد جدی امتیاز کم می‌کند. عدد کوچک‌تری برای هشدار بگذارید.');
        }
    }

    /**
     * «آخرین تغییر توسط ... در ...» برای یک کلید.
     *
     * @return array{user: ?string, at: mixed}
     */
    private function meta(string $key): array
    {
        $row = Setting::query()->where('key', $key)->first();

        return [
            'user' => $row && $row->updated_by
                ? User::query()->whereKey($row->updated_by)->value('name')
                : null,
            'at' => $row?->updated_at,
        ];
    }

    /**
     * این آستانه‌ها الان روی چند پروندهٔ امتیازخورده اثر دارند؟
     *
     * @param  array{approve_at: float, reject_below: float, cross_fail_rejects: bool, unread_required_holds: bool, expired_rejects: bool}  $thresholds
     * @return array<string, int>
     */
    private function impact(array $thresholds): array
    {
        $scored = PermitCase::query()->whereNotNull('confidence_score');

        // دو شرطِ ردیف اعتبارسنجی که تصمیم را از عدد جدا می‌کنند. هر دو دقیقاً
        // همان چیزی را می‌پرسند که CaseScorer::decide() می‌پرسد.
        $unreadRows = fn ($query) => $query
            ->where('scope', 'document')
            ->where('rule_key', 'like', 'document.missing_required.%')
            // skipped هم هست: یعنی مدرک اجباری اصلاً نیامده — همان حالتی که
            // جریمهٔ اعتبارسنجی نمی‌گیرد و بدون نگهبان خودکار تایید می‌شد.
            ->whereIn('status', ['failed', 'warning', 'skipped']);

        $expiredRows = fn ($query) => $query
            ->where('scope', 'document')
            ->where('status', 'failed')
            ->where('rule_key', 'like', 'document.expired.%');

        $total = (clone $scored)->count();

        // پروندهٔ بالای آستانه که دادهٔ اجباریِ دیده‌نشده دارد تایید خودکار
        // نمی‌شود، و پروندهٔ دارای مدرک منقضی اصلاً رد می‌شود
        // (CaseScorer::decide). بدون این دو کسر، همین صفحه — که تنها جای دیدنِ
        // اثرِ تنظیمات است — تعداد تایید خودکار را بیشتر از واقعیت نشان می‌داد.
        //
        // کسر با whereDoesntHave انجام می‌شود نه با تفریقِ دو شمارش، چون یک
        // پرونده می‌تواند هم‌زمان هر دو ایراد را داشته باشد و آن‌وقت دو بار از
        // «تایید» کم می‌شد.
        //
        // عدد **تقریبی** است و متن صفحه هم «حدود» می‌گوید: این کوئری‌ها شرطِ
        // «آیا خدمتِ این پرونده آن مدرک را اجباری کرده؟» را ندارند، چون آن
        // شرط روی پیوت است و آوردنش به SQL این پرس‌وجوی نمایشی را چند برابر
        // گران می‌کند. تصمیم واقعی همیشه با CaseScorer است، نه با این عدد.
        $approve = (clone $scored)
            ->where('confidence_score', '>=', $thresholds['approve_at'])
            ->when($thresholds['unread_required_holds'],
                fn ($query) => $query->whereDoesntHave('validationResults', $unreadRows))
            ->when($thresholds['expired_rejects'],
                fn ($query) => $query->whereDoesntHave('validationResults', $expiredRows))
            ->count();

        // مدرک منقضی مستقل از امتیاز رد می‌کند (تسک ۷۳۸)، پس شرطش با «یا» کنار
        // آستانهٔ رد می‌نشیند — نه جمعِ دو شمارش، که پروندهٔ کم‌امتیازِ منقضی را
        // دو بار می‌شمرد.
        $reject = (clone $scored)
            ->where(function ($query) use ($thresholds, $expiredRows): void {
                $query->where('confidence_score', '<', $thresholds['reject_below']);

                if ($thresholds['expired_rejects']) {
                    $query->orWhereHas('validationResults', $expiredRows);
                }
            })
            ->count();

        $held = $thresholds['unread_required_holds']
            ? (clone $scored)
                ->where('confidence_score', '>=', $thresholds['approve_at'])
                ->whereHas('validationResults', $unreadRows)
                ->count()
            : 0;

        $expired = $thresholds['expired_rejects']
            ? (clone $scored)->whereHas('validationResults', $expiredRows)->count()
            : 0;

        return [
            'total' => $total,
            'approved' => $approve,
            'rejected' => $reject,
            'held_unread' => $held,
            'expired' => $expired,
            'needs_review' => max(0, $total - $approve - $reject),
            'cross_failed' => PermitCase::query()
                ->whereHas('validationResults', fn ($query) => $query
                    ->where('scope', 'cross')
                    ->where('status', 'failed'))
                ->count(),
        ];
    }

    /** پیام‌های فارسی اعتبارسنجی. */
    private static function messages(): array
    {
        return [
            'required' => 'پر کردن :attribute الزامی است.',
            'array' => 'قالب وزن‌ها درست ارسال نشده است؛ صفحه را تازه کنید و دوباره ذخیره کنید.',
            'numeric' => ':attribute باید عدد باشد.',
            'min' => ':attribute کمتر از حد مجاز است.',
            'penalties.failed.min' => 'جریمهٔ هر ایراد جدی باید دست‌کم ۱ باشد، وگرنه ایراد جدی هیچ اثری ندارد.',
            'max' => ':attribute نمی‌تواند بیشتر از ۱۰۰ باشد.',
        ];
    }

    /** نام فارسی فیلدها. */
    private static function attributes(): array
    {
        return [
            'weights' => 'وزن‌ها',
            'weights.ocr_quality' => 'وزن کیفیت تشخیص متن',
            'weights.validation' => 'وزن نتیجهٔ اعتبارسنجی',
            'weights.completeness' => 'وزن کامل بودن مدارک',
            'approve_at' => 'آستانهٔ تایید',
            'reject_below' => 'آستانهٔ رد',
            'penalties' => 'جریمهٔ ایرادها',
            'penalties.failed' => 'جریمهٔ هر ایراد جدی',
            'penalties.warning' => 'جریمهٔ هر هشدار',
            'cross_fail_rejects' => 'رد خودکار پروندهٔ ناهمخوان',
            'unread_required_holds' => 'نگه‌داشتن پروندهٔ دارای فیلد اجباریِ خوانده‌نشده',
            'expired_rejects' => 'رد خودکار پروندهٔ دارای مدرک منقضی',
        ];
    }
}
