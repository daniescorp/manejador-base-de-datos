<?php

namespace Database\Factories;

use App\Models\ExportJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExportJob>
 */
class ExportJobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'export_type' => 'csv',
            'status' => 'draft',
            'file_path' => null,
            'rows_count' => 0,
            'meta' => ['source' => 'test'],
            'created_by_id' => User::factory(),
            'started_at' => null,
            'finished_at' => null,
        ];
    }
}
