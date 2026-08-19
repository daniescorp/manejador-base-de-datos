<?php

namespace Database\Factories;

use App\Models\ProductStagingRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductStagingRow>
 */
class ProductStagingRowFactory extends Factory
{
    public function definition(): array
    {
        return [
            'codigo_producto_original' => fake()->bothify('COD-#####'),
            'nombre_sku_original' => fake()->words(4, true),
            'uxb_original' => (string) fake()->numberBetween(0, 24),
            'ean_original' => fake()->ean13(),
            'categoria_original' => fake()->word(),
            'grupo_original' => fake()->word(),
            'familia_original' => fake()->word(),
            'marca_original' => fake()->company(),
            'raw_data' => ['source' => 'test'],
            'detected_data' => ['requires_review' => false],
            'normalized_preview' => ['status' => 'pending'],
            'status' => 'pending',
            'requires_review' => false,
            'review_reason' => null,
            'row_hash' => fake()->sha256(),
            'analyzed_at' => null,
            'approved_at' => null,
        ];
    }
}
