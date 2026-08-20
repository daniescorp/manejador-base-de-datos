<?php

namespace App\Services\Imports;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductExcelAuditService
{
    private const MAIN_SHEET = 'Base';

    private const EXPECTED_HEADERS = [
        'Sku',
        'Nombre Sku',
        'UXB',
        'Ean',
        'Categoria',
        'Grupo',
        'Familia',
        'Marca',
    ];

    private const STAGING_MAPPING = [
        'Sku' => 'codigo_producto_original',
        'Nombre Sku' => 'nombre_sku_original',
        'UXB' => 'uxb_original',
        'Ean' => 'ean_original',
        'Categoria' => 'categoria_original',
        'Grupo' => 'grupo_original',
        'Familia' => 'familia_original',
        'Marca' => 'marca_original',
        'Fila completa' => 'raw_data',
    ];

    private const DUPLICATE_SKU_OBSERVATION = 'Posible carga incompleta, EAN mal cargado o fila duplicada no depurada. No bloquear importación a staging; marcar para revisión.';

    /**
     * Audit a product workbook without writing to the database.
     *
     * @return array<string, mixed>
     */
    public function audit(string $filePath): array
    {
        if (! is_file($filePath)) {
            throw new InvalidArgumentException("El archivo Excel no existe: {$filePath}");
        }

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $spreadsheet = $reader->load($filePath);

        try {
            $sheetNames = $spreadsheet->getSheetNames();
            $sheet = $spreadsheet->getSheetByName(self::MAIN_SHEET);

            if (! $sheet instanceof Worksheet) {
                return $this->emptyReport($sheetNames);
            }

            return $this->auditSheet($sheetNames, $sheet);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @param  array<int, string>  $sheetNames
     * @return array<string, mixed>
     */
    private function auditSheet(array $sheetNames, Worksheet $sheet): array
    {
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
        [$headers, $columnIndexes] = $this->readHeaders($sheet, $highestColumnIndex);
        $missingHeaders = array_values(array_filter(
            self::EXPECTED_HEADERS,
            fn (string $header): bool => ! array_key_exists($this->normalizeHeader($header), $columnIndexes),
        ));

        $metrics = $this->initialMetrics();
        $skuRows = [];
        $eanRows = [];

        for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
            $row = $this->readExpectedRow($sheet, $rowNumber, $columnIndexes);

            if (! $this->isProductRow($row)) {
                continue;
            }

            $metrics['product_rows']++;
            $this->auditRow($row, $metrics);
            $this->collectDuplicateCandidate($skuRows, $row['Sku'], $rowNumber, $row);
            $this->collectDuplicateCandidate($eanRows, $row['Ean'], $rowNumber, $row);
        }

        $skuDuplicates = $this->duplicateGroups($skuRows);
        $eanDuplicates = $this->duplicateGroups($eanRows);
        $metrics['duplicated_sku_groups'] = count($skuDuplicates);
        $metrics['duplicated_sku_rows'] = $this->duplicateRowCount($skuDuplicates);
        $metrics['examples_duplicated_skus'] = $this->duplicateSkuExamples($skuDuplicates);
        $metrics['duplicated_ean_groups'] = count($eanDuplicates);
        $metrics['duplicated_ean_rows'] = $this->duplicateRowCount($eanDuplicates);

        return array_merge([
            'status' => $missingHeaders === [] ? 'ok' : 'failed',
            'total_sheets' => count($sheetNames),
            'sheet_names' => $sheetNames,
            'main_sheet' => self::MAIN_SHEET,
            'used_range' => "A1:{$highestColumn}{$highestRow}",
            'highest_row' => $highestRow,
            'highest_column' => $highestColumn,
            'headers_detected' => $headers,
            'missing_headers' => $missingHeaders,
            'total_rows' => max(0, $highestRow - 1),
        ], $metrics, [
            'mapping' => self::STAGING_MAPPING,
            'duplicate_skus_are_blocking' => false,
        ]);
    }

    /**
     * @param  array<int, string>  $sheetNames
     * @return array<string, mixed>
     */
    private function emptyReport(array $sheetNames): array
    {
        return array_merge([
            'status' => 'failed',
            'total_sheets' => count($sheetNames),
            'sheet_names' => $sheetNames,
            'main_sheet' => null,
            'used_range' => null,
            'highest_row' => 0,
            'highest_column' => null,
            'headers_detected' => [],
            'missing_headers' => self::EXPECTED_HEADERS,
            'total_rows' => 0,
        ], $this->initialMetrics(), [
            'mapping' => self::STAGING_MAPPING,
            'duplicate_skus_are_blocking' => false,
        ]);
    }

    /**
     * @return array{0: array<int, string>, 1: array<string, int>}
     */
    private function readHeaders(Worksheet $sheet, int $highestColumnIndex): array
    {
        $headers = [];
        $columnIndexes = [];

        for ($column = 1; $column <= $highestColumnIndex; $column++) {
            $header = $this->collapseWhitespace($this->cellValue($sheet, $column, 1));

            if ($header === '') {
                continue;
            }

            $headers[] = $header;
            $columnIndexes[$this->normalizeHeader($header)] ??= $column;
        }

        return [$headers, $columnIndexes];
    }

    /**
     * @param  array<string, int>  $columnIndexes
     * @return array<string, string>
     */
    private function readExpectedRow(Worksheet $sheet, int $rowNumber, array $columnIndexes): array
    {
        $row = [];

        foreach (self::EXPECTED_HEADERS as $header) {
            $column = $columnIndexes[$this->normalizeHeader($header)] ?? null;
            $row[$header] = $column === null ? '' : $this->cellValue($sheet, $column, $rowNumber);
        }

        return $row;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function isProductRow(array $row): bool
    {
        foreach ($row as $value) {
            if (! $this->isBlank($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, int|array<int, array<string, mixed>>>  $metrics
     */
    private function auditRow(array $row, array &$metrics): void
    {
        $sku = trim($row['Sku']);
        $name = trim($row['Nombre Sku']);
        $uxb = trim($row['UXB']);
        $ean = trim($row['Ean']);
        $brand = trim($row['Marca']);

        $metrics['empty_sku_rows'] += (int) ($sku === '');
        $metrics['empty_nombre_sku_rows'] += (int) ($name === '');
        $metrics['uxb_empty_rows'] += (int) ($uxb === '');
        $metrics['uxb_non_numeric_rows'] += (int) ($uxb !== '' && ! $this->isNumericValue($uxb));
        $metrics['uxb_zero_rows'] += (int) ($uxb !== '' && $this->isNumericValue($uxb) && $this->numericValue($uxb) === 0.0);
        $metrics['ean_empty_rows'] += (int) ($ean === '');
        $metrics['ean_one_rows'] += (int) ($ean === '1');
        $metrics['ean_two_rows'] += (int) ($ean === '2');
        $metrics['ean_invalid_length_rows'] += (int) ($ean !== '' && preg_match('/^(?:\d{8}|\d{12}|\d{13}|\d{14})$/D', $ean) !== 1);
        $metrics['categoria_zero_rows'] += (int) $this->isZeroOrBlank($row['Categoria']);
        $metrics['grupo_zero_rows'] += (int) $this->isZeroOrBlank($row['Grupo']);
        $metrics['familia_zero_rows'] += (int) $this->isZeroOrBlank($row['Familia']);
        $metrics['marca_zero_rows'] += (int) $this->isZeroOrBlank($row['Marca']);
        $metrics['rows_with_slash_in_nombre_sku'] += (int) str_contains($name, '/');
        $metrics['rows_with_dot_in_nombre_sku'] += (int) str_contains($name, '.');
        $metrics['rows_with_double_spaces_in_nombre_sku'] += (int) (preg_match('/ {2,}/', $row['Nombre Sku']) === 1);
        $metrics['rows_with_mx_in_nombre_sku'] += (int) (preg_match('/(?<![\p{L}\p{N}_])MX(?![\p{L}\p{N}_])/iu', $name) === 1);
        $metrics['rows_with_arlistan_brand'] += (int) (mb_strtolower($brand, 'UTF-8') === 'arlistan');
        $metrics['rows_with_manon_brand'] += (int) (mb_strtolower($brand, 'UTF-8') === 'manon');
    }

    /**
     * @param  array<string, array<int, array{row_number: int, row: array<string, string>}>>  $groups
     * @param  array<string, string>  $row
     */
    private function collectDuplicateCandidate(array &$groups, string $value, int $rowNumber, array $row): void
    {
        $normalized = trim($value);

        if ($normalized === '') {
            return;
        }

        $groups[$normalized][] = [
            'row_number' => $rowNumber,
            'row' => $row,
        ];
    }

    /**
     * @param  array<string, array<int, array{row_number: int, row: array<string, string>}>>  $groups
     * @return array<string, array<int, array{row_number: int, row: array<string, string>}>>
     */
    private function duplicateGroups(array $groups): array
    {
        return array_filter($groups, static fn (array $rows): bool => count($rows) > 1);
    }

    /**
     * @param  array<string, array<int, array{row_number: int, row: array<string, string>}>>  $groups
     */
    private function duplicateRowCount(array $groups): int
    {
        return array_sum(array_map('count', $groups));
    }

    /**
     * @param  array<string, array<int, array{row_number: int, row: array<string, string>}>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function duplicateSkuExamples(array $groups): array
    {
        $examples = [];

        foreach (array_slice($groups, 0, 10, true) as $sku => $rows) {
            $examples[] = [
                'sku' => $sku,
                'row_numbers' => array_column($rows, 'row_number'),
                'nombre_sku_values' => array_map(
                    fn (array $item): string => trim($item['row']['Nombre Sku']),
                    $rows,
                ),
                'ean_values' => array_map(
                    fn (array $item): string => trim($item['row']['Ean']),
                    $rows,
                ),
                'marca_values' => array_map(
                    fn (array $item): string => trim($item['row']['Marca']),
                    $rows,
                ),
                'observacion_sugerida' => self::DUPLICATE_SKU_OBSERVATION,
            ];
        }

        return $examples;
    }

    private function cellValue(Worksheet $sheet, int $column, int $row): string
    {
        $value = $sheet->getCell([$column, $row])->getFormattedValue();

        if ($value instanceof RichText) {
            return $value->getPlainText();
        }

        return (string) $value;
    }

    private function normalizeHeader(string $header): string
    {
        return mb_strtolower($this->collapseWhitespace($header), 'UTF-8');
    }

    private function collapseWhitespace(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }

    private function isBlank(string $value): bool
    {
        return trim($value) === '';
    }

    private function isNumericValue(string $value): bool
    {
        return preg_match('/^[+-]?(?:\d+(?:[.,]\d+)?|[.,]\d+)$/D', $value) === 1;
    }

    private function numericValue(string $value): float
    {
        return (float) str_replace(',', '.', $value);
    }

    private function isZeroOrBlank(string $value): bool
    {
        $value = trim($value);

        return $value === '' || $value === '0';
    }

    /**
     * @return array<string, int|array<int, array<string, mixed>>>
     */
    private function initialMetrics(): array
    {
        return [
            'product_rows' => 0,
            'empty_sku_rows' => 0,
            'duplicated_sku_groups' => 0,
            'duplicated_sku_rows' => 0,
            'examples_duplicated_skus' => [],
            'empty_nombre_sku_rows' => 0,
            'uxb_empty_rows' => 0,
            'uxb_non_numeric_rows' => 0,
            'uxb_zero_rows' => 0,
            'ean_empty_rows' => 0,
            'ean_one_rows' => 0,
            'ean_two_rows' => 0,
            'ean_invalid_length_rows' => 0,
            'duplicated_ean_groups' => 0,
            'duplicated_ean_rows' => 0,
            'categoria_zero_rows' => 0,
            'grupo_zero_rows' => 0,
            'familia_zero_rows' => 0,
            'marca_zero_rows' => 0,
            'rows_with_slash_in_nombre_sku' => 0,
            'rows_with_dot_in_nombre_sku' => 0,
            'rows_with_double_spaces_in_nombre_sku' => 0,
            'rows_with_mx_in_nombre_sku' => 0,
            'rows_with_arlistan_brand' => 0,
            'rows_with_manon_brand' => 0,
        ];
    }
}
