<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->string('component_key', 60);            // ocr_quality|validation|completeness
            $table->string('label_fa', 120);
            $table->decimal('weight', 5, 2);
            $table->decimal('value', 5, 2);                 // 0..100
            $table->decimal('contribution', 5, 2);          // weight * value
            $table->text('note_fa')->nullable();
            $table->timestamps();

            $table->index('case_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_components');
    }
};
