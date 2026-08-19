<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductStagingRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_batch_id',
        'import_file_id',
        'import_row_id',
        'master_product_id',
        'codigo_producto_original',
        'nombre_sku_original',
        'uxb_original',
        'ean_original',
        'categoria_original',
        'grupo_original',
        'familia_original',
        'marca_original',
        'raw_data',
        'detected_data',
        'normalized_preview',
        'status',
        'requires_review',
        'review_reason',
        'row_hash',
        'analyzed_at',
        'approved_at',
        'approved_by_id',
    ];

    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
            'detected_data' => 'array',
            'normalized_preview' => 'array',
            'requires_review' => 'boolean',
            'analyzed_at' => 'datetime',
            'approved_at' => 'datetime',
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

    public function importRow(): BelongsTo
    {
        return $this->belongsTo(ImportRow::class, 'import_row_id');
    }

    public function masterProduct(): BelongsTo
    {
        return $this->belongsTo(MasterProduct::class, 'master_product_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(NormalizationSuggestion::class);
    }
}
