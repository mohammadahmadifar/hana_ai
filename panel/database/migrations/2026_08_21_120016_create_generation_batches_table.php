<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generation_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('document_type_ids');
            $table->unsignedInteger('count_requested');
            $table->unsignedInteger('count_done')->default(0);
            $table->unsignedInteger('count_failed')->default(0);
            $table->json('augmentations')->nullable();
            $table->string('status', 20)->default('queued'); // queued|running|done|failed
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_batches');
    }
};
