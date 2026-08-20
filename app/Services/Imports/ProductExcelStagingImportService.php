<?php

namespace App\Services\Imports;

use App\Models\ImportBatch;
use App\Models\ImportFile;
use App\Models\ProductStagingRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductExcelStagingImportService
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

    public function __construct(
        private readonly ProductExcelAuditService $auditService,
    ) {}

    /**
     * Import product rows to staging, or calculate the same result without writes.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function import(string $filePath, array $options = []): array
    {
        $options = array_merge([
            'dry_run' => false,
            'batch_name' => null,
            'source_type' => 'product_excel',
            'uploaded_by_id' => null,
            'replace_existing' => false,
        ], $options);

        $audit = $this->auditService->audit($filePath);

        if ($audit['status'] !== 'ok') {
            return $this->failedResult($audit, $options);
        }

        $preparedRows = $this->prepareRows($filePath);
        $existingHashes = $this->existingHashes(array_column($preparedRows, 'row_hash'));
        $newRows = array_values(array_filter(
            $preparedRows,
            static fn (array $row): bool => ! isset($existingHashes[$row['row_hash']]),
        ));
        $skippedRows = count($preparedRows) - count($newRows);
        $candidateReviewRows = $this->reviewRowCount($preparedRows);
        $newReviewRows = $this->reviewRowCount($newRows);

        $summary = [
            'status' => $options['dry_run'] ? 'dry_run' : 'completed',
            'dry_run' => (bool) $options['dry_run'],
            'file' => $filePath,
            'audit_status' => $audit['status'],
            'product_rows' => count($preparedRows),
            'would_import_rows' => count($newRows),
            'imported_rows' => 0,
            'skipped_existing_rows' => $skippedRows,
            'requires_review_rows' => $newReviewRows,
            'candidate_requires_review_rows' => $candidateReviewRows,
            'pending_rows' => count($newRows) - $newReviewRows,
            'batch_id' => null,
            'file_id' => null,
            'replace_existing_requested' => (bool) $options['replace_existing'],
            'replace_existing_applied' => false,
            'warnings' => $options['replace_existing']
                ? ['replace_existing no elimina datos: los row_hash existentes se omiten para preservar el historial.']
                : [],
            'errors' => [],
            'message' => $options['dry_run']
                ? 'Dry-run completado sin escrituras en la base de datos.'
                : null,
        ];

        if ($options['dry_run']) {
            return $summary;
        }

        if ($newRows === []) {
            return $this->alreadyImportedResult($summary);
        }

        return DB::transaction(function () use (
            $audit,
            $candidateReviewRows,
            $filePath,
            $newRows,
            $options,
            $preparedRows,
            $summary,
        ): array {
            $currentHashes = $this->existingHashes(array_column($newRows, 'row_hash'), true);
            $rowsToImport = array_values(array_filter(
                $newRows,
                static fn (array $row): bool => ! isset($currentHashes[$row['row_hash']]),
            ));
            $concurrentSkippedRows = count($newRows) - count($rowsToImport);

            if ($rowsToImport === []) {
                return $this->alreadyImportedResult($summary, $concurrentSkippedRows);
            }

            $startedAt = now();
            $importedReviewRows = $this->reviewRowCount($rowsToImport);

            $batch = ImportBatch::query()->create([
                'name' => filled($options['batch_name'])
                    ? (string) $options['batch_name']
                    : 'Importación Excel de productos '.$startedAt->format('Y-m-d H:i:s'),
                'process_type' => 'product_excel_import',
                'source_type' => (string) $options['source_type'],
                'status' => 'completed',
                'notes' => $this->batchNotes(
                    count($rowsToImport),
                    $summary['skipped_existing_rows'] + $concurrentSkippedRows,
                    $importedReviewRows,
                ),
                'uploaded_by_id' => $options['uploaded_by_id'],
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            $file = ImportFile::query()->create([
                'import_batch_id' => $batch->getKey(),
                'original_name' => basename($filePath),
                'stored_path' => $this->storedPath($filePath),
                'file_type' => strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) ?: 'xlsx',
                'total_rows' => count($preparedRows),
                'valid_rows' => count($preparedRows) - $candidateReviewRows,
                'error_rows' => $candidateReviewRows,
                'meta' => $this->fileMeta(
                    $audit,
                    count($rowsToImport),
                    $summary['skipped_existing_rows'] + $concurrentSkippedRows,
                ),
            ]);

            foreach ($rowsToImport as $row) {
                ProductStagingRow::query()->create(array_merge($row['attributes'], [
                    'import_batch_id' => $batch->getKey(),
                    'import_file_id' => $file->getKey(),
                ]));
            }

            return array_merge($summary, [
                'imported_rows' => count($rowsToImport),
                'would_import_rows' => count($rowsToImport),
                'skipped_existing_rows' => $summary['skipped_existing_rows'] + $concurrentSkippedRows,
                'requires_review_rows' => $importedReviewRows,
                'pending_rows' => count($rowsToImport) - $importedReviewRows,
                'batch_id' => $batch->getKey(),
                'file_id' => $file->getKey(),
                'message' => 'Importación Excel a staging completada.',
            ]);
        });
    }

    /**
     * @return array<int, array{row_hash: string, attributes: array<string, mixed>}>
     */
    private function prepareRows(string $filePath): array
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $spreadsheet = $reader->load($filePath);

        try {
            $sheet = $spreadsheet->getSheetByName(self::MAIN_SHEET);

            if (! $sheet instanceof Worksheet) {
                return [];
            }

            $headers = $this->headers($sheet);
            $rows = [];

            for ($rowNumber = 2; $rowNumber <= $sheet->getHighestDataRow(); $rowNumber++) {
                $canonical = $this->canonicalRow($sheet, $headers, $rowNumber);

                if (! $this->isProductRow($canonical)) {
                    continue;
                }

                $rows[] = [
                    'row_number' => $rowNumber,
                    'canonical' => $canonical,
                    'raw_data' => $this->rawRow($sheet, $headers, $rowNumber),
                ];
            }

            return $this->finalizeRows($rows);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @return array<int, array{column: int, label: string, normalized: string}>
     */
    private function headers(Worksheet $sheet): array
    {
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $headers = [];

        for ($column = 1; $column <= $highestColumn; $column++) {
            $label = $this->cellValue($sheet, $column, 1);

            if (trim($label) === '') {
                continue;
            }

            $headers[] = [
                'column' => $column,
                'label' => $label,
                'normalized' => $this->normalizeHeader($label),
            ];
        }

        return $headers;
    }

    /**
     * @param  array<int, array{column: int, label: string, normalized: string}>  $headers
     * @return array<string, string>
     */
    private function canonicalRow(Worksheet $sheet, array $headers, int $rowNumber): array
    {
        $columns = [];

        foreach ($headers as $header) {
            $columns[$header['normalized']] ??= $header['column'];
        }

        $row = [];

        foreach (self::EXPECTED_HEADERS as $expectedHeader) {
            $column = $columns[$this->normalizeHeader($expectedHeader)];
            $row[$expectedHeader] = $this->cellValue($sheet, $column, $rowNumber);
        }

        return $row;
    }

    /**
     * @param  array<int, array{column: int, label: string, normalized: string}>  $headers
     * @return array<string, mixed>
     */
    private function rawRow(Worksheet $sheet, array $headers, int $rowNumber): array
    {
        $row = [];

        foreach ($headers as $header) {
            $row[$header['label']] = $this->rawCellValue($sheet, $header['column'], $rowNumber);
        }

        return $row;
    }

    /**
     * @param  array<int, array{row_number: int, canonical: array<string, string>, raw_data: array<string, mixed>}>  $rows
     * @return array<int, array{row_hash: string, attributes: array<string, mixed>}>
     */
    private function finalizeRows(array $rows): array
    {
        $skuCounts = [];

        foreach ($rows as $row) {
            $sku = trim($row['canonical']['Sku']);

            if ($sku !== '') {
                $skuCounts[$sku] = ($skuCounts[$sku] ?? 0) + 1;
            }
        }

        return array_map(function (array $row) use ($skuCounts): array {
            $canonical = $row['canonical'];
            $reasons = $this->reviewReasons($canonical, $skuCounts);
            $rowHash = $this->rowHash($canonical, $row['row_number']);

            return [
                'row_hash' => $rowHash,
                'attributes' => [
                    'import_row_id' => null,
                    'master_product_id' => null,
                    'codigo_producto_original' => $canonical['Sku'],
                    'nombre_sku_original' => $canonical['Nombre Sku'],
                    'uxb_original' => $canonical['UXB'],
                    'ean_original' => $canonical['Ean'],
                    'categoria_original' => $canonical['Categoria'],
                    'grupo_original' => $canonical['Grupo'],
                    'familia_original' => $canonical['Familia'],
                    'marca_original' => $canonical['Marca'],
                    'raw_data' => $row['raw_data'],
                    'status' => $reasons === [] ? 'pending' : 'requires_review',
                    'requires_review' => $reasons !== [],
                    'review_reason' => $reasons === [] ? null : implode('; ', $reasons),
                    'row_hash' => $rowHash,
                ],
            ];
        }, $rows);
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, int>  $skuCounts
     * @return array<int, string>
     */
    private function reviewReasons(array $row, array $skuCounts): array
    {
        $reasons = [];
        $sku = trim($row['Sku']);
        $name = trim($row['Nombre Sku']);
        $uxb = trim($row['UXB']);

        if ($sku === '') {
            $reasons[] = 'SKU vacío';
        } elseif (($skuCounts[$sku] ?? 0) > 1) {
            $reasons[] = 'SKU duplicado en base origen';
        }

        if ($name === '') {
            $reasons[] = 'Nombre Sku vacío';
        }

        if ($uxb === '') {
            $reasons[] = 'UXB vacío';
        } elseif (! $this->isNumericValue($uxb)) {
            $reasons[] = 'UXB no numérico';
        } elseif ($this->numericValue($uxb) === 0.0) {
            $reasons[] = 'UXB igual a 0';
        }

        foreach (['Categoria', 'Grupo', 'Familia'] as $field) {
            if ($this->isZeroOrBlank($row[$field])) {
                $reasons[] = "{$field} original vacía o 0";
            }
        }

        if ($this->isZeroOrBlank($row['Marca'])) {
            $reasons[] = 'Marca original vacía o 0';
        }

        if (! $this->isValidEan($row['Ean'])) {
            $reasons[] = 'EAN inválido o sospechoso';
        }

        return $reasons;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function rowHash(array $row, int $rowNumber): string
    {
        return hash('sha256', json_encode([
            $row['Sku'],
            $row['Nombre Sku'],
            $row['UXB'],
            $row['Ean'],
            $row['Categoria'],
            $row['Grupo'],
            $row['Familia'],
            $row['Marca'],
            $rowNumber,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<int, string>  $hashes
     * @return array<string, true>
     */
    private function existingHashes(array $hashes, bool $lockForUpdate = false): array
    {
        $existing = [];

        foreach (array_chunk(array_values(array_unique($hashes)), 1000) as $chunk) {
            $query = ProductStagingRow::query()->whereIn('row_hash', $chunk);

            if ($lockForUpdate) {
                $query->lockForUpdate();
            }

            foreach ($query->pluck('row_hash') as $hash) {
                if (is_string($hash)) {
                    $existing[$hash] = true;
                }
            }
        }

        return $existing;
    }

    /**
     * @param  array<int, array{row_hash: string, attributes: array<string, mixed>}>  $rows
     */
    private function reviewRowCount(array $rows): int
    {
        return count(array_filter(
            $rows,
            static fn (array $row): bool => $row['attributes']['requires_review'],
        ));
    }

    private function cellValue(Worksheet $sheet, int $column, int $row): string
    {
        $value = $sheet->getCell([$column, $row])->getFormattedValue();

        if ($value instanceof RichText) {
            return $value->getPlainText();
        }

        return (string) $value;
    }

    private function rawCellValue(Worksheet $sheet, int $column, int $row): mixed
    {
        $value = $sheet->getCell([$column, $row])->getValue();

        return $value instanceof RichText ? $value->getPlainText() : $value;
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/\s+/u', ' ', trim($header)) ?? trim($header);

        return mb_strtolower($header, 'UTF-8');
    }

    /**
     * @param  array<string, string>  $row
     */
    private function isProductRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim($value) !== '') {
                return true;
            }
        }

        return false;
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

    private function isValidEan(string $value): bool
    {
        $value = preg_replace('/\s+/u', '', trim($value)) ?? trim($value);

        return preg_match('/^(?:\d{8}|\d{12}|\d{13}|\d{14})$/D', $value) === 1;
    }

    private function storedPath(string $filePath): string
    {
        $normalizedPath = str_replace('\\', '/', $filePath);
        $normalizedBasePath = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        return Str::startsWith($normalizedPath, $normalizedBasePath)
            ? Str::after($normalizedPath, $normalizedBasePath)
            : $normalizedPath;
    }

    private function batchNotes(int $importedRows, int $skippedRows, int $reviewRows): string
    {
        return "Importación segura de Excel a staging: {$importedRows} filas importadas, "
            ."{$skippedRows} omitidas por row_hash y {$reviewRows} para revisión.";
    }

    /**
     * @param  array<string, mixed>  $audit
     * @return array<string, mixed>
     */
    private function fileMeta(array $audit, int $importedRows, int $skippedRows): array
    {
        return [
            'audit_status' => $audit['status'],
            'main_sheet' => $audit['main_sheet'],
            'headers_detected' => $audit['headers_detected'],
            'missing_headers' => $audit['missing_headers'],
            'duplicated_sku_groups' => $audit['duplicated_sku_groups'],
            'duplicated_ean_groups' => $audit['duplicated_ean_groups'],
            'imported_rows' => $importedRows,
            'skipped_existing_rows' => $skippedRows,
        ];
    }

    /**
     * @param  array<string, mixed>  $audit
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function failedResult(array $audit, array $options): array
    {
        return [
            'status' => 'failed',
            'dry_run' => (bool) $options['dry_run'],
            'audit_status' => $audit['status'],
            'product_rows' => $audit['product_rows'],
            'would_import_rows' => 0,
            'imported_rows' => 0,
            'skipped_existing_rows' => 0,
            'requires_review_rows' => 0,
            'candidate_requires_review_rows' => 0,
            'pending_rows' => 0,
            'batch_id' => null,
            'file_id' => null,
            'replace_existing_requested' => (bool) $options['replace_existing'],
            'replace_existing_applied' => false,
            'warnings' => [],
            'errors' => ['La estructura del Excel no es válida. Columnas faltantes: '.implode(', ', $audit['missing_headers'])],
            'message' => 'La importación se detuvo porque la estructura del Excel no es válida.',
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function alreadyImportedResult(array $summary, int $additionalSkippedRows = 0): array
    {
        return array_merge($summary, [
            'status' => 'already_imported',
            'would_import_rows' => 0,
            'imported_rows' => 0,
            'skipped_existing_rows' => $summary['skipped_existing_rows'] + $additionalSkippedRows,
            'requires_review_rows' => 0,
            'pending_rows' => 0,
            'batch_id' => null,
            'file_id' => null,
            'message' => 'No se crearon batch/file porque todas las filas ya existían por row_hash.',
        ]);
    }
}
