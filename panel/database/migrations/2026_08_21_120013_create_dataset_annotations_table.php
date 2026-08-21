<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_sample_id')->constrained()->cascadeOnDelete();
            $table->string('field_key', 40);
            $table->text('value')->nullable();               // ground truth

            // کادر روی تصویر — نسبت به ابعاد تصویر (۰ تا ۱) تا با تغییر اندازه نشکند
            $table->float('bbox_x')->nullable();
            $table->float('bbox_y')->nullable();
            $table->float('bbox_w')->nullable();
            $table->float('bbox_h')->nullable();

            // generated: از ژنراتور | manual: تگ دستی | correction: اصلاح کارشناس روی پرونده
            $table->string('source', 20)->default('generated');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['dataset_sample_id', 'field_key'], 'ds_field_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_annotations');
    }
};
