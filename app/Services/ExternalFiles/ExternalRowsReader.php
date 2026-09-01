<?php

namespace App\Services\ExternalFiles;

use App\Services\Audits\ExternalFormatSamplesAuditService;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\StringHelper;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ExternalRowsReader
{
    public function __construct(
        private readonly ExternalFormatSamplesAuditService $auditService,
    ) {}

    /** @return array<string, mixed> */
    public function read(string $filePath, ?string $workflowType = null): array
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            throw new InvalidArgumentException("No se puede leer el archivo externo: {$filePath}");
        }

        $format = mb_strtolower(pathinfo($filePath, PATHINFO_EXTENSION), 'UTF-8');
        $workflowType ??= $this->inferWorkflowType(basename($filePath));

        return match ($format) {
            'txt' => $this->readTextFile($filePath, $workflowType),
            'xlsx' => $this->readWorkbook($filePath, $workflowType),
            default => throw new InvalidArgumentException("Formato externo no soportado: {$format}"),
        };
    }

    /**
     * @param  array<string, mixed>  $readResult
     * @return list<array<string, mixed>>
     */
    public function rowsForPriceMap(array $readResult): array
    {
        return array_values(array_map(
            static fn (array $row): array => $row['data'],
            $readResult['rows'] ?? [],
        ));
    }

    /** @return array<string, mixed> */
    private function readTextFile(string $filePath, string $workflowType): array
    {
        $bytes = file_get_contents($filePath);

        if ($bytes === false) {
            throw new InvalidArgumentException("No se puede leer el TXT externo: {$filePath}");
        }

        $encoding = $this->detectEncoding($bytes);
        $text = $this->toUtf8($bytes, $encoding);
        $text = preg_replace('/^\x{FEFF}/u', '', $text) ?? $text;
        $lines = preg_split('/\R/u', $text) ?: [];
        $headerIndex = array_search(true, array_map(
            static fn (string $line): bool => trim($line) !== '',
            $lines,
        ), true);

        if ($headerIndex === false) {
            return $this->emptyResult($filePath, 'txt', $workflowType, $encoding, 'none');
        }

        $headerLine = $lines[$headerIndex];
        $delimiter = $this->delimiter($headerLine);
        $rawHeaders = str_getcsv($headerLine, $delimiter['character'], '"', '');
        $headers = $this->safeHeaders($rawHeaders, $workflowType);
        $rows = [];
        $warnings = [];

        foreach (array_slice($lines, $headerIndex + 1, null, true) as $lineIndex => $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line, $delimiter['character'], '"', '');
            $rowNumber = $lineIndex + 1;

            if (count($values) !== count($headers)) {
                $warnings[] = [
                    'issue' => 'irregular_column_count',
                    'severity' => 'review',
                    'requires_review' => true,
                    'row_number' => $rowNumber,
                    'columns' => count($values),
                    'expected_columns' => count($headers),
                    'recommendation' => 'review_source_row',
                ];
            }

            $rows[] = $this->rowEnvelope(
                $rowNumber,
                basename($filePath),
                null,
                $workflowType,
                $this->mapRow($headers, $values),
            );
        }

        return [
            'rows' => $rows,
            'warnings' => $warnings,
            'requires_review' => $warnings !== [],
            'metadata' => [
                'source_file' => basename($filePath),
                'source_path' => realpath($filePath) ?: $filePath,
                'format' => 'txt',
                'workflow_type' => $workflowType,
                'delimiter' => $delimiter['label'],
                'encoding' => $encoding,
                'column_count' => count($headers),
                'headers' => $headers,
                'raw_headers' => $rawHeaders,
                'row_count' => count($rows),
                'ignored_secondary_block_count' => 0,
                'duplicate_catalog_sections' => [],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function readWorkbook(string $filePath, string $workflowType): array
    {
        $audit = $this->auditService->auditWorkbook($filePath, workflowType: $workflowType);
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($filePath);
        $rows = [];
        $columnCounts = [];
        $firstHeaders = null;
        $firstRawHeaders = null;

        foreach ($audit['sheets'] as $sheetAudit) {
            $table = $sheetAudit['first_table'] ?? null;

            if ($table === null) {
                continue;
            }

            $sheet = $spreadsheet->getSheetByName($sheetAudit['name']);

            if ($sheet === null) {
                continue;
            }

            $rawHeaders = array_map(
                static fn (array $column): string => (string) $column['header'],
                $table['columns'],
            );
            $headers = $this->safeHeaders($rawHeaders, $workflowType);
            $firstHeaders ??= $headers;
            $firstRawHeaders ??= $rawHeaders;
            $columnCounts[] = count($headers);

            for ($rowNumber = $table['header_row'] + 1; $rowNumber <= $table['end_row']; $rowNumber++) {
                $values = [];

                for ($column = 1; $column <= count($headers); $column++) {
                    $cell = $sheet->getCell([$column, $rowNumber]);
                    $value = $this->isPriceHeader($headers[$column - 1])
                        ? $this->formattedPriceValue($cell)
                        : $this->dataOnlyValue($cell);
                    $values[] = trim((string) $value);
                }

                if ($this->isEmptyRow($values)) {
                    continue;
                }

                $rows[] = $this->rowEnvelope(
                    $rowNumber,
                    basename($filePath),
                    $sheetAudit['name'],
                    $workflowType,
                    $this->mapRow($headers, $values),
                );
            }
        }

        $spreadsheet->disconnectWorksheets();
        $duplicateSections = $workflowType === ExternalFormatSamplesAuditService::WORKFLOW_CATALOG_BODY
            ? $audit['duplicate_catalog_sections']
            : [];
        $warnings = array_values(array_map(
            static fn (array $duplicate): array => [
                'issue' => 'duplicate_catalog_section',
                'severity' => 'blocked',
                'requires_review' => true,
                'section' => $duplicate['normalized_section'],
                'detected_blocks' => $duplicate['detected_blocks'],
                'origins' => $duplicate['origins'],
                'recommendation' => 'manual_selection',
                'message' => $duplicate['message'],
            ],
            $duplicateSections,
        ));

        return [
            'rows' => $rows,
            'warnings' => $warnings,
            'requires_review' => $warnings !== [],
            'metadata' => [
                'source_file' => basename($filePath),
                'source_path' => realpath($filePath) ?: $filePath,
                'format' => 'xlsx',
                'workflow_type' => $workflowType,
                'delimiter' => null,
                'encoding' => null,
                'column_count' => count(array_unique($columnCounts)) === 1
                    ? ($columnCounts[0] ?? 0)
                    : null,
                'column_counts' => array_values(array_unique($columnCounts)),
                'headers' => $firstHeaders,
                'raw_headers' => $firstRawHeaders,
                'row_count' => count($rows),
                'sheet_count' => $audit['sheet_count'],
                'sheets_read' => array_values(array_unique(array_column($rows, 'source_sheet'))),
                'ignored_secondary_block_count' => count($audit['secondary_blocks']),
                'duplicate_catalog_sections' => $duplicateSections,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyResult(
        string $filePath,
        string $format,
        string $workflowType,
        ?string $encoding,
        ?string $delimiter,
    ): array {
        return [
            'rows' => [],
            'warnings' => [],
            'requires_review' => false,
            'metadata' => [
                'source_file' => basename($filePath),
                'source_path' => realpath($filePath) ?: $filePath,
                'format' => $format,
                'workflow_type' => $workflowType,
                'delimiter' => $delimiter,
                'encoding' => $encoding,
                'column_count' => 0,
                'headers' => [],
                'raw_headers' => [],
                'row_count' => 0,
                'ignored_secondary_block_count' => 0,
                'duplicate_catalog_sections' => [],
            ],
        ];
    }

    /** @param list<string> $headers */
    private function safeHeaders(array $headers, string $workflowType): array
    {
        $safeHeaders = [];
        $occurrences = [];

        foreach ($headers as $index => $header) {
            $base = $this->logicalHeader($header, $workflowType);
            $base = $base !== '' ? $base : 'column_'.($index + 1);
            $occurrenceKey = mb_strtolower($base, 'UTF-8');
            $occurrences[$occurrenceKey] = ($occurrences[$occurrenceKey] ?? 0) + 1;
            $safeHeaders[] = $occurrences[$occurrenceKey] === 1
                ? $base
                : $base.'_'.$occurrences[$occurrenceKey];
        }

        return $safeHeaders;
    }

    private function logicalHeader(string $header, string $workflowType): string
    {
        $trimmed = trim($header);
        $compact = preg_replace(
            '/[^a-z0-9]+/u',
            '',
            mb_strtolower(Str::ascii($trimmed), 'UTF-8'),
        ) ?? '';

        return match ($compact) {
            'codigo' => 'CODIGO',
            'sku' => 'SKU',
            'categoria' => 'CATEGORIA',
            'grupo' => 'GRUPO',
            'marca' => 'MARCA',
            'descripcion' => 'DESCRIPCION',
            'uxb' => 'UXB',
            'preciolista' => 'PRECIOLISTA',
            'preciooferta' => 'PRECIOOFERTA',
            'preciotachado' => 'PRECIOTACHADO',
            'precio' => $workflowType === ExternalFormatSamplesAuditService::WORKFLOW_PROMO_TAPA
                ? 'PRECIOOFERTA'
                : 'PRECIOLISTA',
            default => $trimmed,
        };
    }

    /** @param list<string> $headers */
    private function mapRow(array $headers, array $values): array
    {
        $mapped = [];

        foreach ($headers as $index => $header) {
            $mapped[$header] = isset($values[$index]) ? trim((string) $values[$index]) : '';
        }

        return $mapped;
    }

    /** @return array<string, mixed> */
    private function rowEnvelope(
        int $rowNumber,
        string $sourceFile,
        ?string $sourceSheet,
        string $workflowType,
        array $data,
    ): array {
        return [
            'row_number' => $rowNumber,
            'source_file' => $sourceFile,
            'source_sheet' => $sourceSheet,
            'workflow_type' => $workflowType,
            'data' => $data,
        ];
    }

    private function isEmptyRow(array $values): bool
    {
        return count(array_filter(
            $values,
            static fn (mixed $value): bool => trim((string) $value) !== '',
        )) === 0;
    }

    private function isPriceHeader(string $header): bool
    {
        return in_array($header, ['PRECIOLISTA', 'PRECIOOFERTA', 'PRECIOTACHADO'], true);
    }

    private function formattedPriceValue(Cell $cell): string
    {
        $decimalSeparator = StringHelper::getDecimalSeparator();
        $thousandsSeparator = StringHelper::getThousandsSeparator();

        try {
            StringHelper::setDecimalSeparator(',');
            StringHelper::setThousandsSeparator('.');

            return NumberFormat::toFormattedString(
                $this->dataOnlyValue($cell),
                $cell->getStyle()->getNumberFormat()->getFormatCode(),
            );
        } finally {
            StringHelper::setDecimalSeparator($decimalSeparator);
            StringHelper::setThousandsSeparator($thousandsSeparator);
        }
    }

    private function dataOnlyValue(Cell $cell): mixed
    {
        return $cell->isFormula()
            ? $cell->getOldCalculatedValue()
            : $cell->getValue();
    }

    /** @return array{character: string, label: string} */
    private function delimiter(string $headerLine): array
    {
        $tabColumns = count(str_getcsv($headerLine, "\t", '"', ''));
        $semicolonColumns = count(str_getcsv($headerLine, ';', '"', ''));

        if ($tabColumns > 1 && $tabColumns >= $semicolonColumns) {
            return ['character' => "\t", 'label' => 'tab'];
        }

        if ($semicolonColumns > 1) {
            return ['character' => ';', 'label' => 'semicolon'];
        }

        return ['character' => "\t", 'label' => 'none'];
    }

    private function detectEncoding(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return 'UTF-8 BOM';
        }

        if (str_starts_with($bytes, "\xFF\xFE")) {
            return 'UTF-16LE BOM';
        }

        if (str_starts_with($bytes, "\xFE\xFF")) {
            return 'UTF-16BE BOM';
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return 'UTF-8';
        }

        return mb_detect_encoding($bytes, ['Windows-1252', 'ISO-8859-1'], true)
            ?: 'Windows-1252';
    }

    private function toUtf8(string $bytes, string $encoding): string
    {
        $sourceEncoding = match ($encoding) {
            'UTF-8 BOM' => 'UTF-8',
            'UTF-16LE BOM' => 'UTF-16LE',
            'UTF-16BE BOM' => 'UTF-16BE',
            default => $encoding,
        };

        return $sourceEncoding === 'UTF-8'
            ? $bytes
            : mb_convert_encoding($bytes, 'UTF-8', $sourceEncoding);
    }

    private function inferWorkflowType(string $fileName): string
    {
        $normalized = mb_strtolower(Str::ascii(preg_replace('/\s+/u', ' ', trim($fileName)) ?? $fileName), 'UTF-8');

        if ($normalized === mb_strtolower(Str::ascii(ExternalFormatSamplesAuditService::CATALOG_INPUT), 'UTF-8')
            || preg_match('/\sint\.txt$/u', $normalized) === 1) {
            return ExternalFormatSamplesAuditService::WORKFLOW_CATALOG_BODY;
        }

        if (in_array($normalized, [
            mb_strtolower(Str::ascii(ExternalFormatSamplesAuditService::PROMOTIONS_INPUT), 'UTF-8'),
            mb_strtolower(Str::ascii(ExternalFormatSamplesAuditService::PROMOTIONS_OUTPUT), 'UTF-8'),
        ], true)) {
            return ExternalFormatSamplesAuditService::WORKFLOW_PROMO_TAPA;
        }

        return ExternalFormatSamplesAuditService::WORKFLOW_UNKNOWN;
    }
}
