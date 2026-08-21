<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_runs', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('subject');            // CaseDocument یا DatasetSample
            $table->string('engine_version', 40)->nullable();
            $table->json('params')->nullable();
            $table->longText('raw_text')->nullable();
            $table->json('extra')->nullable();             // مثل VIN و پلاک کارت خودرو
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_runs');
    }
};
