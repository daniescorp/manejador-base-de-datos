<?php

namespace App\Services\Products;

use App\Models\MasterProduct;
use App\Models\ProductChangeLog;
use App\Models\ProductStagingRow;
use App\Models\User;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Stringable;

class ProductStagingApprovalService
{
    public function approve(ProductStagingRow $row, ?User $user): MasterProduct
    {
        if ($user === null || ! $user->exists || $user->getKey() === null) {
            throw new DomainException('Se requiere un usuario válido para aprobar el producto.');
        }

        if (! $row->exists || $row->getKey() === null) {
            throw new DomainException('La fila de staging no existe.');
        }

        return DB::transaction(function () use ($row, $user): MasterProduct {
            $stagingRow = ProductStagingRow::query()
                ->lockForUpdate()
                ->find($row->getKey());

            if ($stagingRow === null) {
                throw new DomainException('La fila de staging no existe.');
            }

            $this->validateApproval($stagingRow);

            $attributes = $this->masterProductAttributes($stagingRow, $user);
            $matches = MasterProduct::query()
                ->where('codigo_producto', $attributes['codigo_producto'])
                ->lockForUpdate()
                ->limit(2)
                ->get();

            if ($matches->count() > 1) {
                throw new DomainException(
                    'Hay más de un producto maestro con el mismo código; se requiere revisión manual.',
                );
            }

            $masterProduct = $matches->first() ?? new MasterProduct;
            $oldValues = $masterProduct->exists ? $masterProduct->getAttributes() : [];

            $masterProduct->fill($attributes);
            $changes = $masterProduct->getDirty();
            $masterProduct->save();

            $this->logChanges($masterProduct, $stagingRow, $user, $oldValues, $changes);

            $stagingRow->forceFill([
                'master_product_id' => $masterProduct->getKey(),
                'status' => 'approved',
                'approved_at' => $attributes['approved_at'],
                'approved_by_id' => $user->getKey(),
                'requires_review' => false,
            ])->save();

            return $masterProduct->fresh();
        });
    }

    public function canApprove(ProductStagingRow $row): bool
    {
        try {
            $this->validateApproval($row);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    private function validateApproval(ProductStagingRow $row): void
    {
        if ($row->status === 'approved'
            || $row->approved_at !== null
            || $row->approved_by_id !== null) {
            throw new DomainException('La fila de staging ya fue aprobada.');
        }

        if ($row->requires_review) {
            throw new DomainException('La fila requiere revisión y no puede aprobarse automáticamente.');
        }

        if (blank(trim((string) $row->codigo_producto_original))) {
            throw new DomainException('La fila no tiene código de producto original.');
        }

        if (! is_array($row->normalized_preview) || $row->normalized_preview === []) {
            throw new DomainException('La fila no tiene un preview normalizado.');
        }

        if (blank($this->previewValue($row, 'descripcion_catalogo'))) {
            throw new DomainException('El preview no tiene una descripción de catálogo.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function masterProductAttributes(ProductStagingRow $row, User $user): array
    {
        $code = trim((string) $row->codigo_producto_original);
        $description = $this->previewValue($row, 'descripcion_catalogo');
        $originalBrand = $this->nullableString($row->marca_original);
        $homologatedBrand = $this->previewValue($row, 'marca_homologada')
            ?: $originalBrand;
        $catalogMeasure = $this->previewValue($row, 'medida_catalogo');
        $measurementAttributes = [
            'medida_requiere_revision' => $catalogMeasure === null,
        ];

        if ($catalogMeasure !== null) {
            $measurementAttributes = [
                'medida_original' => $this->previewValue($row, 'medida_original') ?: $catalogMeasure,
                'contenido_valor' => $this->previewValue($row, 'contenido_valor'),
                'unidad_original' => $this->previewValue($row, 'unidad_original'),
                'unidad_normalizada' => $this->previewValue($row, 'unidad_normalizada'),
                'medida_valor' => $this->previewValue($row, 'medida_valor'),
                'medida_catalogo' => $catalogMeasure,
                'medida_requiere_revision' => false,
            ];
        }
        $approvedAt = now();

        return [
            'codigo_producto' => $code,
            'codigo_original' => $code,
            'sku_original' => $code,
            'ean_original' => $this->nullableString($row->ean_original),
            'nombre_original' => $this->nullableString($row->nombre_sku_original),
            'nombre_sin_marca' => $description,
            'nombre_homologado' => $description,
            'descripcion_catalogo' => $description,
            'marca_original' => $originalBrand,
            'marca_homologada' => $homologatedBrand,
            'marca_detectada_en_nombre' => $this->brandAppearsInName(
                $this->nullableString($row->nombre_sku_original),
                $originalBrand,
                $homologatedBrand,
            ),
            'categoria_original' => $this->nullableString($row->categoria_original),
            'grupo_original' => $this->nullableString($row->grupo_original),
            'familia_original' => $this->nullableString($row->familia_original),
            'uxb_original' => $this->nullableString($row->uxb_original),
            ...$measurementAttributes,
            'estado_homologacion' => 'aprobado_catalogo',
            'requiere_revision' => false,
            'approved_by_id' => $user->getKey(),
            'approved_at' => $approvedAt,
            'last_import_batch_id' => $row->import_batch_id,
            'sku' => $code,
            'barcode' => $this->nullableString($row->ean_original),
            'name' => $description,
            'brand' => $homologatedBrand,
            'category' => $this->nullableString($row->categoria_original),
            'status' => 'active',
            'data' => $row->raw_data,
        ];
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $changes
     */
    private function logChanges(
        MasterProduct $masterProduct,
        ProductStagingRow $row,
        User $user,
        array $oldValues,
        array $changes,
    ): void {
        foreach ($changes as $field => $newValue) {
            if (($oldValues[$field] ?? null) === null && $newValue === null) {
                continue;
            }

            ProductChangeLog::query()->create([
                'master_product_id' => $masterProduct->getKey(),
                'changed_by_id' => $user->getKey(),
                'import_batch_id' => $row->import_batch_id,
                'source' => 'batch_approval',
                'field_name' => $field,
                'old_value' => $this->logValue($oldValues[$field] ?? null),
                'new_value' => $this->logValue($newValue),
                'change_reason' => "Approved from product staging row ID {$row->getKey()}",
            ]);
        }
    }

    private function previewValue(ProductStagingRow $row, string $field): ?string
    {
        $value = data_get($row->normalized_preview, $field)
            ?? data_get($row->normalized_preview, "fields.{$field}.value")
            ?? data_get($row->normalized_preview, "fields.{$field}.preview");

        return $this->nullableString($value);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value) && ! $value instanceof Stringable) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function brandAppearsInName(?string $name, ?string ...$brands): bool
    {
        if ($name === null) {
            return false;
        }

        $normalizedName = preg_replace('/\s+/u', ' ', $name);

        if ($normalizedName === null) {
            return false;
        }

        foreach ($brands as $brand) {
            if ($brand === null) {
                continue;
            }

            $normalizedBrand = preg_replace('/\s+/u', ' ', $brand);

            if ($normalizedBrand === null) {
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($normalizedBrand, '/').'(?![\p{L}\p{N}])/iu';

            if (preg_match($pattern, $normalizedName) === 1) {
                return true;
            }
        }

        return false;
    }

    private function logValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) ?: null;
        }

        return (string) $value;
    }
}
