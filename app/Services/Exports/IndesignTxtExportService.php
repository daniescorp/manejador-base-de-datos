<?php

namespace App\Services\Exports;

use App\Models\MasterProduct;
use App\Services\ExternalFiles\ExternalPriceFormatter;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class IndesignTxtExportService
{
    public const FORMAT = 'indesign_tapa_amba_tab_txt';

    public const DELIMITER = "\t";

    public const COLUMNS = 15;

    public const PRICES_SOURCE = 'external_pending';

    public const EXTERNAL_PRICES_SOURCE = 'external_provided';

    public const HEADER = "CATEGORIA\tGRUPO\tCODIGO\tMARCA\tDESCRIPCION\tUXB\t@folder\tPRECIOLISTA\t@folder\t PRECIOOFERTA \t PRECIOTACHADO \t@folder\t@folder\tConca\tConca";

    private const LINE_ENDING = "\r\n";

    private const PRICE_FIELDS = [
        'precio_lista' => 'PRECIOLISTA',
        'precio_oferta' => 'PRECIOOFERTA',
        'precio_tachado' => 'PRECIOTACHADO',
    ];

    public function __construct(private readonly ExternalPriceFormatter $priceFormatter) {}

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
     *     exported_measure_exception_codes: list<string>,
     *     prices_source: string,
     *     price_requires_review: bool,
     *     price_review_count: int,
     *     price_warnings: list<array<string, mixed>>
     * }
     */
    public function generate(
        ?int $limit = null,
        bool $includeCategoryGroup = false,
        array $externalPrices = [],
    ): array {
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
        $externalPricesByCode = $this->externalPricesByCode($externalPrices);
        $priceWarnings = [];
        $productLines = $exportableProducts
            ->map(function (MasterProduct $product) use (
                $includeCategoryGroup,
                $externalPricesByCode,
                &$priceWarnings,
            ): string {
                $code = (string) $product->codigo_producto;
                $prices = $this->formatExternalPrices($externalPricesByCode[$code] ?? []);

                foreach ($prices['warnings'] as $warning) {
                    $priceWarnings[] = ['code' => $code, ...$warning];
                }

                return $this->productLine(
                    $product,
                    $includeCategoryGroup,
                    $prices['formatted_values'],
                );
            })
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
            'prices_source' => $externalPrices === [] ? self::PRICES_SOURCE : self::EXTERNAL_PRICES_SOURCE,
            'price_requires_review' => $priceWarnings !== [],
            'price_review_count' => count($priceWarnings),
            'price_warnings' => $priceWarnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $externalRow
     * @return array{
     *     formatted_values: array{precio_lista: string, precio_oferta: string, precio_tachado: string},
     *     requires_review: bool,
     *     warnings: list<array<string, mixed>>
     * }
     */
    public function formatExternalPrices(array $externalRow): array
    {
        $normalizedRow = [];

        foreach ($externalRow as $field => $value) {
            $normalizedRow[$this->normalizePriceField((string) $field)] = $value;
        }

        $formattedValues = [];
        $warnings = [];

        foreach (self::PRICE_FIELDS as $key => $externalField) {
            $result = $this->priceFormatter->format($normalizedRow[$externalField] ?? null);
            $formattedValues[$key] = $result['formatted_value'];

            if ($result['requires_review']) {
                $warnings[] = [
                    'field' => $externalField,
                    'original_value' => $result['original_value'],
                    'status' => $result['status'],
                    'requires_review' => true,
                    'warning' => $result['warning'],
                ];
            }
        }

        return [
            'formatted_values' => $formattedValues,
            'requires_review' => $warnings !== [],
            'warnings' => $warnings,
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

    /** @param array{precio_lista: string, precio_oferta: string, precio_tachado: string} $prices */
    private function productLine(
        MasterProduct $product,
        bool $includeCategoryGroup,
        array $prices,
    ): string {
        $code = $this->txtValue($product->codigo_producto);
        $columns = [
            $includeCategoryGroup ? $this->txtValue($product->categoria_original) : '',
            $includeCategoryGroup ? $this->txtValue($product->grupo_original) : '',
            $code,
            $this->txtValue($product->marca_homologada),
            $this->txtValue($product->descripcion_catalogo),
            $this->txtValue($product->uxb_original),
            '.\\imagenes\\'.$code.'.png',
            $this->txtValue($prices['precio_lista']),
            '',
            $this->txtValue($prices['precio_oferta']),
            $this->txtValue($prices['precio_tachado']),
            '',
            '',
            '',
            '',
        ];

        return implode(self::DELIMITER, $columns);
    }

    /** @return array<string, array<string, mixed>> */
    private function externalPricesByCode(array $externalPrices): array
    {
        $indexed = [];

        foreach ($externalPrices as $key => $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalizedRow = [];

            foreach ($row as $field => $value) {
                $normalizedRow[$this->normalizePriceField((string) $field)] = $value;
            }

            $code = $normalizedRow['CODIGO']
                ?? $normalizedRow['CODIGOPRODUCTO']
                ?? $normalizedRow['SKU']
                ?? $key;
            $code = trim((string) $code);

            if ($code !== '') {
                $indexed[$code] = $row;
            }
        }

        return $indexed;
    }

    private function normalizePriceField(string $field): string
    {
        return mb_strtoupper(preg_replace('/[^a-z0-9]+/iu', '', trim($field)) ?? trim($field), 'UTF-8');
    }

    private function txtValue(mixed $value): string
    {
        return trim((string) preg_replace('/[\t\r\n]+/u', ' ', (string) $value));
    }
}
