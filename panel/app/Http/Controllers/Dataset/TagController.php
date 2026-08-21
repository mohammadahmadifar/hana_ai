<?php

namespace App\Http\Controllers\Dataset;

use App\Http\Controllers\Controller;
use App\Models\DatasetTag;
use App\Support\Jalali;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * تگ‌های دیتاست — دسته‌بندی آزاد نمونه‌ها (مثل «کیفیت پایین» یا «فونت نازک»).
 *
 * تگ فقط برچسبِ سازمان‌دهی است؛ حذف یک تگ هرگز نمونه‌ای را پاک نمی‌کند و
 * فقط پیوندش با نمونه‌ها برداشته می‌شود.
 */
class TagController extends Controller
{
    /** رنگ پیش‌فرض تگ تازه — همان پیش‌فرض ستون دیتابیس. */
    public const DEFAULT_COLOR = '#64748b';

    /** فهرست تگ‌ها همراه شمار نمونه‌های هر کدام. */
    public function index(): View
    {
        $tags = DatasetTag::query()
            ->withCount('samples')
            ->orderByDesc('samples_count')
            ->orderBy('name')
            ->get();

        return view('dataset.tags.index', [
            'tags' => $tags,
            'usedCount' => $tags->where('samples_count', '>', 0)->count(),
            'linkCount' => (int) $tags->sum('samples_count'),
            'defaultColor' => self::DEFAULT_COLOR,
        ]);
    }

    /** ثبت تگ تازه. */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $tag = DatasetTag::query()->create($data);

        return redirect()
            ->route('dataset.tags.index')
            ->with('success', 'تگ «'.$tag->name.'» ساخته شد.');
    }

    /** ویرایش تگ موجود. */
    public function update(Request $request, DatasetTag $tag): RedirectResponse
    {
        $data = $this->validated($request, $tag);

        $tag->update($data);

        return redirect()
            ->route('dataset.tags.index')
            ->with('success', 'تگ «'.$tag->name.'» به‌روزرسانی شد.');
    }

    /** حذف تگ — پیوندش با نمونه‌ها برداشته می‌شود، نمونه‌ها سر جایشان می‌مانند. */
    public function destroy(DatasetTag $tag): RedirectResponse
    {
        $name = $tag->name;
        $linked = $tag->samples()->count();

        $tag->samples()->detach();
        $tag->delete();

        $note = $linked > 0
            ? 'تگ «'.$name.'» حذف شد و از '.Jalali::digits($linked).' نمونه برداشته شد. خود نمونه‌ها دست‌نخورده‌اند.'
            : 'تگ «'.$name.'» حذف شد.';

        return redirect()->route('dataset.tags.index')->with('success', $note);
    }

    /** اعتبارسنجی مشترک ساخت و ویرایش. */
    private function validated(Request $request, ?DatasetTag $tag = null): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('dataset_tags', 'name')->ignore($tag?->id),
            ],
            // رنگ از ورودی type=color می‌آید: همیشه #rrggbb
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description_fa' => ['nullable', 'string', 'max:500'],
        ], [
            'required' => 'پر کردن :attribute الزامی است.',
            'string' => 'مقدار :attribute باید متن باشد.',
            'max' => ':attribute طولانی‌تر از حد مجاز است.',
            'unique' => 'تگی با این نام از قبل وجود دارد.',
            'color.regex' => 'رنگ انتخاب‌شده معتبر نیست.',
        ], [
            'name' => 'نام تگ',
            'color' => 'رنگ',
            'description_fa' => 'توضیح',
        ]);

        $data['name'] = trim($data['name']);
        $data['color'] = strtolower($data['color']);
        $data['description_fa'] = filled($data['description_fa'] ?? null) ? trim($data['description_fa']) : null;

        return $data;
    }
}
