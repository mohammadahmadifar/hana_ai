<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اجراهای «ارزیابی دقت» (تسک ۷۲۶).
 *
 * هر ردیف یک اندازه‌گیری کامل است: n تصویر مصنوعی ساخته می‌شود، همان‌ها OCR و
 * استخراج می‌شوند، و مقدار خوانده‌شدهٔ هر فیلد با متنی که واقعاً روی تصویر چاپ
 * شده مقایسه می‌گردد. شمارنده‌ها حین اجرا به‌روز می‌شوند تا صفحهٔ پیشرفت زنده
 * باشد و اگر کار نیمه‌کاره ماند، همان‌جا معلوم باشد چقدر جلو رفته.
 *
 * چرا جدا از generation_batches: آن جدول «نمونهٔ دیتاست» می‌سازد و نگه می‌دارد؛
 * این‌جا تصویرها بعد از خوانده‌شدن پاک می‌شوند و آنچه می‌ماند فقط عدد است.
 * یکی‌کردنشان یعنی یک جدول با دو معنی و ستون‌هایی که نیمی‌شان همیشه خالی‌اند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('document_type_ids');
            $table->unsignedInteger('count_requested');            // تصویر، نه شخص
            $table->unsignedInteger('count_done')->default(0);
            $table->unsignedInteger('count_failed')->default(0);
            $table->string('augment_mode', 20)->default('clean');  // clean|random
            $table->string('status', 20)->default('queued');       // queued|running|done|failed

            // شمارش خام تا درصد همیشه از روی همین دو عدد ساخته شود، نه برعکس:
            // درصدِ ذخیره‌شده با ادامهٔ دسته در یک Job تازه باید بازمحاسبه شود.
            $table->unsignedInteger('fields_total')->default(0);
            $table->unsignedInteger('fields_correct')->default(0);
            $table->decimal('confidence_sum', 12, 2)->default(0);

            $table->json('breakdown')->nullable();   // نوع مدرک ← فیلد ← شمارش‌ها
            $table->json('misses')->nullable();      // نمونهٔ محدودی از خواندن‌های نادرست
            $table->json('previews')->nullable();    // چند تصویر نگه‌داشته‌شده برای دیدن
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_runs');
    }
};
