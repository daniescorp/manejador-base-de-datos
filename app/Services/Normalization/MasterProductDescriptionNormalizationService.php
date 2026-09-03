<?php

namespace App\Services\Normalization;

use App\Models\MasterProduct;
use App\Models\ProductChangeLog;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class MasterProductDescriptionNormalizationService
{
    public function __construct(
        private readonly DescriptionNormalizationRuleApplier $applier,
    ) {}

    /** @return array<string, mixed> */
    public function preview(MasterProduct $product): array
    {
        return $this->applier->apply(
            (string) $product->descripcion_catalogo,
            (string) $product->marca_homologada,
        );
    }

    /** @return array<string, mixed> */
    public function apply(MasterProduct $product, User $user): array
    {
        if (! $product->exists || $product->getKey() === null) {
            throw new DomainException('El producto maestro no existe.');
        }

        if (! $user->exists || $user->getKey() === null) {
            throw new DomainException('Se requiere un usuario válido.');
        }

        return DB::transaction(function () use ($product, $user): array {
            $lockedProduct = MasterProduct::query()
                ->lockForUpdate()
                ->findOrFail($product->getKey());
            $result = $this->preview($lockedProduct);

            if (! $result['changed']) {
                return $result;
            }

            $lockedProduct->forceFill([
                'descripcion_catalogo' => $result['normalized'],
            ])->save();

            ProductChangeLog::query()->create([
                'master_product_id' => $lockedProduct->getKey(),
                'changed_by_id' => $user->getKey(),
                'normalization_rule_id' => $result['applied_rules'][0]['id'] ?? null,
                'source' => 'manual_dictionary',
                'field_name' => 'descripcion_catalogo',
                'old_value' => $result['original'],
                'new_value' => $result['normalized'],
                'change_reason' => 'Reanalización individual con Diccionario: '.implode(
                    ', ',
                    array_column($result['applied_rules'], 'name'),
                ),
            ]);

            return $result;
        });
    }
}
