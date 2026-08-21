<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_type_id')->constrained();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // generated: ساخته موتور (برچسب رایگان) | uploaded: تصویر واقعی (نیاز به تگ دستی)
            $table->string('source', 20)->default('generated');

            $table->string('disk', 20)->default('dataset');
            $table->string('path');                         // تصویر نهایی (بعد از اعوجاج)
            $table->string('clean_path')->nullable();       // تصویر تمیز قبل از اعوجاج
            $table->string('original_name')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->string('augmentation', 30)->nullable(); // rotation|brightness|blur|noise|shadow|none
            $table->json('augmentation_params')->nullable();
            $table->json('generation_payload')->nullable(); // داده شخصی که چاپ شده

            // برای split آموزش
            $table->string('split', 10)->default('train');  // train|val|test

            $table->boolean('is_verified')->default(false); // برچسبش تایید انسانی شده؟
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['document_type_id', 'source']);
            $table->index(['is_verified', 'split']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_samples');
    }
};
