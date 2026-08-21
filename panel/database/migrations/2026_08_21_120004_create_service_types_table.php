<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();          // issue | renew
            $table->string('label_fa', 80);
            $table->text('description_fa')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('service_type_document_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);

            $table->unique(['service_type_id', 'document_type_id'], 'std_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_type_document_type');
        Schema::dropIfExists('service_types');
    }
};
