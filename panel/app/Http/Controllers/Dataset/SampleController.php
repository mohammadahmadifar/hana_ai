<?php

namespace App\Http\Controllers\Dataset;

use App\Http\Controllers\Controller;
use App\Models\DatasetSample;
use App\Models\DatasetTag;
use App\Models\DocumentType;
use App\Models\User;
use App\Support\Jalali;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * مدیریت نمونه‌های دیتاست آموزشی.
 *
 * این کنترلر فقط «مدیریت» می‌کند: فهرست، پالایش، جزئیات، تگ، تایید و حذف.
 * ساختِ نمونه کار بخش «تولید انبوه» است و تگ‌گذاری کادرها کار بخش «تگ‌گذاری».
 */
class SampleController extends Controller
{
    /** شمار نمونه در هر صفحه. */
    public const PER_PAGE = 24;

    /** منبع نمونه → برچسب فارسی. */
    public const SOURCES = [
        'generated' => 'تولیدشده',
        'uploaded' => 'آپلودی',
    ];

    /** بخش دیتاست → برچسب فارسی. */
    public const SPLITS = [
        'train' => 'آموزش',
        'val' => 'اعتبارسنجی',
        'test' => 'آزمون',
    ];

    /** برچسب فارسی اعوجاج‌های شناخته‌شده (هر مقدار ناشناخته خودش چاپ می‌شود). */
    public const AUGMENTATIONS = [
        'none' => 'بدون اعوجاج',
        'clean' => 'بدون اعوجاج',
        'rotation' => 'چرخش',
        'brightness' => 'روشنایی',
        'blur' => 'تاری',
        'noise' => 'نویز',
        'shadow' => 'سایه',
        'mixed' => 'ترکیبی',
        'combo' => 'ترکیبی',
    ];

    /** کارهای دسته‌ای مجاز → برچسب فارسی (در ویو هم استفاده می‌شود). */
    public const BULK_ACTIONS = [
        'tag_add' => 'افزودن تگ',
        'tag_remove' => 'حذف تگ',
        'split' => 'تغییر بخش دیتاست',
        'delete' => 'حذف نمونه‌ها',
    ];

    /** فهرست نمونه‌ها با پالایش و صفحه‌بندی. */
    public function index(Request $request): View
    {
        $filters = [
            'type' => (string) $request->query('type', ''),
            'source' => (string) $request->query('source', ''),
            'aug' => (string) $request->query('aug', ''),
            'split' => (string) $request->query('split', ''),
            'verified' => (string) $request->query('verified', ''),
            'tag' => (string) $request->query('tag', ''),
        ];

        $documentTypes = DocumentType::query()->orderBy('sort')->get();
        $tags = DatasetTag::query()->orderBy('name')->get();

        // مقادیر اعوجاج را از خود داده می‌خوانیم تا با هر چیزی که تولیدکننده
        // ذخیره کرده جور باشد و حدس نزنیم.
        $augmentationValues = DatasetSample::query()
            ->select('augmentation')
            ->distinct()
            ->orderBy('augmentation')
            ->pluck('augmentation')
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values();

        $hasEmptyAugmentation = $augmentationValues->contains('');
        $augmentationOptions = [];

        if ($hasEmptyAugmentation) {
            $augmentationOptions['none'] = self::AUGMENTATIONS['none'];
        }

        foreach ($augmentationValues as $value) {
            if ($value === '') {
                continue;
            }

            $augmentationOptions[$value] = self::augmentationLabel($value);
        }

        $query = DatasetSample::query()
            ->with(['documentType', 'tags'])
            ->withCount('annotations');

        if ($filters['type'] !== '' && ctype_digit($filters['type'])) {
            $query->where('document_type_id', (int) $filters['type']);
        }

        if (array_key_exists($filters['source'], self::SOURCES)) {
            $query->where('source', $filters['source']);
        }

        if ($filters['aug'] === 'none') {
            $query->where(fn ($inner) => $inner->whereNull('augmentation')->orWhere('augmentation', ''));
        } elseif ($filters['aug'] !== '') {
            $query->where('augmentation', $filters['aug']);
        }

        if (array_key_exists($filters['split'], self::SPLITS)) {
            $query->where('split', $filters['split']);
        }

        if ($filters['verified'] === '1' || $filters['verified'] === '0') {
            $query->where('is_verified', $filters['verified'] === '1');
        }

        if ($filters['tag'] !== '' && ctype_digit($filters['tag'])) {
            $tagId = (int) $filters['tag'];
            $query->whereHas('tags', fn ($inner) => $inner->where('dataset_tags.id', $tagId));
        }

        /** @var LengthAwarePaginator $samples */
        $samples = $query->orderByDesc('id')->paginate(self::PER_PAGE)->withQueryString();

        return view('dataset.samples.index', [
            'samples' => $samples,
            'filters' => $filters,
            'hasAnyFilter' => count(array_filter($filters, fn ($v) => $v !== '')) > 0,
            'documentTypes' => $documentTypes,
            'tags' => $tags,
            'augmentationOptions' => $augmentationOptions,
            'sources' => self::SOURCES,
            'splits' => self::SPLITS,
            'bulkActions' => self::BULK_ACTIONS,
            'totals' => $this->totals(),
        ]);
    }

    /** جزئیات یک نمونه: تصویر، برچسب‌های فیلد، متادیتا و تگ‌ها. */
    public function show(Request $request, DatasetSample $sample): View
    {
        $sample->load(['documentType.fields', 'annotations', 'tags']);

        $fieldLabels = $sample->documentType?->fields->pluck('label_fa', 'key') ?? collect();
        $fieldOrder = $sample->documentType?->fields->pluck('key')->values()->all() ?? [];

        // برچسب‌ها را به ترتیب فیلدهای همان نوع مدرک می‌چینیم؛ هر فیلد ناشناخته آخر می‌آید.
        $annotations = $sample->annotations
            ->sortBy(function ($annotation) use ($fieldOrder) {
                $position = array_search($annotation->field_key, $fieldOrder, true);

                return $position === false ? 9999 : $position;
            })
            ->values();

        $people = User::query()
            ->whereIn('id', array_filter([$sample->created_by, $sample->verified_by]))
            ->pluck('name', 'id');

        $tab = $request->query('tab') === 'clean' && filled($sample->clean_path) ? 'clean' : 'final';

        return view('dataset.samples.show', [
            'sample' => $sample,
            'annotations' => $annotations,
            'fieldLabels' => $fieldLabels,
            'availableTags' => DatasetTag::query()
                ->whereNotIn('id', $sample->tags->pluck('id'))
                ->orderBy('name')
                ->get(),
            'creatorName' => $people[$sample->created_by] ?? null,
            'verifierName' => $people[$sample->verified_by] ?? null,
            'sources' => self::SOURCES,
            'splits' => self::SPLITS,
            'tab' => $tab,
        ]);
    }

    /** تایید یا لغو تایید برچسب‌های نمونه. */
    public function verify(Request $request, DatasetSample $sample): RedirectResponse
    {
        if ($sample->is_verified) {
            $sample->forceFill([
                'is_verified' => false,
                'verified_by' => null,
                'verified_at' => null,
            ])->save();

            return back()->with('warning', 'تایید نمونه شمارهٔ '.Jalali::digits($sample->id).' لغو شد.');
        }

        $sample->forceFill([
            'is_verified' => true,
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
        ])->save();

        return back()->with('success', 'برچسب‌های نمونه شمارهٔ '.Jalali::digits($sample->id).' تایید شد.');
    }

    /** افزودن یک تگ به نمونه. */
    public function attachTag(Request $request, DatasetSample $sample): RedirectResponse
    {
        $data = $request->validate(
            ['tag_id' => ['required', 'integer', Rule::exists('dataset_tags', 'id')]],
            self::messages(),
            ['tag_id' => 'تگ'],
        );

        $tag = DatasetTag::query()->findOrFail($data['tag_id']);
        $sample->tags()->syncWithoutDetaching([$tag->id]);

        return back()->with('success', 'تگ «'.$tag->name.'» به این نمونه اضافه شد.');
    }

    /** برداشتن یک تگ از نمونه. */
    public function detachTag(DatasetSample $sample, DatasetTag $tag): RedirectResponse
    {
        $sample->tags()->detach($tag->id);

        return back()->with('success', 'تگ «'.$tag->name.'» از این نمونه برداشته شد.');
    }

    /** حذف نمونه به همراه فایل‌هایش روی دیسک. */
    public function destroy(DatasetSample $sample): RedirectResponse
    {
        $id = $sample->id;
        $this->forgetFiles($sample);
        $sample->delete(); // برچسب‌ها و پیوت تگ با cascade پاک می‌شوند

        return redirect()
            ->route('dataset.samples.index')
            ->with('success', 'نمونه شمارهٔ '.Jalali::digits($id).' و فایل‌هایش حذف شد.');
    }

    /** کارهای دسته‌ای روی نمونه‌های انتخاب‌شده. */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(array_keys(self::BULK_ACTIONS))],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'tag_id' => ['required_if:action,tag_add,tag_remove', 'nullable', 'integer', Rule::exists('dataset_tags', 'id')],
            'split' => ['required_if:action,split', 'nullable', 'string', Rule::in(array_keys(self::SPLITS))],
        ], self::messages(), [
            'action' => 'عملیات',
            'ids' => 'نمونه‌های انتخاب‌شده',
            'tag_id' => 'تگ',
            'split' => 'بخش دیتاست',
        ]);

        $samples = DatasetSample::query()->whereIn('id', $data['ids'])->get();

        if ($samples->isEmpty()) {
            return back()->with('error', 'هیچ‌کدام از نمونه‌های انتخاب‌شده پیدا نشد؛ شاید پیش‌تر حذف شده باشند.');
        }

        $count = Jalali::digits($samples->count());

        return match ($data['action']) {
            'tag_add' => $this->bulkTag($samples, (int) $data['tag_id'], true, $count),
            'tag_remove' => $this->bulkTag($samples, (int) $data['tag_id'], false, $count),
            'split' => $this->bulkSplit($samples, (string) $data['split'], $count),
            'delete' => $this->bulkDelete($samples, $count),
            default => back(),
        };
    }

    /** برچسب فارسی یک مقدار اعوجاج (مقدار ناشناخته را دست‌نخورده برمی‌گرداند). */
    public static function augmentationLabel(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return self::AUGMENTATIONS['none'];
        }

        if (isset(self::AUGMENTATIONS[$value])) {
            return self::AUGMENTATIONS[$value];
        }

        // مقدارهای ترکیبی مثل «rotation+noise» را جزءبه‌جزء ترجمه می‌کنیم.
        if (str_contains($value, '+') || str_contains($value, ',')) {
            $parts = preg_split('/[+,]/', $value) ?: [];
            $labels = array_map(
                fn ($part) => self::AUGMENTATIONS[trim($part)] ?? trim($part),
                array_filter($parts, fn ($part) => trim($part) !== ''),
            );

            if ($labels !== []) {
                return implode(' + ', $labels);
            }
        }

        return $value;
    }

    /** افزودن یا حذف یک تگ برای گروهی از نمونه‌ها. */
    private function bulkTag($samples, int $tagId, bool $attach, string $count): RedirectResponse
    {
        $tag = DatasetTag::query()->findOrFail($tagId);

        foreach ($samples as $sample) {
            $attach
                ? $sample->tags()->syncWithoutDetaching([$tag->id])
                : $sample->tags()->detach($tag->id);
        }

        return back()->with('success', $attach
            ? 'تگ «'.$tag->name.'» به '.$count.' نمونه اضافه شد.'
            : 'تگ «'.$tag->name.'» از '.$count.' نمونه برداشته شد.');
    }

    /** تغییر بخش دیتاست گروهی از نمونه‌ها. */
    private function bulkSplit($samples, string $split, string $count): RedirectResponse
    {
        DatasetSample::query()->whereIn('id', $samples->pluck('id'))->update(['split' => $split]);

        return back()->with('success', 'بخش دیتاست '.$count.' نمونه به «'.(self::SPLITS[$split] ?? $split).'» تغییر کرد.');
    }

    /** حذف گروهی نمونه‌ها به همراه فایل‌هایشان. */
    private function bulkDelete($samples, string $count): RedirectResponse
    {
        foreach ($samples as $sample) {
            $this->forgetFiles($sample);
        }

        DatasetSample::query()->whereIn('id', $samples->pluck('id'))->delete();

        return back()->with('success', $count.' نمونه و فایل‌هایشان حذف شد.');
    }

    /** پاک کردن فایل‌های یک نمونه از دیسک خصوصی (نبودِ فایل خطا نیست). */
    private function forgetFiles(DatasetSample $sample): void
    {
        if (! is_array(config('filesystems.disks.'.$sample->disk))) {
            return;
        }

        $disk = Storage::disk($sample->disk);

        foreach ([$sample->path, $sample->clean_path] as $path) {
            if (filled($path) && ! str_contains((string) $path, '..') && $disk->exists($path)) {
                $disk->delete($path);
            }
        }
    }

    /** شمارنده‌های بالای صفحه (روی کل دیتاست، نه فقط نتیجهٔ پالایش). */
    private function totals(): array
    {
        return [
            'total' => DatasetSample::query()->count(),
            'generated' => DatasetSample::query()->where('source', 'generated')->count(),
            'uploaded' => DatasetSample::query()->where('source', 'uploaded')->count(),
            'verified' => DatasetSample::query()->where('is_verified', true)->count(),
        ];
    }

    /** پیام‌های فارسی اعتبارسنجی. */
    private static function messages(): array
    {
        return [
            'required' => 'انتخاب :attribute الزامی است.',
            'required_if' => 'برای این عملیات، انتخاب :attribute الزامی است.',
            'array' => 'مقدار :attribute معتبر نیست.',
            'min' => 'دست‌کم یک نمونه را انتخاب کنید.',
            'integer' => 'مقدار :attribute معتبر نیست.',
            'in' => 'مقدار انتخاب‌شده برای :attribute معتبر نیست.',
            'exists' => ':attribute انتخاب‌شده وجود ندارد.',
        ];
    }
}
