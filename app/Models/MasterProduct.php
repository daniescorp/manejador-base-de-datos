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
        'codigo_producto',
        'codigo_original',
        'sku_original',
        'ean_original',
        'ean_validado',
        'nombre_original',
        'nombre_sin_marca',
        'nombre_homologado',
        'descripcion_catalogo',
        'titulo_shopify',
        'descripcion_app',
        'descripcion_interna',
        'marca_original',
        'marca_homologada',
        'marca_detectada_en_nombre',
        'marca_inferida',
        'requiere_revision_marca',
        'nivel_confianza_marca',
        'categoria_original',
        'categoria_homologada',
        'grupo_original',
        'grupo_homologado',
        'familia_original',
        'familia_homologada',
        'medida_original',
        'contenido_valor',
        'unidad_original',
        'unidad_normalizada',
        'cantidad_unidades',
        'medida_valor',
        'medida_catalogo',
        'medida_requiere_revision',
        'uxb_original',
        'uxb_validado',
        'uxb_requiere_revision',
        'estado_homologacion',
        'requiere_revision',
        'observaciones',
        'approved_by_id',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'marca_detectada_en_nombre' => 'boolean',
            'requiere_revision_marca' => 'boolean',
            'contenido_valor' => 'decimal:3',
            'medida_valor' => 'decimal:3',
            'medida_requiere_revision' => 'boolean',
            'uxb_requiere_revision' => 'boolean',
            'requiere_revision' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
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
