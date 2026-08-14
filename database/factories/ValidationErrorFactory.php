<?php

namespace Database\Factories;

use App\Models\ValidationError;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ValidationError>
 */
class ValidationErrorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'severity' => 'warning',
            'field_name' => 'sku',
            'error_code' => 'invalid_value',
            'message' => fake()->sentence(),
            'context' => ['source' => 'test'],
            'resolved_at' => null,
        ];
    }
}
