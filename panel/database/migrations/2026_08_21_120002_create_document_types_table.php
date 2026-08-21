<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();          // national_card, driving_license, ...
            $table->string('label_fa', 80);
            $table->string('template_path')->nullable();   // قالب PNG در موتور پایتون
            $table->boolean('is_generatable')->default(false); // موتور می‌تواند بسازدش؟
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
