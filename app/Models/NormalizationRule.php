<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NormalizationRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'rule_name',
        'detected_value',
        'replacement_value',
        'rule_type',
        'applies_to_field',
        'context',
        'priority',
        'is_automatic',
        'requires_preview',
        'requires_review',
        'confidence_level',
        'active',
        'notes',
        'created_by_id',
        'updated_by_id',
    ];

    protected function casts(): array
    {
        return [
            'is_automatic' => 'boolean',
            'requires_preview' => 'boolean',
            'requires_review' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(NormalizationSuggestion::class);
    }

    public function changeLogs(): HasMany
    {
        return $this->hasMany(ProductChangeLog::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}
