<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use App\Models\ImportFile;
use App\Models\ImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportRow>
 */
class ImportRowFactory extends Factory
{
    public function definition(): array
    {
        return [
            'import_batch_id' => ImportBatch::factory(),
            'import_file_id' => ImportFile::factory(),
            'row_number' => 1,
            'raw_data' => ['sku' => fake()->bothify('SKU-####')],
            'normalized_data' => ['status' => 'normalized'],
            'status' => 'pending',
            'row_hash' => fake()->sha256(),
        ];
    }
}
