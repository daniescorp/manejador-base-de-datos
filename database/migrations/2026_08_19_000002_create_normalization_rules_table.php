<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('normalization_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule_name');
            $table->string('detected_value')->index();
            $table->string('replacement_value')->nullable();
            $table->string('rule_type')->index();
            $table->string('applies_to_field')->nullable()->index();
            $table->string('context')->nullable();
            $table->unsignedInteger('priority')->default(100)->index();
            $table->boolean('is_automatic')->default(false)->index();
            $table->boolean('requires_preview')->default(true);
            $table->boolean('requires_review')->default(false)->index();
            $table->string('confidence_level')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('normalization_rules');
    }
};
