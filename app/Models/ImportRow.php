<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_batch_id',
        'import_file_id',
        'row_number',
        'raw_data',
        'normalized_data',
        'status',
        'row_hash',
    ];

    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
            'normalized_data' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(ImportFile::class, 'import_file_id');
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
