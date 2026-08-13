<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validation_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->nullable()->constrained('import_batches')->cascadeOnDelete();
            $table->foreignId('import_file_id')->nullable()->constrained('import_files')->cascadeOnDelete();
            $table->foreignId('import_row_id')->nullable()->constrained('import_rows')->cascadeOnDelete();
            $table->foreignId('master_product_id')->nullable()->constrained('master_products')->nullOnDelete();
            $table->string('severity')->default('warning')->index();
            $table->string('field_name')->nullable();
            $table->string('error_code')->nullable()->index();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_errors');
    }
};
