<?php

namespace App\Services\Products;

use App\Models\MasterProduct;
use App\Models\ProductChangeLog;
use App\Models\User;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class MasterProductMeasurementService
{
    private const UNIT_ALIASES = [
        'GR' => 'GR',
        'G' => 'GR',
        'GRS' => 'GR',
        'GRS.' => 'GR',
        'KG' => 'KG',
        'KGS' => 'KG',
        'K' => 'KG',
        'LT' => 'LT',
        'LTS' => 'LT',
        'L' => 'LT',
        'CC' => 'CC',
        'CM3' => 'CC',
        'ML' => 'ML',
        'MT' => 'MT',
        'MTS' => 'MT',
        'M' => 'MT',
        'UN' => 'UNIDADES',
        'UNIDAD' => 'UNIDADES',
        'UNIDADES' => 'UNIDADES',
        'SOBRE' => 'SOBRES',
        'SOBRES' => 'SOBRES',
        'S' => 'SOBRES',
    ];

    private const COMMERCIAL_TEXT_FIELDS = [
        'descripcion_catalogo',
        'nombre_sin_marca',
        'nombre_homologado',
        'name',
    ];

    public function completeMeasurement(
        MasterProduct $product,
        User $user,
        string|float|int $value,
        string $unit,
        ?string $reason = null,
    ): MasterProduct {
        $this->validatePersistedModels($product, $user);
        $numericValue = $this->positiveNumericValue($value);
        $normalizedUnit = $this->normalizedUnit($unit);
        $catalogMeasure = $this->catalogMeasure($numericValue, $normalizedUnit);

        return DB::transaction(function () use (
            $product,
            $user,
            $numericValue,
            $normalizedUnit,
            $catalogMeasure,
            $reason,
        ): MasterProduct {
            $lockedProduct = MasterProduct::query()
                ->lockForUpdate()
                ->find($product->getKey());

            if ($lockedProduct === null) {
                throw new DomainException('El producto maestro no existe.');
            }

            $oldMeasure = $this->nullableString($lockedProduct->medida_catalogo);
            $attributes = [
                'contenido_valor' => $numericValue,
                'unidad_original' => $normalizedUnit,
                'unidad_normalizada' => $normalizedUnit,
                'medida_valor' => $numericValue,
                'medida_catalogo' => $catalogMeasure,
                'medida_original' => $catalogMeasure,
                'medida_requiere_revision' => false,
            ];

            foreach (self::COMMERCIAL_TEXT_FIELDS as $field) {
                $currentText = $this->nullableString($lockedProduct->{$field});

                if ($currentText !== null) {
                    $attributes[$field] = $this->textWithMeasure(
                        $currentText,
                        $oldMeasure,
                        $catalogMeasure,
                    );
                }
            }

            $data = $lockedProduct->data ?? [];

            if (data_get($data, 'measurement.not_applicable') === true) {
                data_set($data, 'measurement.not_applicable', false);
                Arr::forget($data, [
                    'measurement.not_applicable_reason',
                    'measurement.not_applicable_by_id',
                    'measurement.not_applicable_at',
                ]);
                $attributes['data'] = $data;
            }

            return $this->saveAndLog(
                $lockedProduct,
                $user,
                $attributes,
                filled($reason)
                    ? trim((string) $reason)
                    : "Manual measurement completion for master product ID {$lockedProduct->getKey()}",
            );
        });
    }

    public function markMeasurementNotApplicable(
        MasterProduct $product,
        User $user,
        string $reason,
    ): MasterProduct {
        $this->validatePersistedModels($product, $user);
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('El motivo de la excepción de medida es obligatorio.');
        }

        return DB::transaction(function () use ($product, $user, $reason): MasterProduct {
            $lockedProduct = MasterProduct::query()
                ->lockForUpdate()
                ->find($product->getKey());

            if ($lockedProduct === null) {
                throw new DomainException('El producto maestro no existe.');
            }

            $data = $lockedProduct->data ?? [];
            data_set($data, 'measurement.not_applicable', true);
            data_set($data, 'measurement.not_applicable_reason', $reason);
            data_set($data, 'measurement.not_applicable_by_id', $user->getKey());
            data_set($data, 'measurement.not_applicable_at', now()->toIso8601String());

            return $this->saveAndLog($lockedProduct, $user, [
                'contenido_valor' => null,
                'unidad_original' => null,
                'unidad_normalizada' => null,
                'medida_valor' => null,
                'medida_catalogo' => null,
                'medida_original' => null,
                'medida_requiere_revision' => false,
                'data' => $data,
            ], $reason);
        });
    }

    private function validatePersistedModels(MasterProduct $product, User $user): void
    {
        if (! $product->exists || $product->getKey() === null) {
            throw new DomainException('Se requiere un producto maestro persistido.');
        }

        if (! $user->exists || $user->getKey() === null) {
            throw new DomainException('Se requiere un usuario persistido.');
        }
    }

    private function positiveNumericValue(string|float|int $value): float
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === '' || ! is_numeric($value) || (float) $value <= 0) {
            throw new DomainException('El valor de medida debe ser numérico y mayor que cero.');
        }

        return (float) $value;
    }

    private function normalizedUnit(string $unit): string
    {
        $unit = mb_strtoupper(trim($unit), 'UTF-8');
        $normalized = self::UNIT_ALIASES[$unit] ?? null;

        if ($normalized === null) {
            throw new DomainException('La unidad de medida no está permitida.');
        }

        return $normalized;
    }

    private function catalogMeasure(float $value, string $unit): string
    {
        $formattedValue = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return match ($unit) {
            'SOBRES' => "{$formattedValue} sobres",
            'UNIDADES' => "{$formattedValue} unidades",
            default => $formattedValue.$unit,
        };
    }

    private function textWithMeasure(string $text, ?string $oldMeasure, string $newMeasure): string
    {
        if ($this->containsCompletePhrase($text, $newMeasure)) {
            return $text;
        }

        if ($oldMeasure !== null && $this->containsCompletePhrase($text, $oldMeasure)) {
            return trim((string) preg_replace(
                $this->completePhrasePattern($oldMeasure),
                $newMeasure,
                $text,
            ));
        }

        return trim($text).' '.$newMeasure;
    }

    private function containsCompletePhrase(string $text, string $phrase): bool
    {
        return preg_match($this->completePhrasePattern($phrase), $text) === 1;
    }

    private function completePhrasePattern(string $phrase): string
    {
        return '/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/iu';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function saveAndLog(
        MasterProduct $product,
        User $user,
        array $attributes,
        string $reason,
    ): MasterProduct {
        $oldValues = $product->getAttributes();
        $product->fill($attributes);
        $changes = $product->getDirty();

        if ($changes === []) {
            return $product->fresh();
        }

        $product->save();

        foreach ($changes as $field => $newValue) {
            ProductChangeLog::query()->create([
                'master_product_id' => $product->getKey(),
                'changed_by_id' => $user->getKey(),
                'source' => 'manual',
                'field_name' => $field,
                'old_value' => $this->logValue($oldValues[$field] ?? null),
                'new_value' => $this->logValue($newValue),
                'change_reason' => $reason,
            ]);
        }

        return $product->fresh();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
