<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('normalization_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_staging_row_id')->nullable()->constrained('product_staging_rows')->cascadeOnDelete();
            $table->foreignId('master_product_id')->nullable()->constrained('master_products')->cascadeOnDelete();
            $table->foreignId('normalization_rule_id')->nullable()->constrained('normalization_rules')->nullOnDelete();
            $table->string('field_name')->index();
            $table->text('original_value')->nullable();
            $table->text('suggested_value')->nullable();
            $table->text('suggestion_reason')->nullable();
            $table->string('confidence_level')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('normalization_suggestions');
    }
};
