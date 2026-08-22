<?php

namespace App\Http\Controllers;

use App\Exceptions\EngineException;
use App\Models\DatasetAnnotation;
use App\Models\DatasetSample;
use App\Models\DatasetTag;
use App\Models\DocumentType;
use App\Models\ServiceType;
use App\Models\TestImage;
use App\Services\HanaEngine;
use App\Support\PersianValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ساخت «تصویر تستی» با دادهٔ دلخواه کاربر.
 *
 * فرق این بخش با «تولید انبوه» دیتاست: آن‌جا هزار تصویر با دادهٔ تصادفی
 * برای آموزش مدل ساخته می‌شود؛ این‌جا کارشناس یک تصویر با دادهٔ مشخص
 * می‌سازد تا رفتار OCR را روی همان داده ببیند. به‌همین دلیل دکمهٔ
 * «OCR بگیر» قلب این صفحه است، نه خودِ ساخت تصویر.
 */
class TestImageController extends Controller
{
    /** دیسک تصاویر تستی (خصوصی، زیر storage/app/private/testimages). */
    private const DISK = 'testimages';

    /** دیسک دیتاست، برای دکمهٔ «افزودن به دیتاست». */
    private const DATASET_DISK = 'dataset';

    /**
     * تعریف پنج اعوجاج — تنها منبع حقیقت فرم و کنترلر.
     *
     * key      : نامی که موتور می‌شناسد (hana_engine/render.py: AUG_ORDER)
     * param    : نام پارامتری که موتور برای شدت این اعوجاج می‌خواند
     * min/max  : بازهٔ اسلایدر (خواستهٔ تسک ۶۲۸)
     * why      : چرا این خرابی در دنیای واقعی پیش می‌آید
     */
    public const AUGMENTATIONS = [
        'rotation' => [
            'label' => 'چرخش',
            'icon' => '🔄',
            'param' => 'angle',
            'unit' => 'درجه',
            'min' => -25.0,
            'max' => 25.0,
            'step' => 1.0,
            'default' => 7.0,
            'decimals' => 0,
            'why' => 'مدرک کج روی اسکنر گذاشته شده یا با گوشی از زاویه عکس گرفته شده است.',
        ],
        'brightness' => [
            'label' => 'روشنایی',
            'icon' => '💡',
            'param' => 'value',
            'unit' => 'واحد',
            'min' => -80.0,
            'max' => 80.0,
            'step' => 5.0,
            'default' => -20.0,
            'decimals' => 0,
            'why' => 'اتاق کم‌نور (عدد منفی) یا فلاش مستقیم و سوختن تصویر (عدد مثبت).',
        ],
        'blur' => [
            'label' => 'تاری',
            'icon' => '🌫',
            'param' => 'kernel',
            'unit' => 'شدت',
            'min' => 1.0,
            'max' => 9.0,
            'step' => 2.0,
            'default' => 3.0,
            'decimals' => 0,
            'why' => 'لرزش دست یا قفل‌نشدن فوکوس دوربین هنگام عکس گرفتن.',
        ],
        'noise' => [
            'label' => 'نویز',
            'icon' => '📻',
            'param' => 'std',
            'unit' => 'انحراف معیار',
            'min' => 1.0,
            'max' => 40.0,
            'step' => 1.0,
            'default' => 8.0,
            'decimals' => 0,
            'why' => 'دوربین ارزان‌قیمت در نور کم، یا فشرده‌سازی شدید تصویر.',
        ],
        'shadow' => [
            'label' => 'سایه',
            'icon' => '🌑',
            'param' => 'alpha',
            'unit' => 'آلفا',
            'min' => 0.3,
            'max' => 0.9,
            'step' => 0.05,
            'default' => 0.75,
            'decimals' => 2,
            'why' => 'سایهٔ دست یا گوشی روی مدرک. عدد کمتر یعنی سایهٔ تیره‌تر.',
        ],
    ];

    public function __construct(private readonly HanaEngine $engine)
    {
    }

    // ==================================================================
    // فرم ساخت
    // ==================================================================

    public function create(Request $request): View
    {
        $types = $this->generatableTypes();

        $from = null;

        if ($request->filled('from')) {
            $candidate = TestImage::find($request->integer('from'));

            if ($candidate && $this->canSee($request, $candidate)) {
                $from = $candidate;
            }
        }

        return view('testimage.create', [
            'types' => $types,
            'augmentations' => self::AUGMENTATIONS,
            'state' => $this->formState($types, $from),
            'from' => $from,
        ]);
    }

    /**
     * پرکردن تصادفی — خروجی ژنراتور موتور را به‌صورت JSON به فرم می‌دهد.
     * هیچ دادهٔ هویتی واقعی در کار نیست؛ همه از app/person/person_generator.py.
     */
    public function random(): JsonResponse
    {
        try {
            $result = $this->engine->generatePerson(1);
        } catch (EngineException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ]);
        }

        $person = (array) ($result['person'] ?? []);

        // موتور full_name جدا نمی‌دهد؛ گواهینامه و کارت خودرو آن را می‌خواهند.
        $first = trim((string) ($person['first_name'] ?? ''));
        $last = trim((string) ($person['last_name'] ?? ''));

        if ($first !== '' && $last !== '') {
            $person['full_name'] = $first.' '.$last;
        }

        return response()->json(['ok' => true, 'person' => $person]);
    }

    // ==================================================================
    // ساخت تصویر
    // ==================================================================

    public function store(Request $request): RedirectResponse
    {
        $types = $this->generatableTypes();

        $type = $types->firstWhere('id', $request->integer('document_type_id'));

        if (! $type) {
            return back()
                ->withInput()
                ->withErrors(['document_type_id' => 'نوع مدرک انتخاب‌شده معتبر نیست یا قابل تولید تصویر نیست.']);
        }

        [$payload, $errors] = $this->collectPayload($request, $type);

        if ($errors !== []) {
            return back()->withInput()->withErrors($errors);
        }

        $augmentations = $this->collectAugmentations($request);

        $timezone = config('panel_menu.timezone', config('app.timezone'));
        $folder = now($timezone)->format('Y-m');
        $basename = 'ti_'.$request->user()->id.'_'.now($timezone)->format('YmdHis').'_'.Str::lower(Str::random(6));

        try {
            $result = $this->engine->renderDocument(
                documentType: $type->key,
                payload: $payload,
                augmentations: $augmentations,
                outDir: Storage::disk(self::DISK)->path($folder),
                basename: $basename,
            );
        } catch (EngineException $exception) {
            return back()
                ->withInput()
                ->withErrors(['engine' => 'ساخت تصویر ناموفق بود: '.$exception->getMessage()]);
        }

        $disk = Storage::disk(self::DISK);

        $cleanRelative = $folder.'/'.basename((string) $result['clean_path']);

        $augmentedRelative = filled($result['augmented_path'] ?? null)
            ? $folder.'/'.basename((string) $result['augmented_path'])
            : null;

        if (! $disk->exists($cleanRelative)) {
            return back()
                ->withInput()
                ->withErrors(['engine' => 'موتور فایلی برنگرداند. مسیر خروجی روی سرور قابل نوشتن نیست.']);
        }

        // کادر هر فیلد کنار خود تصویر ذخیره می‌شود تا «افزودن به دیتاست»
        // بدون ساخت دوبارهٔ تصویر، برچسب مکانی داشته باشد.
        $disk->put($folder.'/'.$basename.'.json', json_encode([
            'fields' => $result['fields'] ?? [],
            'fields_augmented' => $result['fields_augmented'] ?? null,
            'applied' => $result['applied'] ?? [],
            'missing_fields' => $result['missing_fields'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $testImage = TestImage::create([
            'user_id' => $request->user()->id,
            'document_type_id' => $type->id,
            'payload' => $payload,
            'augmentations' => $augmentations,
            'disk' => self::DISK,
            'path' => $augmentedRelative ?? $cleanRelative,
            'clean_path' => $cleanRelative,
            'width' => $result['width'] ?? null,
            'height' => $result['height'] ?? null,
        ]);

        return redirect()
            ->route('testimage.show', $testImage)
            ->with('success', 'تصویر تستی ساخته شد. حالا با دکمهٔ «OCR بگیر» ببینید موتور چقدر از آن را درست می‌خواند.');
    }

    // ==================================================================
    // نمایش
    // ==================================================================

    public function show(Request $request, TestImage $testImage): View
    {
        abort_unless($this->canSee($request, $testImage), 403, 'این تصویر تستی متعلق به کاربر دیگری است.');

        $testImage->load(['documentType.fields', 'user']);

        $sidecar = $this->readSidecar($testImage);

        $ocr = session('ocr');
        $ocr = is_array($ocr) && (int) ($ocr['test_image_id'] ?? 0) === $testImage->id ? $ocr : null;

        return view('testimage.show', [
            'image' => $testImage,
            'rows' => $this->payloadRows($testImage, $sidecar),
            'applied' => $this->appliedList($testImage),
            'augmentations' => self::AUGMENTATIONS,
            'sidecar' => $sidecar,
            'ocr' => $ocr,
            'inDataset' => DatasetSample::where('path', 'generated/'.$testImage->path)->exists(),
            // خدمت‌هایی که این نوع مدرک را لازم دارند — ورودی دکمهٔ «بفرست به
            // فرایند بررسی» (تسک ۶۲۷). برای کارشناس داده خالی می‌ماند چون او
            // اصلاً پرونده نمی‌سازد و دکمه هم برایش نمایش داده نمی‌شود.
            'caseServices' => $this->caseServices($request, $testImage),
        ]);
    }

    /**
     * خدمت‌های فعالی که این نوع مدرک جزو مدارک لازمشان است.
     *
     * @return \Illuminate\Support\Collection<int, ServiceType>
     */
    private function caseServices(Request $request, TestImage $testImage): \Illuminate\Support\Collection
    {
        if (! $request->user()->canReviewCases() || $testImage->document_type_id === null) {
            return collect();
        }

        return ServiceType::query()
            ->active()
            ->whereHas('documentTypes', fn ($query) => $query->where('document_types.id', $testImage->document_type_id))
            ->orderBy('sort')
            ->get();
    }

    /**
     * اجرای OCR روی همین تصویر و بررسی فیلد‌به‌فیلد.
     *
     * این کار حدود نیم تا سه ثانیه طول می‌کشد (تک تصویر)، پس همین‌جا
     * هم‌زمان انجام می‌شود و به صف نمی‌رود؛ صف برای تولید انبوه است.
     *
     * عمداً فقط مقیاس مرجع خوانده می‌شود، نه سه مقیاسِ پیش‌فرض تسک ۶۶۲:
     * تصویر تستی همیشه دقیقاً در اندازهٔ قالب ساخته می‌شود، پس مقیاس‌های
     * دیگر چیز تازه‌ای نمی‌گویند و فقط زمان همین درخواستِ هم‌زمان را سه
     * برابر می‌کنند. سؤالی که این صفحه جواب می‌دهد «آیا قالب در رزولوشن
     * مرجع خوانا چاپ شده؟» است، و همان مقیاس ۱.۰ است.
     */
    public function ocr(Request $request, TestImage $testImage): RedirectResponse
    {
        abort_unless($this->canSee($request, $testImage), 403, 'این تصویر تستی متعلق به کاربر دیگری است.');

        $testImage->load('documentType.fields');

        $disk = Storage::disk($testImage->disk);

        if (! $disk->exists($testImage->path)) {
            return back()->withErrors(['ocr' => 'فایل این تصویر روی سرور پیدا نشد؛ شاید پاک شده است.']);
        }

        $timezone = config('panel_menu.timezone', config('app.timezone'));

        try {
            $result = $this->engine->ocrDocument(
                path: $disk->path($testImage->path),
                documentType: $testImage->documentType?->key,
                preprocess: true,
                outDir: Storage::disk(self::DISK)->path('_preprocessed/'.now($timezone)->format('Y-m')),
                scales: [1.0],
            );
        } catch (EngineException $exception) {
            return back()->withErrors(['ocr' => 'اجرای OCR ناموفق بود: '.$exception->getMessage()]);
        }

        $rawText = (string) ($result['raw_text'] ?? '');

        $checks = [];
        $found = 0;

        // فیلدی که چیدمان موتور جای چاپش را ندارد (مثل «تاریخ انقضا» گواهینامه)
        // نه سنجیده می‌شود نه در مخرج نمره می‌آید؛ وگرنه نمره الکی پایین می‌آید.
        // خودِ صفحه از روی rows می‌گوید کدام فیلد چاپ نشده است.
        foreach ($this->payloadRows($testImage, $this->readSidecar($testImage)) as $row) {
            if (! $row['printed']) {
                continue;
            }

            $hit = PersianValue::foundInText($row['value'], $rawText);
            $found += $hit ? 1 : 0;

            $checks[] = [
                'key' => $row['key'],
                'label' => $row['label'],
                'value' => $row['value'],
                'found' => $hit,
            ];
        }

        $preprocessed = null;
        $absolutePre = (string) ($result['preprocessed_path'] ?? '');

        if ($absolutePre !== '') {
            $root = Storage::disk(self::DISK)->path('');
            $preprocessed = str_starts_with($absolutePre, $root)
                ? ltrim(substr($absolutePre, strlen($root)), '/')
                : null;
        }

        return back()->with('ocr', [
            'test_image_id' => $testImage->id,
            'raw_text' => $rawText,
            'char_count' => (int) ($result['char_count'] ?? 0),
            'line_count' => (int) ($result['line_count'] ?? 0),
            'duration_ms' => (int) ($result['duration_ms'] ?? 0),
            'extra' => (array) ($result['extra'] ?? []),
            'preprocessed' => $preprocessed,
            'checks' => $checks,
            'found' => $found,
            'total' => count($checks),
        ]);
    }

    public function download(Request $request, TestImage $testImage): StreamedResponse
    {
        abort_unless($this->canSee($request, $testImage), 403, 'این تصویر تستی متعلق به کاربر دیگری است.');

        $disk = Storage::disk($testImage->disk);

        $clean = $request->boolean('clean');
        $path = $clean && filled($testImage->clean_path) ? $testImage->clean_path : $testImage->path;

        abort_unless($disk->exists($path), 404, 'فایل این تصویر روی سرور پیدا نشد.');

        $name = ($testImage->documentType?->key ?? 'document').'_'.$testImage->id.($clean ? '_clean' : '').'.png';

        return $disk->download($path, $name);
    }

    /**
     * افزودن این تصویر به دیتاست آموزش، همراه با برچسب و کادر هر فیلد.
     * فقط نقش‌های داده — کارشناس بررسی به دیتاست دست نمی‌زند.
     */
    public function toDataset(Request $request, TestImage $testImage): RedirectResponse
    {
        abort_unless($this->canSee($request, $testImage), 403, 'این تصویر تستی متعلق به کاربر دیگری است.');
        abort_unless($request->user()->canManageDataset(), 403, 'افزودن به دیتاست فقط برای مدیر سامانه و کارشناس داده باز است.');

        $source = Storage::disk($testImage->disk);

        if (! $source->exists($testImage->path)) {
            return back()->withErrors(['dataset' => 'فایل این تصویر روی سرور پیدا نشد؛ نمی‌توان به دیتاست افزود.']);
        }

        $target = Storage::disk(self::DATASET_DISK);

        $relative = 'generated/'.$testImage->path;

        if (DatasetSample::where('path', $relative)->exists()) {
            return back()->with('warning', 'این تصویر قبلاً به دیتاست اضافه شده است.');
        }

        $target->put($relative, $source->get($testImage->path));

        $cleanRelative = null;

        if (filled($testImage->clean_path) && $source->exists($testImage->clean_path)) {
            $cleanRelative = 'generated/'.$testImage->clean_path;
            $target->put($cleanRelative, $source->get($testImage->clean_path));
        }

        $applied = $this->appliedList($testImage);
        $names = array_column($applied, 'key');

        $sample = DatasetSample::create([
            'document_type_id' => $testImage->document_type_id,
            'created_by' => $request->user()->id,
            'source' => 'generated',
            'disk' => self::DATASET_DISK,
            'path' => $relative,
            'clean_path' => $cleanRelative,
            'original_name' => 'تصویر تستی #'.$testImage->id,
            'width' => $testImage->width,
            'height' => $testImage->height,
            'augmentation' => match (count($names)) {
                0 => 'none',
                1 => $names[0],
                default => 'mixed',
            },
            'augmentation_params' => $testImage->augmentations ?: null,
            'generation_payload' => $testImage->payload,
            'split' => 'train',
            'is_verified' => false,
            'notes' => 'از بخش تصویر تستی افزوده شد.',
        ]);

        $this->copyAnnotations($testImage, $sample, $request->user()->id);
        $this->attachTags($sample, $names);

        return back()->with('success', 'به دیتاست افزوده شد (نمونهٔ شمارهٔ '
            .PersianValue::toPersianDigits((string) $sample->id).') همراه با کادر هر فیلد.');
    }

    // ==================================================================
    // فهرست و حذف
    // ==================================================================

    public function index(Request $request): View
    {
        $query = TestImage::query()
            ->with(['documentType', 'user'])
            ->latest('id');

        $mine = ! $request->user()->isAdmin() || ! $request->boolean('all');

        if ($mine) {
            $query->where('user_id', $request->user()->id);
        }

        $images = $query->paginate(12)->withQueryString();

        $applied = [];

        foreach ($images as $image) {
            $applied[$image->id] = $this->appliedList($image);
        }

        return view('testimage.index', [
            'images' => $images,
            'applied' => $applied,
            'mine' => $mine,
            'canSeeAll' => $request->user()->isAdmin(),
        ]);
    }

    public function destroy(Request $request, TestImage $testImage): RedirectResponse
    {
        abort_unless($this->canSee($request, $testImage), 403, 'این تصویر تستی متعلق به کاربر دیگری است.');

        $disk = Storage::disk($testImage->disk);

        foreach (array_filter([$testImage->path, $testImage->clean_path]) as $path) {
            $disk->delete($path);
        }

        $disk->delete($this->sidecarPath($testImage));

        // تصویر پیش‌پردازش‌شدهٔ OCR هم برود، وگرنه روی دیسک جا می‌ماند.
        // ماهِ پوشهٔ پیش‌پردازش می‌تواند با ماه خود تصویر فرق کند (OCR بعداً گرفته شده)،
        // پس همهٔ ماه‌ها گشته می‌شود.
        $stem = pathinfo($testImage->path, PATHINFO_FILENAME);

        foreach ($disk->directories('_preprocessed') as $monthDirectory) {
            $disk->delete($monthDirectory.'/'.$stem.'_pre.png');
        }

        $testImage->delete();

        return redirect()
            ->route('testimage.index')
            ->with('success', 'تصویر تستی حذف شد.');
    }

    // ==================================================================
    // کمکی‌ها
    // ==================================================================

    /** انواع مدرکی که موتور می‌تواند تصویرشان را بسازد. */
    private function generatableTypes()
    {
        return DocumentType::query()
            ->where('is_active', true)
            ->where('is_generatable', true)
            ->with('fields')
            ->orderBy('sort')
            ->get();
    }

    private function canSee(Request $request, TestImage $testImage): bool
    {
        return $request->user()->isAdmin() || $testImage->user_id === $request->user()->id;
    }

    /**
     * وضعیت اولیهٔ فرم: کدام نوع مدرک انتخاب باشد، هر فیلد چه مقداری داشته
     * باشد و اسلایدرهای اعوجاج کجا بایستند.
     *
     * ترتیب اولویت: ورودی قبلی (old) ← تصویری که «با اعوجاج دیگر» ساخته
     * می‌شود ← پیش‌فرض‌ها.
     */
    private function formState($types, ?TestImage $from): array
    {
        $selected = (int) old('document_type_id', $from?->document_type_id ?? ($types->first()->id ?? 0));

        $values = [];

        foreach ($types as $type) {
            foreach ($type->fields as $field) {
                $values[$type->key][$field->key] = (string) old("fields.{$type->key}.{$field->key}", '');
            }
        }

        if ($from && ! old('fields')) {
            $key = $from->documentType?->key;

            if ($key && isset($values[$key])) {
                foreach ((array) $from->payload as $fieldKey => $value) {
                    if (array_key_exists($fieldKey, $values[$key])) {
                        $values[$key][$fieldKey] = (string) $value;
                    }
                }
            }
        }

        $aug = [];
        $oldAug = old('aug');
        $fromAug = $from?->augmentations ?? [];

        foreach (self::AUGMENTATIONS as $name => $spec) {
            if (is_array($oldAug)) {
                $enabled = (bool) ($oldAug[$name]['enabled'] ?? false);
                $value = (float) ($oldAug[$name]['value'] ?? $spec['default']);
            } elseif ($from) {
                $enabled = (bool) ($fromAug[$name]['enabled'] ?? false);
                $value = (float) ($fromAug[$name][$spec['param']] ?? $spec['default']);
            } else {
                $enabled = false;
                $value = (float) $spec['default'];
            }

            $aug[$name] = [
                'enabled' => $enabled,
                'value' => $this->clampToStep($value, $spec),
            ];
        }

        return [
            'selected' => $selected,
            'values' => $values,
            'aug' => $aug,
        ];
    }

    /**
     * خواندن و اعتبارسنجی فیلدهای نوع مدرک انتخاب‌شده.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function collectPayload(Request $request, DocumentType $type): array
    {
        $raw = $request->input("fields.{$type->key}");
        $raw = is_array($raw) ? $raw : [];

        $payload = [];
        $errors = [];

        foreach ($type->fields as $field) {
            $key = "fields.{$type->key}.{$field->key}";

            $input = $raw[$field->key] ?? null;
            $input = is_scalar($input) ? (string) $input : '';

            // شکل قانونی مقدار: ارقام انگلیسی فارسی می‌شوند و جداکننده‌های
            // خوانایی («۰۰۶-۹۵۳-۷۴۱۰») حذف می‌شوند. همین یک مقدار هم
            // اعتبارسنجی می‌شود، هم چاپ، هم به‌عنوان برچسب ذخیره — یک منبع حقیقت.
            $value = PersianValue::forEngine($field->value_type, $input);

            if ($value === '') {
                if ($field->is_required) {
                    $errors[$key] = "«{$field->label_fa}» الزامی است و خالی مانده.";
                }

                continue;
            }

            // validate خودش هم از همان forEngine می‌گذرد (خودتکرار است)
            $problem = PersianValue::validate($field->value_type, $value, $field->label_fa);

            if ($problem !== null) {
                $errors[$key] = $problem;

                continue;
            }

            $payload[$field->key] = $value;
        }

        return [$payload, $errors];
    }

    /**
     * ساخت آرایهٔ اعوجاج به همان شکلی که موتور می‌خواهد.
     *
     * @return array<string, array<string, mixed>>
     */
    private function collectAugmentations(Request $request): array
    {
        $input = $request->input('aug');
        $input = is_array($input) ? $input : [];

        $result = [];

        foreach (self::AUGMENTATIONS as $name => $spec) {
            $enabled = (bool) ($input[$name]['enabled'] ?? false);

            $value = $this->clampToStep(
                (float) ($input[$name]['value'] ?? $spec['default']),
                $spec,
            );

            $result[$name] = [
                'enabled' => $enabled,
                $spec['param'] => $spec['decimals'] > 0 ? $value : (int) round($value),
            ];
        }

        return $result;
    }

    /** مقدار اسلایدر را داخل بازه و روی گام درست می‌نشاند. */
    private function clampToStep(float $value, array $spec): float
    {
        $min = (float) $spec['min'];
        $max = (float) $spec['max'];
        $step = (float) $spec['step'];

        $value = max($min, min($max, $value));

        if ($step > 0) {
            $value = $min + round(($value - $min) / $step) * $step;
            $value = max($min, min($max, $value));
        }

        return round($value, (int) $spec['decimals']);
    }

    /**
     * اعوجاج‌هایی که واقعاً روی این تصویر اعمال شده‌اند، آمادهٔ نمایش.
     *
     * @return list<array{key: string, label: string, icon: string, value: string, unit: string}>
     */
    private function appliedList(TestImage $testImage): array
    {
        $stored = $testImage->augmentations;
        $stored = is_array($stored) ? $stored : [];

        $out = [];

        foreach (self::AUGMENTATIONS as $name => $spec) {
            if (! ($stored[$name]['enabled'] ?? false)) {
                continue;
            }

            $value = $stored[$name][$spec['param']] ?? $spec['default'];

            $out[] = [
                'key' => $name,
                'label' => $spec['label'],
                'icon' => $spec['icon'],
                'unit' => $spec['unit'],
                'value' => PersianValue::decimal((float) $value, (int) $spec['decimals']),
            ];
        }

        return $out;
    }

    /**
     * جدول «چه چیزی روی تصویر چاپ شد» — ترتیب و برچسب از خود نوع مدرک.
     *
     * پرچم printed می‌گوید آیا موتور واقعاً این فیلد را روی مدرک کشیده است.
     * نوع مدرک در پنل می‌تواند فیلدی داشته باشد که چیدمان موتور جایی برایش
     * ندارد (امروز: «تاریخ انقضا»ی گواهینامه، چون hana_engine/layouts.py آن را
     * نمی‌شناسد). چنین فیلدی نه چاپ می‌شود نه OCR می‌تواند پیدایش کند، پس
     * نباید در جدول به‌عنوان «چاپ‌شده» جا بزند.
     *
     * منبع حقیقتِ «چه چیزی کشیده شد» فایل کنارِ تصویر است. اگر آن فایل نباشد
     * (تصویر قدیمی) چیزی ادعا نمی‌کنیم و همه چاپ‌شده فرض می‌شوند.
     *
     * @param  array<string, mixed>|null  $sidecar
     * @return list<array{key: string, label: string, value: string, type: string, printed: bool}>
     */
    private function payloadRows(TestImage $testImage, ?array $sidecar = null): array
    {
        $payload = is_array($testImage->payload) ? $testImage->payload : [];

        $drawn = is_array($sidecar['fields'] ?? null) ? $sidecar['fields'] : null;

        $printed = static fn (string $key): bool => $drawn === null || array_key_exists($key, $drawn);

        $rows = [];
        $seen = [];

        foreach (($testImage->documentType?->fields ?? []) as $field) {
            if (! array_key_exists($field->key, $payload)) {
                continue;
            }

            $seen[$field->key] = true;

            $rows[] = [
                'key' => $field->key,
                'label' => $field->label_fa,
                'value' => (string) $payload[$field->key],
                'type' => $field->value_type,
                'printed' => $printed($field->key),
            ];
        }

        foreach ($payload as $key => $value) {
            if (! isset($seen[$key])) {
                $rows[] = [
                    'key' => (string) $key,
                    'label' => (string) $key,
                    'value' => (string) $value,
                    'type' => 'text',
                    'printed' => $printed((string) $key),
                ];
            }
        }

        return $rows;
    }

    private function sidecarPath(TestImage $testImage): string
    {
        $directory = trim(dirname($testImage->clean_path ?: $testImage->path), '.');
        $base = pathinfo($testImage->clean_path ?: $testImage->path, PATHINFO_FILENAME);

        return ($directory !== '' ? $directory.'/' : '').$base.'.json';
    }

    /** @return array<string, mixed>|null */
    private function readSidecar(TestImage $testImage): ?array
    {
        $disk = Storage::disk($testImage->disk);
        $path = $this->sidecarPath($testImage);

        if (! $disk->exists($path)) {
            return null;
        }

        $decoded = json_decode((string) $disk->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** کپی کادرهای موتور به‌عنوان برچسب دیتاست. */
    private function copyAnnotations(TestImage $testImage, DatasetSample $sample, int $userId): void
    {
        $sidecar = $this->readSidecar($testImage);

        if ($sidecar === null) {
            return;
        }

        $clean = is_array($sidecar['fields'] ?? null) ? $sidecar['fields'] : [];

        // اگر تصویر اعوجاج دارد، کادرِ درست همان کادر چرخیده است
        $augmented = is_array($sidecar['fields_augmented'] ?? null) ? $sidecar['fields_augmented'] : [];
        $useAugmented = $testImage->path !== $testImage->clean_path && $augmented !== [];

        foreach ($clean as $fieldKey => $info) {
            $box = $useAugmented && isset($augmented[$fieldKey]['norm'])
                ? $augmented[$fieldKey]['norm']
                : ($info['norm'] ?? null);

            DatasetAnnotation::create([
                'dataset_sample_id' => $sample->id,
                'field_key' => (string) $fieldKey,
                'value' => (string) ($info['text'] ?? ''),
                'bbox_x' => is_array($box) ? (float) ($box['x'] ?? 0) : null,
                'bbox_y' => is_array($box) ? (float) ($box['y'] ?? 0) : null,
                'bbox_w' => is_array($box) ? (float) ($box['w'] ?? 0) : null,
                'bbox_h' => is_array($box) ? (float) ($box['h'] ?? 0) : null,
                'source' => 'generated',
                'created_by' => $userId,
            ]);
        }
    }

    /** برچسب‌زدن نمونه بر پایهٔ اعوجاج‌هایی که خورده است. */
    private function attachTags(DatasetSample $sample, array $names): void
    {
        $map = [
            'rotation' => 'چرخیده',
            'shadow' => 'سایه‌دار',
            'brightness' => 'نور نامناسب',
            'blur' => 'کیفیت پایین',
            'noise' => 'کیفیت پایین',
        ];

        $wanted = array_values(array_unique(array_filter(
            array_map(static fn (string $name): ?string => $map[$name] ?? null, $names),
        )));

        if ($wanted === []) {
            return;
        }

        $ids = DatasetTag::whereIn('name', $wanted)->pluck('id')->all();

        if ($ids !== []) {
            $sample->tags()->syncWithoutDetaching($ids);
        }
    }
}
