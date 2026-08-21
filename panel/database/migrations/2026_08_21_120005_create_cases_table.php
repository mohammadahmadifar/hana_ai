<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cases', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();          // HA-1405-000123
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_type_id')->constrained();
            $table->string('applicant_name')->nullable();
            $table->string('applicant_national_id', 20)->nullable();

            // draft: در حال آپلود | submitted: ثبت شد | processing: در صف/پردازش
            // needs_review: نیاز به بررسی انسانی | approved | rejected
            $table->string('status', 20)->default('draft');

            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->string('decision', 20)->nullable();     // approved|rejected|needs_review
            $table->text('decision_reason')->nullable();
            $table->boolean('decision_is_manual')->default(false);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('processing_ms')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('service_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cases');
    }
};
