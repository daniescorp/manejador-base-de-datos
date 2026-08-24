<?php

namespace App\Services\Exports;

use App\Models\MasterProduct;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class IndesignTxtExportService
{
    /**
     * @return Collection<int, MasterProduct>
     */
    public function approvedProducts(?int $limit = null): Collection
    {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('El límite debe ser un entero positivo.');
        }

        $query = MasterProduct::query()
            ->where('status', 'active')
            ->where('estado_homologacion', 'aprobado_catalogo')
            ->where('requiere_revision', false)
            ->whereRaw("TRIM(COALESCE(marca_homologada, '')) <> ''")
            ->whereRaw("TRIM(COALESCE(descripcion_catalogo, '')) <> ''")
            ->orderBy('marca_homologada')
            ->orderBy('descripcion_catalogo')
            ->orderBy('codigo_producto');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get([
            'id',
            'codigo_producto',
            'marca_homologada',
            'descripcion_catalogo',
        ]);
    }

    /**
     * @return array{rows: int, lines: list<string>, content: string}
     */
    public function generate(?int $limit = null): array
    {
        $products = $this->approvedProducts($limit);
        $lines = $products
            ->map(fn (MasterProduct $product): string => sprintf(
                '%s;%s',
                trim((string) $product->marca_homologada),
                trim((string) $product->descripcion_catalogo),
            ))
            ->values()
            ->all();

        return [
            'rows' => count($lines),
            'lines' => $lines,
            'content' => implode(PHP_EOL, $lines),
        ];
    }
}
