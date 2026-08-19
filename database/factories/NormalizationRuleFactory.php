<?php

namespace Database\Factories;

use App\Models\NormalizationRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NormalizationRule>
 */
class NormalizationRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'rule_name' => fake()->words(3, true),
            'detected_value' => fake()->lexify('????.'),
            'replacement_value' => fake()->word(),
            'rule_type' => 'abbreviation',
            'applies_to_field' => 'nombre_sku_original',
            'context' => null,
            'priority' => 100,
            'is_automatic' => false,
            'requires_preview' => true,
            'requires_review' => false,
            'confidence_level' => 'medium',
            'active' => true,
            'notes' => null,
        ];
    }
}
