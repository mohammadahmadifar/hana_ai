<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('color', 9)->default('#64748b');
            $table->text('description_fa')->nullable();
            $table->timestamps();
        });

        Schema::create('dataset_sample_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_sample_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dataset_tag_id')->constrained()->cascadeOnDelete();
            $table->unique(['dataset_sample_id', 'dataset_tag_id'], 'dst_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_sample_tag');
        Schema::dropIfExists('dataset_tags');
    }
};
