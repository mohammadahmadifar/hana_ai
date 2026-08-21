<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained();

            $table->string('disk', 20)->default('documents');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 60)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('checksum', 64)->nullable();

            // اعتبارسنجی اولیه فایل (قبل از خرج کردن OCR)
            $table->string('precheck_status', 20)->default('pending'); // pending|passed|failed
            $table->json('precheck_issues')->nullable();
            $table->float('blur_score')->nullable();
            $table->float('brightness_score')->nullable();

            $table->string('ocr_status', 20)->default('pending'); // pending|queued|running|done|failed
            $table->timestamps();

            $table->index(['case_id', 'document_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_documents');
    }
};
