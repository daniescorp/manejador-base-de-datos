<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NormalizationSuggestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_staging_row_id',
        'master_product_id',
        'normalization_rule_id',
        'field_name',
        'original_value',
        'suggested_value',
        'suggestion_reason',
        'confidence_level',
        'status',
        'reviewed_by_id',
        'reviewed_at',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function stagingRow(): BelongsTo
    {
        return $this->belongsTo(ProductStagingRow::class, 'product_staging_row_id');
    }

    public function masterProduct(): BelongsTo
    {
        return $this->belongsTo(MasterProduct::class, 'master_product_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(NormalizationRule::class, 'normalization_rule_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
}
