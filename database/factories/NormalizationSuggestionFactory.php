<?php

namespace Database\Factories;

use App\Models\NormalizationRule;
use App\Models\NormalizationSuggestion;
use App\Models\ProductStagingRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NormalizationSuggestion>
 */
class NormalizationSuggestionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_staging_row_id' => ProductStagingRow::factory(),
            'master_product_id' => null,
            'normalization_rule_id' => NormalizationRule::factory(),
            'field_name' => 'nombre_sku_original',
            'original_value' => fake()->word(),
            'suggested_value' => fake()->word(),
            'suggestion_reason' => fake()->sentence(),
            'confidence_level' => 'medium',
            'status' => 'pending',
            'reviewed_by_id' => null,
            'reviewed_at' => null,
            'applied_at' => null,
        ];
    }
}
