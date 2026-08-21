<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_type_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);                    // national_id, first_name, ...
            $table->string('label_fa', 80);
            $table->string('value_type', 30)->default('text'); // text|national_id|jalali_date|digits|vin|plate
            $table->boolean('is_required')->default(true);
            $table->boolean('is_cross_checked')->default(false); // باید بین مدارک یکی باشد
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['document_type_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_type_fields');
    }
};
