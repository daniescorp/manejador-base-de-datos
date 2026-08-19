<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MasterProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sku',
        'barcode',
        'name',
        'brand',
        'category',
        'status',
        'source_reference',
        'data',
        'last_import_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    public function lastImportBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'last_import_batch_id');
    }

    public function validationErrors(): HasMany
    {
        return $this->hasMany(ValidationError::class);
    }

    public function productStagingRows(): HasMany
    {
        return $this->hasMany(ProductStagingRow::class);
    }

    public function normalizationSuggestions(): HasMany
    {
        return $this->hasMany(NormalizationSuggestion::class);
    }

    public function changeLogs(): HasMany
    {
        return $this->hasMany(ProductChangeLog::class);
    }
}
