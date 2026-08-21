<?php

namespace App\Http\Controllers\Dataset;

use App\Http\Controllers\Controller;
use App\Models\DatasetAnnotation;
use App\Models\DatasetSample;
use App\Models\DocumentType;
use App\Models\DocumentTypeField;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * ابزار تگ‌گذاری تصویری دیتاست.
 *
 * سه صفحه:
 *   index  — صف نمونه‌هایی که هنوز برچسب کامل یا تایید ندارند
 *   edit   — بوم تصویر + فهرست فیلدها (کشیدن کادر و تایپ مقدار)
 *   update — ذخیرهٔ برچسب‌ها (هم از فرم پنل و هم به‌صورت JSON)
 *
 * قرارداد کادر: مختصات نسبی بین ۰ و ۱ نسبت به ابعاد همان تصویر ذخیره
 * می‌شود، نه پیکسل. بنابراین با هر بزرگ‌نمایی و هر اندازهٔ پنجره درست
 * رسم می‌شود و با تغییر ابعاد تصویر هم بی‌اعتبار نمی‌شود.
 */
class AnnotateController extends Controller
{
    /** حالت‌های پالایش صف. */
    private const STATUSES = [
        'pending' => 'در انتظار کار (پیش‌فرض)',
        'unlabeled' => 'بدون هیچ برچسب',
        'incomplete' => 'برچسب همهٔ فیلدها کامل نیست',
        'done' => 'کامل و تاییدشده',
        'all' => 'همه',
    ];

    /**
     * فیلدهای ترکیبی — آینهٔ «compose» در hana_engine/layouts.py.
     * برای دکمهٔ «پر کردن از دادهٔ تولید» لازم است، چون دادهٔ تولید
     * first_name و last_name دارد ولی مدرک، فیلد full_name.
     *
     * @var array<string, array<int, string>>
     */
    private const COMPOSE = [
        'full_name' => ['first_name', 'last_name'],
    ];

    /** رواداری خطای ممیز شناور هنگام بررسی «کادر داخل تصویر بماند». */
    private const EPSILON = 0.0005;

    /** کوچک‌ترین ضلع پذیرفته‌شده برای یک کادر (۰٫۳٪ از ضلع تصویر). */
    private const MIN_SIDE = 0.003;

    /** بیشترین طول متن یک فیلد. */
    private const MAX_VALUE = 500;

    // ==================================================================
    // صف تگ‌گذاری
    // ==================================================================

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'pending');

        if (! array_key_exists($status, self::STATUSES)) {
            $status = 'pending';
        }

        $type = trim((string) $request->query('type', ''));
        $source = trim((string) $request->query('source', ''));
        $search = trim((string) $request->query('q', ''));

        $types = DocumentType::query()->orderBy('sort')->orderBy('id')->get();

        if ($type !== '' && ! $types->contains('key', $type)) {
            $type = '';
        }

        if (! in_array($source, ['generated', 'uploaded'], true)) {
            $source = '';
        }

        /** @var LengthAwarePaginator $samples */
        $samples = $this->queue($status, $type, $source, $search)
            ->paginate(15)
            ->withQueryString();

        $fieldCounts = $this->fieldCounts();

        return view('dataset.annotate.index', [
            'samples' => $samples,
            'fieldCounts' => $fieldCounts,
            'types' => $types,
            'statuses' => self::STATUSES,
            'status' => $status,
            'type' => $type,
            'source' => $source,
            'q' => $search,
            'counters' => [
                'pending' => $this->queue('pending')->count(),
                'unlabeled' => $this->queue('unlabeled')->count(),
                'uploaded' => $this->queue('pending')->where('dataset_samples.source', 'uploaded')->count(),
                'done' => $this->queue('done')->count(),
            ],
        ]);
    }

    // ==================================================================
    // صفحهٔ تگ‌گذاری یک نمونه
    // ==================================================================

    public function edit(Request $request, DatasetSample $sample): View
    {
        $sample->load(['documentType.fields', 'annotations']);

        abort_if($sample->documentType === null, 404, 'نوع مدرک این نمونه پیدا نشد.');

        $byKey = $sample->annotations->keyBy('field_key');
        $definedKeys = $sample->documentType->fields->pluck('key')->all();

        $fields = $sample->documentType->fields->map(function (DocumentTypeField $field) use ($byKey) {
            $annotation = $byKey->get($field->key);

            return [
                'key' => $field->key,
                'label' => $field->label_fa,
                'value_type' => $field->value_type,
                'required' => (bool) $field->is_required,
                'cross_checked' => (bool) $field->is_cross_checked,
                'value' => (string) ($annotation->value ?? ''),
                'bbox' => $this->boxOf($annotation),
                'source' => $annotation->source ?? null,
            ];
        })->values();

        // برچسب‌های یتیم: کلیدی که دیگر در تعریف این نوع مدرک نیست.
        $orphans = $sample->annotations
            ->reject(fn (DatasetAnnotation $a) => in_array($a->field_key, $definedKeys, true))
            ->values();

        $disk = (string) $sample->disk;
        $servable = in_array($disk, ['dataset', 'documents', 'testimages'], true);
        $exists = $servable && $sample->path !== null && Storage::disk($disk)->exists($sample->path);

        return view('dataset.annotate.edit', [
            'sample' => $sample,
            'fields' => $fields,
            'orphans' => $orphans,
            'prefill' => $this->prefill($sample, $definedKeys),
            'imageUrl' => $exists ? route('media', ['disk' => $disk, 'path' => $sample->path]) : null,
            'imageMissing' => ! $exists,
            'next' => $this->nextInQueue($sample),
            'remaining' => $this->queue('pending')->count(),
            'filledCount' => $fields->filter(fn ($f) => $f['value'] !== '')->count(),
            'boxedCount' => $fields->filter(fn ($f) => $f['bbox'] !== null)->count(),
        ]);
    }

    // ==================================================================
    // ذخیرهٔ برچسب‌ها
    // ==================================================================

    /**
     * ورودی پذیرفته‌شده (هر دو شکل):
     *   ۱) فرم پنل: payload = رشتهٔ JSON آرایهٔ فیلدها
     *   ۲) درخواست JSON: {"fields": [ {field_key, value, bbox|null} ], "action": "save|next"}
     */
    public function update(Request $request, DatasetSample $sample): RedirectResponse|JsonResponse
    {
        $sample->load(['documentType.fields']);

        abort_if($sample->documentType === null, 404, 'نوع مدرک این نمونه پیدا نشد.');

        $allowed = $sample->documentType->fields->pluck('key')->all();
        $labels = $sample->documentType->fields->pluck('label_fa', 'key')->all();

        $rows = $this->readRows($request);
        $clean = $this->validateRows($rows, $allowed, $labels);

        // اگر کلید verify اصلاً در درخواست نباشد (مثلاً یک فراخوانی JSON ساده)،
        // وضعیت تایید نمونه دست‌نخورده می‌ماند. فرم پنل همیشه آن را می‌فرستد.
        $wantsVerify = $request->has('verify')
            ? $request->boolean('verify')
            : (bool) $sample->is_verified;

        if ($wantsVerify) {
            // وضعیت نهایی = آنچه از قبل ذخیره شده + آنچه همین حالا فرستاده شده.
            // یک درخواست ناقص (مثلاً اصلاح دو فیلد از راه JSON) نباید فیلدهای
            // دست‌نخوردهٔ دیتابیس را «خالی» فرض کند.
            $effective = $sample->annotations()->pluck('value', 'field_key')->all();

            foreach ($clean as $key => $row) {
                $effective[$key] = $row['value'];
            }

            $missing = $sample->documentType->fields
                ->filter(fn (DocumentTypeField $f) => $f->is_required)
                ->reject(fn (DocumentTypeField $f) => trim((string) ($effective[$f->key] ?? '')) !== '')
                ->pluck('label_fa')
                ->all();

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'verify' => 'برای «تایید نهایی» باید مقدار همهٔ فیلدهای الزامی پر باشد. خالی‌ها: '.implode('، ', $missing),
                ]);
            }
        }

        $userId = (int) $request->user()->id;
        $saved = 0;
        $removed = 0;
        $boxes = 0;

        DB::transaction(function () use ($sample, $clean, $userId, $wantsVerify, &$saved, &$removed, &$boxes) {
            foreach ($clean as $key => $row) {
                $isEmpty = $row['value'] === '' && $row['bbox'] === null;

                if ($isEmpty) {
                    $removed += DatasetAnnotation::query()
                        ->where('dataset_sample_id', $sample->id)
                        ->where('field_key', $key)
                        ->delete();

                    continue;
                }

                DatasetAnnotation::updateOrCreate(
                    ['dataset_sample_id' => $sample->id, 'field_key' => $key],
                    [
                        'value' => $row['value'] === '' ? null : $row['value'],
                        'bbox_x' => $row['bbox']['x'] ?? null,
                        'bbox_y' => $row['bbox']['y'] ?? null,
                        'bbox_w' => $row['bbox']['w'] ?? null,
                        'bbox_h' => $row['bbox']['h'] ?? null,
                        'source' => 'manual',
                        'created_by' => $userId,
                    ],
                );

                $saved++;

                if ($row['bbox'] !== null) {
                    $boxes++;
                }
            }

            $sample->is_verified = $wantsVerify;
            $sample->verified_by = $wantsVerify ? $userId : null;
            $sample->verified_at = $wantsVerify ? now() : null;
            $sample->save();
        });

        $message = 'برچسب‌های نمونهٔ #'.$this->fa($sample->id).' ذخیره شد — '
            .$this->fa($saved).' فیلد ('.$this->fa($boxes).' کادر)'
            .($removed > 0 ? ' و '.$this->fa($removed).' فیلد خالی حذف شد' : '')
            .($wantsVerify ? ' و نمونه «تاییدشده» علامت خورد.' : '.');

        $action = (string) $request->input('action', 'save');
        $next = $action === 'next' ? $this->nextInQueue($sample) : null;

        if ($action === 'next' && $next === null) {
            $target = route('dataset.annotate.index');
            $extra = 'نمونهٔ بعدی‌ای در صف نمانده است.';
        } elseif ($next !== null) {
            $target = route('dataset.annotate.edit', $next);
            $extra = null;
        } else {
            $target = route('dataset.annotate.edit', $sample);
            $extra = null;
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'saved' => $saved,
                'removed' => $removed,
                'boxes' => $boxes,
                'is_verified' => $sample->is_verified,
                'redirect' => $target,
            ]);
        }

        session()->flash('success', $extra === null ? $message : [$message, $extra]);

        return redirect()->to($target);
    }

    // ==================================================================
    // کمکی‌ها
    // ==================================================================

    /**
     * سازندهٔ کوئری صف.
     *
     * «در انتظار کار» یعنی یا هنوز تایید نشده، یا تعداد برچسب‌هایش از
     * تعداد فیلدهای آن نوع مدرک کمتر است. اولویت نمایش با نمونه‌های
     * آپلودی است چون اصلاً برچسبی ندارند.
     */
    private function queue(string $status, string $type = '', string $source = '', string $search = ''): Builder
    {
        // تعداد برچسب‌های همین نمونه
        $annotations = '(select count(*) from `dataset_annotations` `da`'
            .' where `da`.`dataset_sample_id` = `dataset_samples`.`id`)';

        // تعداد کل فیلدهای تعریف‌شدهٔ نوع مدرک همین نمونه
        $fields = '(select count(*) from `document_type_fields` `df`'
            .' where `df`.`document_type_id` = `dataset_samples`.`document_type_id`)';

        // تعداد فیلدهای «الزامی»ای که هنوز مقدار ندارند.
        // معیار «کار تمام‌شده» همین است، نه شمارش خام برچسب‌ها: یک فیلد
        // اختیاری که روی خود مدرک وجود ندارد، نباید نمونه را برای همیشه
        // در صف نگه دارد.
        $missingRequired = '(select count(*) from `document_type_fields` `dfr`'
            .' where `dfr`.`document_type_id` = `dataset_samples`.`document_type_id`'
            .' and `dfr`.`is_required` = 1'
            .' and not exists (select 1 from `dataset_annotations` `dar`'
            ."     where `dar`.`dataset_sample_id` = `dataset_samples`.`id`"
            ."     and `dar`.`field_key` = `dfr`.`key`"
            ."     and `dar`.`value` is not null and `dar`.`value` <> ''))";

        $query = DatasetSample::query()
            ->with('documentType')
            ->withCount('annotations');

        match ($status) {
            'unlabeled' => $query->whereRaw($annotations.' = 0'),
            'incomplete' => $query->whereRaw($annotations.' < '.$fields),
            'done' => $query->where('is_verified', true)->whereRaw($missingRequired.' = 0'),
            'all' => null,
            default => $query->where(function (Builder $inner) use ($missingRequired) {
                $inner->where('is_verified', false)
                    ->orWhereRaw($missingRequired.' > 0');
            }),
        };

        if ($type !== '') {
            $query->whereHas('documentType', fn (Builder $inner) => $inner->where('key', $type));
        }

        if ($source !== '') {
            $query->where('dataset_samples.source', $source);
        }

        if ($search !== '') {
            $query->where(function (Builder $inner) use ($search) {
                $inner->where('original_name', 'like', '%'.$search.'%')
                    ->orWhere('path', 'like', '%'.$search.'%')
                    ->orWhere('notes', 'like', '%'.$search.'%');

                if (ctype_digit($search)) {
                    $inner->orWhere('id', (int) $search);
                }
            });
        }

        return $query
            ->orderByRaw("case when `dataset_samples`.`source` = 'uploaded' then 0 else 1 end")
            ->orderByRaw($annotations.' asc')
            ->orderBy('dataset_samples.id');
    }

    /** نمونهٔ بعدی صف پس از نمونهٔ جاری (برای دکمهٔ «ذخیره و بعدی»). */
    private function nextInQueue(DatasetSample $sample): ?DatasetSample
    {
        return $this->queue('pending')
            ->whereKeyNot($sample->getKey())
            ->first();
    }

    /** تعداد فیلد هر نوع مدرک: [document_type_id => تعداد]. */
    private function fieldCounts(): array
    {
        return DocumentTypeField::query()
            ->selectRaw('document_type_id, count(*) as total')
            ->groupBy('document_type_id')
            ->pluck('total', 'document_type_id')
            ->all();
    }

    /** @return array{x: float, y: float, w: float, h: float}|null */
    private function boxOf(?DatasetAnnotation $annotation): ?array
    {
        if ($annotation === null || $annotation->bbox_w === null || $annotation->bbox_h === null) {
            return null;
        }

        return [
            'x' => (float) $annotation->bbox_x,
            'y' => (float) $annotation->bbox_y,
            'w' => (float) $annotation->bbox_w,
            'h' => (float) $annotation->bbox_h,
        ];
    }

    /**
     * مقدارهای پیشنهادی از دادهٔ تولید نمونه (فقط مقدار، بدون کادر).
     *
     * @param  array<int, string>  $keys
     * @return array<string, string>
     */
    private function prefill(DatasetSample $sample, array $keys): array
    {
        $payload = $sample->generation_payload;

        if (! is_array($payload) || $payload === []) {
            return [];
        }

        $out = [];

        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) || is_numeric($value)) {
                $value = trim((string) $value);

                if ($value !== '') {
                    $out[$key] = $value;

                    continue;
                }
            }

            $parts = [];

            foreach (self::COMPOSE[$key] ?? [] as $part) {
                $piece = $payload[$part] ?? null;

                if ((is_string($piece) || is_numeric($piece)) && trim((string) $piece) !== '') {
                    $parts[] = trim((string) $piece);
                }
            }

            if ($parts !== [] && count($parts) === count(self::COMPOSE[$key] ?? [])) {
                $out[$key] = implode(' ', $parts);
            }
        }

        return $out;
    }

    /**
     * خواندن آرایهٔ فیلدها از درخواست (فرم پنل یا بدنهٔ JSON).
     *
     * @return array<int, mixed>
     */
    private function readRows(Request $request): array
    {
        $rows = $request->input('fields');

        if (! is_array($rows)) {
            $raw = $request->input('payload');

            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                $rows = is_array($decoded) ? $decoded : null;
            }
        }

        if (! is_array($rows)) {
            throw ValidationException::withMessages([
                'fields' => 'دادهٔ برچسب‌ها خوانده نشد. صفحه را تازه کنید و دوباره ذخیره بزنید.',
            ]);
        }

        if (count($rows) > 60) {
            throw ValidationException::withMessages([
                'fields' => 'تعداد فیلدهای ارسالی بیش از حد مجاز است.',
            ]);
        }

        return array_values($rows);
    }

    /**
     * اعتبارسنجی سرور — به هیچ بررسی‌ای که در مرورگر انجام شده اعتماد نمی‌کنیم.
     *
     * @param  array<int, mixed>  $rows
     * @param  array<int, string>  $allowed
     * @param  array<string, string>  $labels
     * @return array<string, array{value: string, bbox: array{x: float, y: float, w: float, h: float}|null}>
     */
    private function validateRows(array $rows, array $allowed, array $labels): array
    {
        $clean = [];
        $error = null;
        $errors = [];

        foreach ($rows as $index => $row) {
            $slot = 'fields.'.$index;

            if (! is_array($row)) {
                $errors[$slot] = 'ساختار فیلد شمارهٔ '.$this->fa($index + 1).' نامعتبر است.';

                continue;
            }

            $key = $row['field_key'] ?? null;

            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                $errors[$slot.'.field_key'] = 'کلید فیلد «'.(is_string($key) ? $key : '؟')
                    .'» متعلق به این نوع مدرک نیست.';

                continue;
            }

            if (array_key_exists($key, $clean)) {
                $errors[$slot.'.field_key'] = 'فیلد «'.($labels[$key] ?? $key).'» دو بار فرستاده شده است.';

                continue;
            }

            $value = $row['value'] ?? '';

            if (is_numeric($value)) {
                $value = (string) $value;
            }

            if ($value === null) {
                $value = '';
            }

            if (! is_string($value)) {
                $errors[$slot.'.value'] = 'مقدار فیلد «'.($labels[$key] ?? $key).'» باید متن باشد.';

                continue;
            }

            $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

            if (mb_strlen($value) > self::MAX_VALUE) {
                $errors[$slot.'.value'] = 'مقدار فیلد «'.($labels[$key] ?? $key).'» بیش از '
                    .$this->fa(self::MAX_VALUE).' نویسه است.';

                continue;
            }

            $bbox = $this->normalizeBox($row['bbox'] ?? null, $labels[$key] ?? $key, $error);

            if ($error !== null) {
                $errors[$slot.'.bbox'] = $error;

                continue;
            }

            $clean[$key] = ['value' => $value, 'bbox' => $bbox];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($clean === []) {
            throw ValidationException::withMessages([
                'fields' => 'هیچ فیلد معتبری برای ذخیره فرستاده نشد.',
            ]);
        }

        return $clean;
    }

    /**
     * کادر نسبی را می‌سنجد و گرد می‌کند.
     * قاعده: هر چهار عدد بین ۰ و ۱، عرض و ارتفاع بزرگ‌تر از صفر و کادر
     * کاملاً داخل تصویر.
     *
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    private function normalizeBox(mixed $bbox, string $label, ?string &$error = null): ?array
    {
        $error = null;

        if ($bbox === null || $bbox === '' || $bbox === []) {
            return null;
        }

        if (! is_array($bbox)) {
            $error = 'کادر فیلد «'.$label.'» ساختار درستی ندارد.';

            return null;
        }

        $out = [];

        foreach (['x', 'y', 'w', 'h'] as $axis) {
            $raw = $bbox[$axis] ?? null;

            if (! is_numeric($raw)) {
                $error = 'مختصات کادر فیلد «'.$label.'» عددی نیست.';

                return null;
            }

            $number = (float) $raw;

            if (! is_finite($number)) {
                $error = 'مختصات کادر فیلد «'.$label.'» معتبر نیست.';

                return null;
            }

            $out[$axis] = $number;
        }

        if ($out['w'] <= 0 || $out['h'] <= 0) {
            $error = 'عرض و ارتفاع کادر فیلد «'.$label.'» باید بزرگ‌تر از صفر باشد.';

            return null;
        }

        if ($out['w'] < self::MIN_SIDE || $out['h'] < self::MIN_SIDE) {
            $error = 'کادر فیلد «'.$label.'» بیش از حد کوچک است؛ دوباره بکشید.';

            return null;
        }

        foreach (['x', 'y'] as $axis) {
            if ($out[$axis] < -self::EPSILON || $out[$axis] > 1 + self::EPSILON) {
                $error = 'کادر فیلد «'.$label.'» بیرون از تصویر است (مختصات باید بین ۰ و ۱ باشد).';

                return null;
            }
        }

        if ($out['x'] + $out['w'] > 1 + self::EPSILON || $out['y'] + $out['h'] > 1 + self::EPSILON) {
            $error = 'کادر فیلد «'.$label.'» از لبهٔ تصویر بیرون زده است.';

            return null;
        }

        // گرد کردن و چسباندن به بازهٔ مجاز تا خطای ممیز شناور ذخیره نشود.
        $out['x'] = round(max(0.0, min(1.0, $out['x'])), 6);
        $out['y'] = round(max(0.0, min(1.0, $out['y'])), 6);
        $out['w'] = round(max(0.0, min(1.0 - $out['x'], $out['w'])), 6);
        $out['h'] = round(max(0.0, min(1.0 - $out['y'], $out['h'])), 6);

        if ($out['w'] <= 0 || $out['h'] <= 0) {
            $error = 'کادر فیلد «'.$label.'» پس از اصلاح، اندازهٔ معتبری ندارد.';

            return null;
        }

        return $out;
    }

    /** ارقام فارسی برای پیام‌ها. */
    private function fa(int|string $number): string
    {
        return strtr((string) $number, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }
}
