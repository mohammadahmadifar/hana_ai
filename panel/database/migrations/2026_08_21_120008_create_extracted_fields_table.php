<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extracted_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->foreignId('case_document_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('field_key', 40);
            $table->text('raw_value')->nullable();          // همان‌طور که از OCR آمد
            $table->text('normalized_value')->nullable();   // بعد از نرمال‌سازی
            $table->decimal('confidence', 5, 2)->default(0);
            $table->string('source', 20)->default('ocr');   // ocr|manual
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
            $table->timestamps();

            $table->index(['case_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extracted_fields');
    }
};
