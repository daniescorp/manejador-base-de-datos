<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_product_id')->constrained('master_products')->cascadeOnDelete();
            $table->foreignId('changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('normalization_rule_id')->nullable()->constrained('normalization_rules')->nullOnDelete();
            $table->foreignId('import_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
            $table->string('source')->index();
            $table->string('field_name')->index();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('change_reason')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_change_logs');
    }
};
