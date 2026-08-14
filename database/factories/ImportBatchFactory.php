<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'process_type' => 'import',
            'source_type' => 'csv',
            'status' => 'draft',
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
