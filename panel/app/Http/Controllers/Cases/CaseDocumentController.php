<?php

namespace App\Http\Controllers\Cases;

use App\Http\Controllers\Controller;
use App\Models\CaseDocument;
use App\Models\DocumentType;
use App\Models\PermitCase;
use App\Models\Setting;
use App\Models\ValidationResult;
use App\Services\Cases\DocumentPrecheck;
use App\Support\PersianValue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * گرفتن مدارک یک پرونده: بارگذاری، جایگزینی، حذف.
 *
 * سه قاعدهٔ امنیتی که این کلاس رعایت می‌کند:
 *
 *  ۱) **نام فایل هرگز از ورودی کاربر ساخته نمی‌شود.** مسیر را خودمان
 *     می‌سازیم: `cases/{case_id}/{document_type_key}-{uuid}.{ext}` که
 *     `document_type_key` از دیتابیس می‌آید و `ext` از **محتوای واقعی**
 *     فایل تشخیص داده می‌شود، نه از پسوند ارسالی. پس نه پیمایش مسیر ممکن
 *     است و نه «shell.php» که خودش را عکس جا زده.
 *
 *  ۲) **دیسک خصوصی `documents`** زیر storage/app/private. هیچ فایل مدرکی
 *     در public نمی‌رود؛ نمایشش فقط از روت `media` است که پشت auth است.
 *
 *  ۳) **اعتبارسنجی محتوا کار این کلاس نیست.** بعد از ذخیره، سرویس
 *     `DocumentPrecheck` (تسک ۶۳۰) فایل را بازرسی می‌کند و اگر ردش کرد،
 *     پیام و راهنمایش به کاربر نشان داده می‌شود تا **فایل را جایگزین کند**.
 *     این‌جا فقط یک سقف حجم به‌عنوان محافظ دیسک بررسی می‌شود.
 */
class CaseDocumentController extends Controller
{
    /** دیسک خصوصی مدارک — هرگز public. */
    private const DISK = 'documents';

    /**
     * پسوند مجاز برای هر MIME واقعی.
     *
     * فهرست عمداً بسته است: هر چیزی که این‌جا نباشد با پسوند `bin` ذخیره
     * می‌شود تا حتی اگر روزی مسیر ذخیره جایی سرو شد، اجراشدنی نباشد.
     * خودِ «قبول یا رد» با DocumentPrecheck است، نه با این جدول.
     */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/bmp' => 'bmp',
        'application/pdf' => 'pdf',
    ];

    // ==================================================================
    // بارگذاری و جایگزینی
    // ==================================================================

    public function store(Request $request, PermitCase $case, DocumentPrecheck $precheck): RedirectResponse
    {
        $this->authorizeCase($request, $case);
        $this->authorizeEditing($case);

        $case->load(['serviceType.documentTypes']);

        $type = $this->requiredType($case, $request->integer('document_type_id'));

        if ($type === null) {
            return back()->with('error', 'نوع مدرک انتخاب‌شده جزو مدارک لازمِ این خدمت نیست. '
                .'صفحه را تازه کنید و فایل را روی کادر همان مدرک بگذارید.');
        }

        $maxBytes = $this->maxBytes();

        $request->validate([
            'file' => ['required', 'file', 'max:'.max(1, intdiv($maxBytes, 1024))],
        ], [
            'file.required' => 'فایلی انتخاب نشده است. روی کادر «'.$type->label_fa.'» کلیک کنید و تصویر مدرک را انتخاب کنید.',
            'file.file' => 'بارگذاری فایل کامل نشد. اتصال اینترنت را بررسی کنید و دوباره تلاش کنید.',
            'file.max' => 'حجم فایل بیشتر از حداکثر مجاز ('
                .PersianValue::decimal($maxBytes / 1048576, 1).' مگابایت) است. '
                .'عکس را با کیفیت پایین‌تر ذخیره کنید و دوباره بارگذاری کنید.',
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $path = Storage::disk(self::DISK)->putFileAs(
            'cases/'.$case->id,
            $file,
            $this->safeFileName($type, $file),
        );

        if ($path === false || $path === null) {
            return back()->with('error', 'ذخیرهٔ فایل روی سرور انجام نشد. '
                .'یک بار دیگر تلاش کنید؛ اگر باز هم تکرار شد به مدیر سامانه اطلاع دهید.');
        }

        $document = $this->attachDocument($case, $type, $file, $path);

        $accepted = $precheck->inspect($document);

        return back()->with(...$this->resultFlash($type, $document->refresh(), $accepted));
    }

    // ==================================================================
    // حذف
    // ==================================================================

    public function destroy(Request $request, PermitCase $case, CaseDocument $document): RedirectResponse
    {
        $this->authorizeCase($request, $case);
        $this->authorizeEditing($case);

        abort_unless($document->case_id === $case->id, 404, 'این مدرک به پروندهٔ دیگری تعلق دارد.');

        $label = $document->documentType?->label_fa ?? 'مدرک';

        $this->forgetFile($document->disk, $document->path);
        $this->forgetDerivedData($document);

        $document->delete();

        return back()->with('warning', 'مدرک «'.$label.'» حذف شد و پرونده دوباره ناقص است. '
            .'برای ثبت پرونده باید همین مدرک را دوباره بارگذاری کنید.');
    }

    // ==================================================================
    // ذخیرهٔ رکورد
    // ==================================================================

    /**
     * رکورد مدرک را می‌سازد یا **جایگزین** می‌کند.
     *
     * جایگزینی روی همان ردیف انجام می‌شود (شناسه ثابت می‌ماند) و فایل قبلی
     * از دیسک پاک می‌شود تا زباله جا نماند. نتیجهٔ بررسی‌های قبلی هم ریست
     * می‌شود، چون به فایلی مربوط بود که دیگر وجود ندارد.
     */
    private function attachDocument(PermitCase $case, DocumentType $type, UploadedFile $file, string $path): CaseDocument
    {
        $documents = $case->documents()
            ->where('document_type_id', $type->id)
            ->orderBy('id')
            ->get();

        /** @var CaseDocument|null $document */
        $document = $documents->shift();

        // ردیف‌های اضافیِ همان نوع (اگر به هر دلیلی ساخته شده باشند) پاک می‌شوند:
        // هر نوع مدرک در هر پرونده فقط یک فایل دارد.
        foreach ($documents as $extra) {
            $this->forgetFile($extra->disk, $extra->path);
            $this->forgetDerivedData($extra);
            $extra->delete();
        }

        $fresh = [
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $this->safeOriginalName($file),
            'mime' => null,
            'size_bytes' => null,
            'width' => null,
            'height' => null,
            'checksum' => null,
            'precheck_status' => 'pending',
            'precheck_issues' => null,
            'blur_score' => null,
            'brightness_score' => null,
            'ocr_status' => 'pending',
        ];

        if ($document === null) {
            return $case->documents()->create($fresh + ['document_type_id' => $type->id]);
        }

        $previous = ['disk' => $document->disk, 'path' => $document->path];

        $this->forgetDerivedData($document);

        $document->fill($fresh)->save();

        if ($previous['path'] !== $path) {
            $this->forgetFile($previous['disk'], $previous['path']);
        }

        return $document;
    }

    // ==================================================================
    // پاک‌سازی
    // ==================================================================

    /** حذف فایل و بندانگشتی‌های کش‌شده‌اش. */
    private function forgetFile(?string $disk, ?string $path): void
    {
        if (blank($disk) || blank($path)) {
            return;
        }

        Storage::disk($disk)->delete($path);

        // کش بندانگشتی MediaController: thumbs/{disk}/{width}/{sha1(path)}.jpg
        // عرض‌ها آن‌جا تعریف شده‌اند، پس به‌جای تکرارشان پوشه‌ها پیمایش می‌شوند.
        $thumbs = Storage::disk('thumbs');
        $key = sha1($path).'.jpg';

        foreach ($thumbs->directories($disk) as $folder) {
            $thumbs->delete($folder.'/'.$key);
        }
    }

    /**
     * دادهٔ مشتق‌شده از فایل قبلی: اجرای OCR، فیلدهای استخراج‌شده و ردیف‌های
     * اعتبارسنجی. با رفتن فایل، این‌ها بی‌معنا و گمراه‌کننده می‌شوند.
     */
    private function forgetDerivedData(CaseDocument $document): void
    {
        $document->ocrRuns()->delete();
        $document->extractedFields()->delete();

        ValidationResult::query()->where('case_document_id', $document->id)->delete();
    }

    // ==================================================================
    // نام و مسیر فایل
    // ==================================================================

    /**
     * نام امنِ ساخته‌شده در سمت سرور.
     *
     * هیچ تکه‌ای از ورودی کاربر در آن نیست: کلید نوع مدرک از دیتابیس، یک
     * uuid، و پسوندی که از محتوای فایل تشخیص داده شده.
     */
    private function safeFileName(DocumentType $type, UploadedFile $file): string
    {
        $key = preg_replace('/[^a-z0-9_-]+/', '', Str::lower((string) $type->key)) ?: 'document';

        return $key.'-'.Str::uuid()->toString().'.'.$this->extensionFor($file);
    }

    /** پسوند از روی MIME واقعیِ محتوا — پسوند ارسالی نادیده گرفته می‌شود. */
    private function extensionFor(UploadedFile $file): string
    {
        $mime = null;

        $real = $file->getRealPath();

        if (is_string($real) && is_file($real)) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $guess = @finfo_file($finfo, $real);
                finfo_close($finfo);

                if (is_string($guess) && $guess !== '') {
                    $mime = strtolower(trim(explode(';', $guess)[0]));
                }
            }
        }

        return self::EXTENSIONS[$mime] ?? 'bin';
    }

    /** نام اصلی فقط برای نمایش نگه داشته می‌شود؛ در هیچ مسیری استفاده نمی‌شود. */
    private function safeOriginalName(UploadedFile $file): string
    {
        $name = PersianValue::normalize($file->getClientOriginalName());

        return mb_substr($name, 0, 120) ?: 'بدون‌نام';
    }

    // ==================================================================
    // پیام نتیجه
    // ==================================================================

    /**
     * پیام فارسی بعد از بارگذاری: بگوید چه شد و کاربر چه کند.
     *
     * @return array{0: string, 1: string} [کلید فلش، متن]
     */
    private function resultFlash(DocumentType $type, CaseDocument $document, bool $accepted): array
    {
        $issues = is_array($document->precheck_issues) ? $document->precheck_issues : [];

        if (! $accepted) {
            $first = $this->firstIssue($issues, 'error');

            return ['error', 'مدرک «'.$type->label_fa.'» پذیرفته نشد. '
                .($first['message_fa'] ?? 'فایل بارگذاری‌شده قابل استفاده نیست.').' '
                .($first['hint_fa'] ?? '')
                .' فایل درست را روی همین کادر بگذارید تا جایگزین شود.'];
        }

        $warning = $this->firstIssue($issues, 'warning');

        if ($warning !== []) {
            return ['warning', 'مدرک «'.$type->label_fa.'» ثبت شد. '
                .($warning['message_fa'] ?? '').' '.($warning['hint_fa'] ?? '')];
        }

        return ['success', 'مدرک «'.$type->label_fa.'» بارگذاری شد و بررسی اولیه را با موفقیت گذراند.'];
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return array<string, mixed>
     */
    private function firstIssue(array $issues, string $severity): array
    {
        foreach ($issues as $issue) {
            if (is_array($issue) && ($issue['severity'] ?? 'error') === $severity) {
                return $issue;
            }
        }

        return [];
    }

    // ==================================================================
    // ابزار
    // ==================================================================

    /** نوع مدرک، فقط اگر واقعاً جزو مدارک همین خدمت باشد. */
    private function requiredType(PermitCase $case, int $documentTypeId): ?DocumentType
    {
        return $case->serviceType?->documentTypes->firstWhere('id', $documentTypeId);
    }

    /** سقف حجم از تنظیمات؛ نبودِ تنظیمات نباید آپلود را قفل کند. */
    private function maxBytes(): int
    {
        $limits = Setting::get('precheck.limits');

        $value = is_array($limits) ? ($limits['max_bytes'] ?? null) : null;

        return (int) ($value ?: DocumentPrecheck::DEFAULT_LIMITS['max_bytes']);
    }

    /** مالکیت پرونده — همان الگوی TestImageController. */
    private function authorizeCase(Request $request, PermitCase $case): void
    {
        abort_unless(
            $request->user()->isAdmin() || $case->user_id === $request->user()->id,
            403,
            'این پرونده متعلق به کاربر دیگری است و شما اجازهٔ دیدنش را ندارید.',
        );
    }

    /** بعد از ثبت پرونده، مدارکش قفل می‌شود. */
    private function authorizeEditing(PermitCase $case): void
    {
        abort_unless(
            $case->status === 'draft',
            403,
            'این پرونده ثبت شده است و مدارکش دیگر تغییر نمی‌کند؛ وضعیت فعلی‌اش «'.$case->statusLabel().'» است.',
        );
    }
}
