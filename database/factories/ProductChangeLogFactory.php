<?php

namespace Database\Factories;

use App\Models\MasterProduct;
use App\Models\ProductChangeLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductChangeLog>
 */
class ProductChangeLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'master_product_id' => MasterProduct::factory(),
            'changed_by_id' => null,
            'normalization_rule_id' => null,
            'import_batch_id' => null,
            'source' => 'manual',
            'field_name' => 'name',
            'old_value' => fake()->words(3, true),
            'new_value' => fake()->words(3, true),
            'change_reason' => fake()->sentence(),
        ];
    }
}
