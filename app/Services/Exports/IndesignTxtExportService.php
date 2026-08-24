<?php

namespace App\Services\Exports;

use App\Models\MasterProduct;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class IndesignTxtExportService
{
    public const FORMAT = 'indesign_tapa_amba_tab_txt';

    public const DELIMITER = "\t";

    public const COLUMNS = 15;

    public const PRICES_SOURCE = 'external_pending';

    public const HEADER = "CATEGORIA\tGRUPO\tCODIGO\tMARCA\tDESCRIPCION\tUXB\t@folder\tPRECIOLISTA\t@folder\t PRECIOOFERTA \t PRECIOTACHADO \t@folder\t@folder\tConca\tConca";

    private const LINE_ENDING = "\r\n";

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
            'uxb_original',
            'categoria_original',
            'grupo_original',
            'medida_catalogo',
            'medida_requiere_revision',
            'data',
        ]);
    }

    /**
     * @return array{
     *     rows: int,
     *     lines: list<string>,
     *     content: string,
     *     skipped_missing_measure: int,
     *     skipped_missing_measure_codes: list<string>,
     *     exported_measure_exceptions: int,
     *     exported_measure_exception_codes: list<string>
     * }
     */
    public function generate(?int $limit = null, bool $includeCategoryGroup = false): array
    {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('El límite debe ser un entero positivo.');
        }

        $products = $this->approvedProducts();
        $missingMeasure = $products
            ->reject(fn (MasterProduct $product): bool => $this->hasCompleteMeasure($product)
                || $this->hasMeasurementException($product))
            ->values();
        $exportableProducts = $products
            ->filter(fn (MasterProduct $product): bool => $this->hasCompleteMeasure($product)
                || $this->hasMeasurementException($product));

        if ($limit !== null) {
            $exportableProducts = $exportableProducts->take($limit);
        }

        $measurementExceptions = $exportableProducts
            ->filter(fn (MasterProduct $product): bool => $this->hasMeasurementException($product))
            ->values();
        $productLines = $exportableProducts
            ->map(fn (MasterProduct $product): string => $this->productLine(
                $product,
                $includeCategoryGroup,
            ))
            ->values()
            ->all();
        $lines = [self::HEADER, ...$productLines];

        return [
            'rows' => count($productLines),
            'lines' => $lines,
            'content' => implode(self::LINE_ENDING, $lines),
            'skipped_missing_measure' => $missingMeasure->count(),
            'skipped_missing_measure_codes' => $missingMeasure
                ->pluck('codigo_producto')
                ->map(static fn (mixed $code): string => (string) $code)
                ->all(),
            'exported_measure_exceptions' => $measurementExceptions->count(),
            'exported_measure_exception_codes' => $measurementExceptions
                ->pluck('codigo_producto')
                ->map(static fn (mixed $code): string => (string) $code)
                ->all(),
        ];
    }

    private function hasCompleteMeasure(MasterProduct $product): bool
    {
        return ! $product->medida_requiere_revision
            && trim((string) $product->medida_catalogo) !== '';
    }

    private function hasMeasurementException(MasterProduct $product): bool
    {
        return ! $product->medida_requiere_revision
            && data_get($product->data, 'measurement.not_applicable') === true
            && trim((string) data_get($product->data, 'measurement.not_applicable_reason')) !== '';
    }

    private function productLine(MasterProduct $product, bool $includeCategoryGroup): string
    {
        $code = $this->txtValue($product->codigo_producto);
        $columns = [
            $includeCategoryGroup ? $this->txtValue($product->categoria_original) : '',
            $includeCategoryGroup ? $this->txtValue($product->grupo_original) : '',
            $code,
            $this->txtValue($product->marca_homologada),
            $this->txtValue($product->descripcion_catalogo),
            $this->txtValue($product->uxb_original),
            '.\\imagenes\\'.$code.'.png',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ];

        return implode(self::DELIMITER, $columns);
    }

    private function txtValue(mixed $value): string
    {
        return trim((string) preg_replace('/[\t\r\n]+/u', ' ', (string) $value));
    }
}
