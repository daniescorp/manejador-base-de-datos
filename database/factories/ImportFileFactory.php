<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use App\Models\ImportFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportFile>
 */
class ImportFileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'import_batch_id' => ImportBatch::factory(),
            'original_name' => fake()->word().'.csv',
            'stored_path' => null,
            'file_type' => 'csv',
            'delimiter' => ',',
            'encoding' => 'UTF-8',
            'total_rows' => 1,
            'valid_rows' => 1,
            'error_rows' => 0,
            'meta' => ['source' => 'test'],
        ];
    }
}
