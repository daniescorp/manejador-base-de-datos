<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_batch_id',
        'original_name',
        'stored_path',
        'file_type',
        'delimiter',
        'encoding',
        'total_rows',
        'valid_rows',
        'error_rows',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function validationErrors(): HasMany
    {
        return $this->hasMany(ValidationError::class);
    }

    public function productStagingRows(): HasMany
    {
        return $this->hasMany(ProductStagingRow::class);
    }
}
