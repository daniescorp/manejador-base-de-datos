<?php

namespace Database\Factories;

use App\Models\MasterProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MasterProduct>
 */
class MasterProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sku' => fake()->unique()->bothify('SKU-#####'),
            'barcode' => fake()->ean13(),
            'name' => fake()->words(3, true),
            'brand' => fake()->company(),
            'category' => fake()->word(),
            'status' => 'active',
            'source_reference' => null,
            'data' => ['source' => 'test'],
        ];
    }
}
