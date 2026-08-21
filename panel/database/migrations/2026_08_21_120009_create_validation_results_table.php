<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->foreignId('case_document_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('rule_key', 60);                 // expiry_national_card, cross_national_id, ...
            $table->string('scope', 20)->default('document'); // file|document|cross
            $table->string('status', 20);                   // passed|failed|warning|skipped
            $table->string('message_fa', 255);
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['case_id', 'status']);
            $table->index('rule_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_results');
    }
};
