<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_staging_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
            $table->foreignId('import_file_id')->nullable()->constrained('import_files')->nullOnDelete();
            $table->foreignId('import_row_id')->nullable()->constrained('import_rows')->nullOnDelete();
            $table->foreignId('master_product_id')->nullable()->constrained('master_products')->nullOnDelete();
            $table->string('codigo_producto_original')->nullable()->index();
            $table->text('nombre_sku_original')->nullable();
            $table->string('uxb_original')->nullable();
            $table->string('ean_original')->nullable();
            $table->string('categoria_original')->nullable();
            $table->string('grupo_original')->nullable();
            $table->string('familia_original')->nullable();
            $table->string('marca_original')->nullable();
            $table->json('raw_data')->nullable();
            $table->json('detected_data')->nullable();
            $table->json('normalized_preview')->nullable();
            $table->string('status')->default('pending')->index();
            $table->boolean('requires_review')->default(false)->index();
            $table->text('review_reason')->nullable();
            $table->string('row_hash')->nullable()->index();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_staging_rows');
    }
};
