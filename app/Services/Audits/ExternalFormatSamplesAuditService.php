<?php

namespace App\Services\Audits;

use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExternalFormatSamplesAuditService
{
    public const WORKFLOW_CATALOG_BODY = 'catalog_body';

    public const WORKFLOW_PROMO_TAPA = 'promo_tapa';

    public const WORKFLOW_UNKNOWN = 'unknown';

    public const CATALOG_INPUT = '08.Cuerpo Int 24.08.xlsx';

    public const PROMOTIONS_INPUT = 'Libro3.xlsx';

    public const PROMOTIONS_OUTPUT = 'TAPA AMBA(1).txt';

    public const CATALOG_OUTPUTS = [
        'ALMACEN INT.txt',
        'BEBIDAS CON AL  INT.txt',
        'BEBIDAS SIN AL  INT.txt',
        'DESAYUNO  INT.txt',
        'GASTRO INT.txt',
        'IMPORTADOS INT.txt',
        'Limpieza INT.txt',
        'NON FOOD INT.txt',
        'Perfumeria INT.txt',
    ];

    /**
     * @return array<string, mixed>
     */
    public function auditDirectory(string $basePath, int $sampleSize = 5): array
    {
        $basePath = $this->resolvedDirectory($basePath);
        $sampleSize = max(1, $sampleSize);
        $expectedFiles = [
            self::CATALOG_INPUT,
            ...self::CATALOG_OUTPUTS,
            self::PROMOTIONS_INPUT,
            self::PROMOTIONS_OUTPUT,
        ];
        $files = collect(scandir($basePath) ?: [])
            ->reject(static fn (string $name): bool => in_array($name, ['.', '..'], true))
            ->filter(static fn (string $name): bool => is_file($basePath.DIRECTORY_SEPARATOR.$name))
            ->values();
        $fileLookup = $files->keyBy(fn (string $name): string => $this->normalizedFileName($name));
        $detected = [];
        $missing = [];

        foreach ($expectedFiles as $expectedFile) {
            $actualName = $fileLookup->get($this->normalizedFileName($expectedFile));

            if ($actualName === null) {
                $missing[] = $expectedFile;

                continue;
            }

            $detected[$expectedFile] = $actualName;
        }

        $catalogInput = isset($detected[self::CATALOG_INPUT])
            ? $this->auditWorkbook(
                $basePath.DIRECTORY_SEPARATOR.$detected[self::CATALOG_INPUT],
                $sampleSize,
                self::WORKFLOW_CATALOG_BODY,
            )
            : null;
        $catalogOutputs = [];

        foreach (self::CATALOG_OUTPUTS as $expectedOutput) {
            if (! isset($detected[$expectedOutput])) {
                continue;
            }

            $catalogOutputs[$detected[$expectedOutput]] = $this->auditTextFile(
                $basePath.DIRECTORY_SEPARATOR.$detected[$expectedOutput],
                $sampleSize,
                self::WORKFLOW_CATALOG_BODY,
            );
        }

        $promotionsInput = isset($detected[self::PROMOTIONS_INPUT])
            ? $this->auditWorkbook(
                $basePath.DIRECTORY_SEPARATOR.$detected[self::PROMOTIONS_INPUT],
                $sampleSize,
                self::WORKFLOW_PROMO_TAPA,
            )
            : null;
        $promotionsOutput = isset($detected[self::PROMOTIONS_OUTPUT])
            ? $this->auditTextFile(
                $basePath.DIRECTORY_SEPARATOR.$detected[self::PROMOTIONS_OUTPUT],
                $sampleSize,
                self::WORKFLOW_PROMO_TAPA,
            )
            : null;
        $textReports = [...array_values($catalogOutputs)];

        if ($promotionsOutput !== null) {
            $textReports[] = $promotionsOutput;
        }

        $catalogReports = array_values(array_filter([
            $catalogInput,
            ...array_values($catalogOutputs),
        ]));
        $promotionsReports = array_values(array_filter([
            $promotionsInput,
            $promotionsOutput,
        ]));

        return [
            'status' => $missing === [] ? 'ok' : 'partial',
            'read_only' => true,
            'base_path' => $basePath,
            'sample_size' => $sampleSize,
            'expected_files' => $expectedFiles,
            'detected_files' => array_values($detected),
            'missing_files' => $missing,
            'unexpected_files' => $files
                ->reject(fn (string $name): bool => in_array($this->normalizedFileName($name), array_keys($fileLookup->only(
                    array_map(fn (string $name): string => $this->normalizedFileName($name), $expectedFiles),
                )->all()), true))
                ->values()
                ->all(),
            'catalog' => [
                'input' => $catalogInput,
                'outputs' => $catalogOutputs,
                'output_count' => count($catalogOutputs),
                'duplicate_sections' => $catalogInput['duplicate_catalog_sections'] ?? [],
                'automatic_export_blocked_sections' => array_values(array_map(
                    static fn (array $issue): string => $issue['normalized_section'],
                    $catalogInput['duplicate_catalog_sections'] ?? [],
                )),
                'category_correspondence' => $this->categoryCorrespondence($catalogInput, array_keys($catalogOutputs)),
                'same_output_structure' => $this->sameTextStructure($catalogOutputs),
            ],
            'promotions' => [
                'input' => $promotionsInput,
                'output' => $promotionsOutput,
                'output_vs_catalog_outputs' => $this->textStructureComparison(
                    $promotionsOutput,
                    $catalogOutputs,
                ),
            ],
            'totals' => [
                'xlsx_files' => ($catalogInput === null ? 0 : 1) + ($promotionsInput === null ? 0 : 1),
                'catalog_txt_files' => count($catalogOutputs),
                'promotions_txt_files' => $promotionsOutput === null ? 0 : 1,
                'line_types' => $this->sumLineTypes($textReports),
                'classification_counts' => $this->sumClassificationCounts([
                    ...$catalogReports,
                    ...$promotionsReports,
                ]),
                'workbook_line_types' => $this->sumLineTypes(array_values(array_filter([
                    $catalogInput,
                    $promotionsInput,
                ]))),
                'prices' => $this->sumPriceCounts($textReports),
                'irregular_rows' => array_sum(array_column($textReports, 'irregular_row_count')),
            ],
            'workflow_totals' => [
                self::WORKFLOW_CATALOG_BODY => $this->sumClassificationCounts($catalogReports),
                self::WORKFLOW_PROMO_TAPA => $this->sumClassificationCounts($promotionsReports),
            ],
            'rules' => [
                'master_stores_prices' => false,
                'external_file_controls_prices' => true,
                'product_requires_master_lookup' => true,
                'composite_code_requires_master_lookup' => false,
                self::WORKFLOW_CATALOG_BODY => [
                    'grouped_varios_allowed' => false,
                    'composite_code_allowed_as_product' => false,
                    'automatic_export_requires_product' => true,
                    'duplicate_catalog_section_requires_review' => true,
                    'duplicate_catalog_section_blocks_only_affected_section' => true,
                    'duplicate_catalog_section_automatic_selection' => false,
                    'duplicate_catalog_section_merge_blocks' => false,
                ],
                self::WORKFLOW_PROMO_TAPA => [
                    'grouped_varios_allowed' => true,
                    'grouped_varios_requires_master_lookup' => false,
                    'grouped_varios_exportable_automatically' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function auditTextFile(
        string $filePath,
        int $sampleSize = 5,
        ?string $workflowType = null,
    ): array {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            throw new InvalidArgumentException("No se puede leer el TXT de referencia: {$filePath}");
        }

        $workflowType = $this->validatedWorkflowType(
            $workflowType ?? $this->inferWorkflowType(basename($filePath)),
        );
        $bytes = file_get_contents($filePath);

        if ($bytes === false) {
            throw new InvalidArgumentException("No se puede leer el TXT de referencia: {$filePath}");
        }

        $encoding = $this->detectEncoding($bytes);
        $text = $this->toUtf8($bytes, $encoding);
        $text = preg_replace('/^\x{FEFF}/u', '', $text) ?? $text;
        $lines = preg_split('/\R/u', $text) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
        $delimiter = $this->detectDelimiter($lines);
        $headerLine = $lines[0] ?? '';
        $headers = $headerLine === '' ? [] : str_getcsv($headerLine, $delimiter['character']);
        $expectedColumns = count($headers);
        $normalizedHeaders = array_map(fn (string $header): string => $this->normalizeHeader($header), $headers);
        $headerOccurrences = array_count_values(array_filter($normalizedHeaders, static fn (string $header): bool => $header !== ''));
        $duplicateHeaders = array_keys(array_filter($headerOccurrences, static fn (int $count): bool => $count > 1));
        $codeIndex = $this->columnIndex($normalizedHeaders, ['codigo', 'cod', 'sku']);
        $categoryIndex = $this->columnIndex($normalizedHeaders, ['categoria']);
        $groupIndex = $this->columnIndex($normalizedHeaders, ['grupo']);
        $priceIndexes = $this->priceIndexes($normalizedHeaders);
        $rows = [];
        $irregularRows = [];
        $lineTypes = $this->emptyLineTypeCounts();
        $classificationCounts = $this->emptyClassificationCounts();
        $priceCounts = ['lista' => 0, 'oferta' => 0, 'tachado' => 0];
        $imagePaths = [];
        $containerPaths = [];
        $variosExamples = [];
        $compositeExamples = [];
        $categoryGroupEmptyRows = 0;

        foreach (array_slice($lines, 1) as $offset => $line) {
            $lineNumber = $offset + 2;
            $columns = str_getcsv($line, $delimiter['character']);
            $columnCount = count($columns);

            if ($columnCount !== $expectedColumns) {
                $irregularRows[] = [
                    'line' => $lineNumber,
                    'columns' => $columnCount,
                    'expected_columns' => $expectedColumns,
                    'content' => $line,
                ];
            }

            $code = $codeIndex === null ? '' : trim((string) ($columns[$codeIndex] ?? ''));
            $classification = $this->classifyCode($code, $workflowType);
            $lineTypes[$classification['line_type']]++;
            $this->incrementClassificationCounts($classificationCounts, $classification);

            if ($classification['line_type'] === 'grouped_varios' && count($variosExamples) < $sampleSize) {
                $variosExamples[] = $this->rowExample($headers, $columns, $lineNumber);
            }

            if ($classification['line_type'] === 'composite_code' && count($compositeExamples) < $sampleSize) {
                $compositeExamples[] = [
                    'line' => $lineNumber,
                    'code' => $code,
                    'parseable_codes' => $classification['parseable_codes'],
                ];
            }

            foreach ($priceIndexes as $type => $indexes) {
                if ($this->hasAnyValue($columns, $indexes)) {
                    $priceCounts[$type]++;
                }
            }

            foreach ($columns as $value) {
                $value = trim((string) $value);

                if (preg_match('~(?:^|[\\/])[^\\/]+\.(?:png|jpe?g)$~iu', $value) === 1) {
                    $imagePaths[$value] = true;
                }

                if (preg_match('~\.ai$~iu', $value) === 1) {
                    $containerPaths[$value] = true;
                }
            }

            if (($categoryIndex !== null && blank($columns[$categoryIndex] ?? null))
                && ($groupIndex !== null && blank($columns[$groupIndex] ?? null))) {
                $categoryGroupEmptyRows++;
            }

            $rows[] = [
                'line' => $lineNumber,
                'column_count' => $columnCount,
                'code' => $code,
                'line_type' => $classification['line_type'],
                'requires_master_lookup' => $classification['requires_master_lookup'],
                'workflow_status' => $classification['workflow_status'],
                'requires_review' => $classification['requires_review'],
                'exportable_automatically' => $classification['exportable_automatically'],
                'values' => $this->rowMap($headers, $columns),
            ];
        }

        return [
            'file_name' => basename($filePath),
            'file_path' => realpath($filePath) ?: $filePath,
            'workflow_type' => $workflowType,
            'size_bytes' => filesize($filePath),
            'encoding_probable' => $encoding,
            'delimiter' => $delimiter['label'],
            'delimiter_character' => $delimiter['printable'],
            'delimiter_candidates' => $delimiter['candidates'],
            'header_exact' => $headerLine,
            'headers' => $headers,
            'duplicate_headers' => $duplicateHeaders,
            'columns' => $expectedColumns,
            'data_rows' => count($rows),
            'irregular_row_count' => count($irregularRows),
            'irregular_rows' => array_slice($irregularRows, 0, $sampleSize),
            'line_types' => $lineTypes,
            'classification_counts' => $classificationCounts,
            'price_rows' => $priceCounts,
            'price_columns' => $this->priceColumnNames($headers, $priceIndexes),
            'image_path_count' => count($imagePaths),
            'image_path_examples' => array_slice(array_keys($imagePaths), 0, $sampleSize),
            'container_ai_path_count' => count($containerPaths),
            'container_ai_path_examples' => array_slice(array_keys($containerPaths), 0, $sampleSize),
            'category_group_empty_rows' => $categoryGroupEmptyRows,
            'varios_examples' => $variosExamples,
            'composite_examples' => $compositeExamples,
            'examples' => array_slice($rows, 0, $sampleSize),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function auditWorkbook(
        string $filePath,
        int $sampleSize = 5,
        ?string $workflowType = null,
    ): array {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            throw new InvalidArgumentException("No se puede leer el XLSX de referencia: {$filePath}");
        }

        $workflowType = $this->validatedWorkflowType(
            $workflowType ?? $this->inferWorkflowType(basename($filePath)),
        );
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $sheets = [];

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $sheets[] = $this->auditWorksheet($sheet, $sampleSize, $workflowType);
        }

        $spreadsheet->disconnectWorksheets();
        $catalogSectionAudit = $this->catalogSectionAudit(basename($filePath), $sheets, $workflowType);
        $firstTables = array_values(array_filter(array_column($sheets, 'first_table')));
        $secondaryBlocks = [];

        foreach ($sheets as $sheet) {
            foreach ($sheet['secondary_blocks'] as $block) {
                $secondaryBlocks[] = ['sheet' => $sheet['name'], ...$block];
            }
        }

        return [
            'file_name' => basename($filePath),
            'file_path' => realpath($filePath) ?: $filePath,
            'workflow_type' => $workflowType,
            'size_bytes' => filesize($filePath),
            'sheet_count' => count($sheets),
            'sheet_names' => array_column($sheets, 'name'),
            'sheets' => $sheets,
            'first_table' => $firstTables[0] ?? null,
            'secondary_blocks' => $secondaryBlocks,
            'useful_rows' => array_sum(array_column($sheets, 'useful_rows')),
            'categories_tabs_detected' => array_values(array_unique(array_filter([
                ...array_column($sheets, 'name'),
                ...$this->nestedValues($sheets, 'categories_detected'),
            ]))),
            'catalog_sections' => $catalogSectionAudit['sections'],
            'duplicate_catalog_sections' => $catalogSectionAudit['duplicates'],
            'has_duplicate_catalog_sections' => $catalogSectionAudit['duplicates'] !== [],
            'line_types' => $this->sumLineTypes($firstTables),
            'classification_counts' => $this->sumClassificationCounts($firstTables),
            'price_columns' => array_values(array_unique($this->nestedValues($firstTables, 'price_columns'))),
            'image_container_columns' => array_values(array_unique($this->nestedValues($firstTables, 'image_container_columns'))),
        ];
    }

    /**
     * @return array{line_type: string, workflow_type: string, workflow_status: string, requires_master_lookup: bool, requires_review: bool, exportable_automatically: bool, parseable_codes: array<int, string>}
     */
    public function classifyCode(?string $code, string $workflowType): array
    {
        $workflowType = $this->validatedWorkflowType($workflowType);
        $code = trim((string) $code);

        if ($code === '') {
            return $this->classification('empty', $workflowType);
        }

        if (mb_strtoupper($code, 'UTF-8') === 'VARIOS') {
            return $this->classification('grouped_varios', $workflowType);
        }

        if (preg_match('/^\d+$/', $code) === 1) {
            return $this->classification('product', $workflowType, [$code]);
        }

        if (str_contains($code, '-')
            && preg_match('/^\d+(?:\s*-\s*\d*)+(?:\s*)$/', $code) === 1) {
            preg_match_all('/\d+/', $code, $matches);

            return $this->classification('composite_code', $workflowType, $matches[0]);
        }

        return $this->classification('invalid', $workflowType);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditWorksheet(Worksheet $sheet, int $sampleSize, string $workflowType): array
    {
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
        $rowValues = [];

        for ($row = 1; $row <= $highestRow; $row++) {
            $rowValues[$row] = $this->worksheetRow($sheet, $row, $highestColumnIndex);
        }

        $blocks = $this->rowBlocks($rowValues);
        $auditedBlocks = array_map(
            fn (array $block): array => $this->auditWorksheetBlock(
                $sheet,
                $rowValues,
                $block,
                $highestColumnIndex,
                $sampleSize,
                $workflowType,
            ),
            $blocks,
        );
        usort($auditedBlocks, static fn (array $a, array $b): int => $b['header_score'] <=> $a['header_score'] ?: $a['start_row'] <=> $b['start_row']);
        $firstTable = $auditedBlocks[0] ?? null;
        $secondaryBlocks = array_values(array_filter(
            $auditedBlocks,
            static fn (array $block): bool => $firstTable === null || $block['start_row'] !== $firstTable['start_row'],
        ));

        return [
            'name' => $sheet->getTitle(),
            'dimension' => "A1:{$highestColumn}{$highestRow}",
            'highest_row' => $highestRow,
            'highest_column' => $highestColumn,
            'non_empty_blocks' => count($auditedBlocks),
            'first_table' => $firstTable,
            'secondary_blocks' => $secondaryBlocks,
            'useful_rows' => $firstTable['useful_rows'] ?? 0,
            'categories_detected' => $firstTable['categories_detected'] ?? [],
            'notes_or_totals' => array_values(array_filter($rowValues, fn (array $values): bool => $this->isNoteOrTotalRow($values))),
        ];
    }

    /**
     * @param  array<int, array<int, string>>  $rowValues
     * @param  array{start: int, end: int}  $block
     * @return array<string, mixed>
     */
    private function auditWorksheetBlock(
        Worksheet $sheet,
        array $rowValues,
        array $block,
        int $highestColumnIndex,
        int $sampleSize,
        string $workflowType,
    ): array {
        $headerRow = $block['start'];
        $headerScore = -1;

        for ($row = $block['start']; $row <= min($block['end'], $block['start'] + 10); $row++) {
            $score = $this->headerScore($rowValues[$row]);

            if ($score > $headerScore) {
                $headerRow = $row;
                $headerScore = $score;
            }
        }

        $headers = $rowValues[$headerRow];
        $lastHeaderColumn = $this->lastRelevantColumn($headers, $rowValues, $headerRow, $block['end']);
        $headers = array_slice($headers, 0, $lastHeaderColumn);
        $normalizedHeaders = array_map(fn (string $header): string => $this->normalizeHeader($header), $headers);
        $codeIndex = $this->columnIndex($normalizedHeaders, ['codigo', 'cod', 'sku']);
        $categoryIndex = $this->columnIndex($normalizedHeaders, ['categoria', 'solapa']);
        $rows = [];
        $lineTypes = $this->emptyLineTypeCounts();
        $classificationCounts = $this->emptyClassificationCounts();
        $categories = [];

        for ($row = $headerRow + 1; $row <= $block['end']; $row++) {
            $values = array_slice($rowValues[$row], 0, $lastHeaderColumn);

            if ($this->nonEmptyCount($values) === 0) {
                continue;
            }

            $code = $codeIndex === null ? '' : trim((string) ($values[$codeIndex] ?? ''));
            $classification = $this->classifyCode($code, $workflowType);
            $lineTypes[$classification['line_type']]++;
            $this->incrementClassificationCounts($classificationCounts, $classification);

            if ($categoryIndex !== null && filled($values[$categoryIndex] ?? null)) {
                $categories[] = trim((string) $values[$categoryIndex]);
            }

            $rows[] = [
                'row' => $row,
                'code' => $code,
                'line_type' => $classification['line_type'],
                'workflow_status' => $classification['workflow_status'],
                'requires_review' => $classification['requires_review'],
                'exportable_automatically' => $classification['exportable_automatically'],
                'values' => $this->rowMap($headers, $values),
            ];
        }

        return [
            'sheet' => $sheet->getTitle(),
            'start_row' => $block['start'],
            'end_row' => $block['end'],
            'range' => 'A'.$block['start'].':'.Coordinate::stringFromColumnIndex(max(1, $lastHeaderColumn)).$block['end'],
            'header_row' => $headerRow,
            'header_score' => $headerScore,
            'headers' => $headers,
            'columns' => array_map(
                static fn (string $header, int $index): array => [
                    'index' => $index + 1,
                    'letter' => Coordinate::stringFromColumnIndex($index + 1),
                    'header' => $header,
                ],
                $headers,
                array_keys($headers),
            ),
            'useful_rows' => count($rows),
            'line_types' => $lineTypes,
            'classification_counts' => $classificationCounts,
            'has_code_column' => $codeIndex !== null,
            'has_category_column' => $categoryIndex !== null,
            'all_rows_exportable_automatically' => $rows !== [] && collect($rows)->every(
                static fn (array $row): bool => $row['exportable_automatically'],
            ),
            'price_columns' => $this->matchingHeaderNames($headers, ['precio', 'lista', 'oferta', 'tachado']),
            'image_container_columns' => $this->matchingHeaderNames($headers, ['imagen', 'folder', 'contenedor', 'cucarda']),
            'categories_detected' => array_values(array_unique($categories)),
            'examples' => array_slice($rows, 0, $sampleSize),
        ];
    }

    /** @return array<int, string> */
    private function worksheetRow(Worksheet $sheet, int $row, int $highestColumnIndex): array
    {
        $values = [];

        for ($column = 1; $column <= $highestColumnIndex; $column++) {
            $values[] = trim((string) $sheet->getCell([$column, $row])->getFormattedValue());
        }

        return $values;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array<int, array{start: int, end: int}>
     */
    private function rowBlocks(array $rows): array
    {
        $blocks = [];
        $start = null;
        $previous = null;

        foreach ($rows as $row => $values) {
            if ($this->nonEmptyCount($values) === 0) {
                if ($start !== null && $previous !== null) {
                    $blocks[] = ['start' => $start, 'end' => $previous];
                    $start = null;
                }

                continue;
            }

            $start ??= $row;
            $previous = $row;
        }

        if ($start !== null && $previous !== null) {
            $blocks[] = ['start' => $start, 'end' => $previous];
        }

        return $blocks;
    }

    /** @param array<int, string> $values */
    private function headerScore(array $values): int
    {
        $keywords = ['codigo', 'sku', 'marca', 'descripcion', 'uxb', 'precio', 'imagen', 'categoria', 'grupo', 'folder'];

        return count(array_filter(
            $values,
            fn (string $value): bool => collect($keywords)->contains(
                fn (string $keyword): bool => str_contains($this->normalizeHeader($value), $keyword),
            ),
        ));
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    private function lastRelevantColumn(array $headers, array $rows, int $startRow, int $endRow): int
    {
        for ($column = count($headers) - 1; $column >= 0; $column--) {
            for ($row = $startRow; $row <= $endRow; $row++) {
                if (filled($rows[$row][$column] ?? null)) {
                    return $column + 1;
                }
            }
        }

        return 1;
    }

    /** @param array<int, string> $values */
    private function isNoteOrTotalRow(array $values): bool
    {
        $text = $this->normalizeHeader(implode(' ', array_filter($values)));

        return preg_match('/\b(?:nota|notas|total|totales|observacion|informativo)\b/u', $text) === 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sheets
     * @return array{sections: array<int, array<string, mixed>>, duplicates: array<int, array<string, mixed>>}
     */
    private function catalogSectionAudit(string $fileName, array $sheets, string $workflowType): array
    {
        if ($workflowType !== self::WORKFLOW_CATALOG_BODY) {
            return ['sections' => [], 'duplicates' => []];
        }

        $sections = [];

        foreach ($sheets as $sheet) {
            $blocks = array_values(array_filter([
                $sheet['first_table'] ?? null,
                ...($sheet['secondary_blocks'] ?? []),
            ]));

            foreach ($blocks as $block) {
                if (! ($block['has_code_column'] ?? false)) {
                    continue;
                }

                $labels = array_values(array_unique(array_filter(array_map(
                    static fn (mixed $category): string => trim((string) $category),
                    $block['categories_detected'] ?? [],
                ))));

                if ($labels === [] && ! $this->isGenericSheetName((string) $sheet['name'])) {
                    $labels[] = (string) $sheet['name'];
                }

                foreach ($labels as $label) {
                    $normalizedSection = $this->normalizeCatalogSection($label);

                    if ($normalizedSection === '') {
                        continue;
                    }

                    $sections[] = [
                        'workflow_type' => self::WORKFLOW_CATALOG_BODY,
                        'section' => $label,
                        'normalized_section' => $normalizedSection,
                        'rows' => (int) ($block['useful_rows'] ?? 0),
                        'file' => $fileName,
                        'sheet' => (string) $sheet['name'],
                        'range' => (string) ($block['range'] ?? ''),
                        'origin' => $fileName.'#'.$sheet['name'].':'.($block['range'] ?? ''),
                        'requires_review' => false,
                        'severity' => null,
                        'problem' => null,
                        'exportable_automatically' => (bool) ($block['all_rows_exportable_automatically'] ?? false),
                    ];
                }
            }
        }

        $grouped = collect($sections)->groupBy('normalized_section');
        $duplicateKeys = $grouped
            ->filter(static fn ($origins): bool => $origins->count() > 1)
            ->keys()
            ->all();

        $sections = array_map(static function (array $section) use ($duplicateKeys): array {
            if (! in_array($section['normalized_section'], $duplicateKeys, true)) {
                return $section;
            }

            return [
                ...$section,
                'requires_review' => true,
                'severity' => 'blocked',
                'problem' => 'duplicate_catalog_section',
                'exportable_automatically' => false,
            ];
        }, $sections);

        $duplicates = collect($sections)
            ->groupBy('normalized_section')
            ->filter(static fn ($origins): bool => $origins->count() > 1)
            ->map(function ($origins, string $normalizedSection): array {
                $origins = $origins->values()->all();

                return [
                    'problem' => 'duplicate_catalog_section',
                    'workflow_type' => self::WORKFLOW_CATALOG_BODY,
                    'normalized_section' => $normalizedSection,
                    'detected_blocks' => count($origins),
                    'rows_per_block' => array_values(array_map(
                        static fn (array $origin): int => $origin['rows'],
                        $origins,
                    )),
                    'origins' => array_values(array_map(
                        static fn (array $origin): array => [
                            'file' => $origin['file'],
                            'sheet' => $origin['sheet'],
                            'range' => $origin['range'],
                            'section' => $origin['section'],
                            'rows' => $origin['rows'],
                            'origin' => $origin['origin'],
                        ],
                        $origins,
                    )),
                    'status' => 'requires_review',
                    'requires_review' => true,
                    'severity' => 'blocked',
                    'exportable_automatically' => false,
                    'automatic_selection' => false,
                    'merge_blocks' => false,
                    'recommendation' => 'manual_selection',
                    'message' => 'Se detectaron '.count($origins).' bloques para '.$origins[0]['section'].'. Elegí cuál usar.',
                ];
            })
            ->values()
            ->all();

        return ['sections' => $sections, 'duplicates' => $duplicates];
    }

    private function normalizeCatalogSection(string $section): string
    {
        $normalized = $this->normalizeHeader($section);
        $normalized = preg_replace('/\.(?:xlsx?|txt)$/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+(?:int|interior)$/u', '', trim($normalized)) ?? trim($normalized);
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);

        return match ($normalized) {
            'almacen', 'alimentos' => 'almacen',
            'bebidas con al', 'bebidas c alcohol', 'bebidas con alcohol' => 'bebidas_con_al',
            'bebidas sin al', 'bebidas s alcohol', 'bebidas sin alcohol' => 'bebidas_sin_al',
            'gastro', 'gastronomia' => 'gastro',
            'non food', 'nonfood' => 'non_food',
            default => str_replace(' ', '_', $normalized),
        };
    }

    private function isGenericSheetName(string $sheetName): bool
    {
        return preg_match('/^hoja\s*\d*(?:\s*\(\s*\d+\s*\))?$/u', $this->normalizeHeader($sheetName)) === 1;
    }

    /** @return array{character: string, printable: string, label: string, candidates: array<string, mixed>} */
    private function detectDelimiter(array $lines): array
    {
        $definitions = [
            'TAB' => "\t",
            'semicolon' => ';',
            'comma' => ',',
        ];
        $candidates = [];

        foreach ($definitions as $label => $character) {
            $counts = array_map(static fn (string $line): int => count(str_getcsv($line, $character)), array_slice($lines, 0, 50));
            $frequencies = array_count_values($counts);
            unset($frequencies[1]);
            arsort($frequencies);
            $modeColumns = (int) (array_key_first($frequencies) ?? 1);
            $regularLines = (int) ($frequencies[$modeColumns] ?? 0);
            $candidates[$label] = [
                'mode_columns' => $modeColumns,
                'regular_lines' => $regularLines,
                'score' => $modeColumns > 1 ? $regularLines * 1000 + $modeColumns : 0,
            ];
        }

        uasort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $label = (string) array_key_first($candidates);

        if (($candidates[$label]['score'] ?? 0) === 0) {
            $label = 'none';
        }

        $character = $definitions[$label] ?? "\t";
        $lineCount = max(1, count(array_slice($lines, 0, 50)));
        $significantCandidates = array_filter(
            $candidates,
            static fn (array $candidate): bool => $candidate['mode_columns'] > 1
                && $candidate['regular_lines'] >= max(1, (int) floor($lineCount * 0.2)),
        );
        $reportedLabel = count($significantCandidates) > 1
            && (($candidates[$label]['regular_lines'] ?? 0) / $lineCount) < 0.8
                ? 'mixed'
                : $label;

        return [
            'character' => $character,
            'printable' => $character === "\t" ? '\\t' : $character,
            'label' => $reportedLabel,
            'candidates' => $candidates,
        ];
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

    private function resolvedDirectory(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new InvalidArgumentException('La opción --base-path es obligatoria.');
        }

        $candidate = $this->isAbsolutePath($path) ? $path : base_path($path);
        $resolved = realpath($candidate);

        if ($resolved === false || ! is_dir($resolved) || ! is_readable($resolved)) {
            throw new InvalidArgumentException("No se puede leer la carpeta de muestras: {$candidate}");
        }

        return $resolved;
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $path) === 1;
    }

    private function normalizedFileName(string $name): string
    {
        return $this->normalizeHeader(preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name));
    }

    private function inferWorkflowType(string $fileName): string
    {
        $normalized = $this->normalizedFileName($fileName);

        if ($normalized === $this->normalizedFileName(self::CATALOG_INPUT)
            || preg_match('/\sint\.txt$/u', $normalized) === 1) {
            return self::WORKFLOW_CATALOG_BODY;
        }

        if (in_array($normalized, [
            $this->normalizedFileName(self::PROMOTIONS_INPUT),
            $this->normalizedFileName(self::PROMOTIONS_OUTPUT),
        ], true)) {
            return self::WORKFLOW_PROMO_TAPA;
        }

        return self::WORKFLOW_UNKNOWN;
    }

    private function validatedWorkflowType(string $workflowType): string
    {
        if (! in_array($workflowType, [
            self::WORKFLOW_CATALOG_BODY,
            self::WORKFLOW_PROMO_TAPA,
            self::WORKFLOW_UNKNOWN,
        ], true)) {
            throw new InvalidArgumentException("Workflow no soportado: {$workflowType}");
        }

        return $workflowType;
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/\s+/u', ' ', trim($header)) ?? trim($header);

        return mb_strtolower(Str::ascii($header), 'UTF-8');
    }

    /** @param array<int, string> $headers */
    private function columnIndex(array $headers, array $needles): ?int
    {
        foreach ($headers as $index => $header) {
            foreach ($needles as $needle) {
                if ($header === $needle || str_contains($header, $needle)) {
                    return $index;
                }
            }
        }

        return null;
    }

    /** @param array<int, string> $headers */
    private function priceIndexes(array $headers): array
    {
        $indexes = ['lista' => [], 'oferta' => [], 'tachado' => []];

        foreach ($headers as $index => $header) {
            if (! str_contains($header, 'precio')) {
                continue;
            }

            if (str_contains($header, 'oferta')) {
                $indexes['oferta'][] = $index;
            } elseif (str_contains($header, 'tachado')) {
                $indexes['tachado'][] = $index;
            } else {
                $indexes['lista'][] = $index;
            }
        }

        return $indexes;
    }

    private function hasAnyValue(array $columns, array $indexes): bool
    {
        foreach ($indexes as $index) {
            if (filled($columns[$index] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function priceColumnNames(array $headers, array $priceIndexes): array
    {
        return collect($priceIndexes)->map(
            static fn (array $indexes): array => array_values(array_map(
                static fn (int $index): string => $headers[$index] ?? '',
                $indexes,
            )),
        )->all();
    }

    private function rowMap(array $headers, array $columns): array
    {
        $mapped = [];

        foreach ($headers as $index => $header) {
            $key = trim((string) $header) !== '' ? trim((string) $header) : 'column_'.($index + 1);

            if (array_key_exists($key, $mapped)) {
                $key .= '_'.($index + 1);
            }

            $mapped[$key] = $columns[$index] ?? null;
        }

        return $mapped;
    }

    private function rowExample(array $headers, array $columns, int $lineNumber): array
    {
        return ['line' => $lineNumber, 'values' => $this->rowMap($headers, $columns)];
    }

    private function classification(string $lineType, string $workflowType, array $parseableCodes = []): array
    {
        $requiresMasterLookup = $lineType === 'product';
        $workflowStatus = 'valid';
        $requiresReview = false;
        $exportableAutomatically = $lineType === 'product';

        if ($workflowType === self::WORKFLOW_CATALOG_BODY
            && in_array($lineType, ['composite_code', 'grouped_varios'], true)) {
            $workflowStatus = 'invalid_for_catalog_body';
            $requiresReview = true;
            $exportableAutomatically = false;
        } elseif ($workflowType === self::WORKFLOW_PROMO_TAPA && $lineType === 'grouped_varios') {
            $exportableAutomatically = true;
        } elseif ($lineType !== 'product') {
            $workflowStatus = 'requires_review';
            $requiresReview = true;
            $exportableAutomatically = false;
        }

        return [
            'line_type' => $lineType,
            'workflow_type' => $workflowType,
            'workflow_status' => $workflowStatus,
            'requires_master_lookup' => $requiresMasterLookup,
            'requires_review' => $requiresReview,
            'exportable_automatically' => $exportableAutomatically,
            'parseable_codes' => $parseableCodes,
        ];
    }

    private function emptyLineTypeCounts(): array
    {
        return [
            'product' => 0,
            'composite_code' => 0,
            'grouped_varios' => 0,
            'empty' => 0,
            'invalid' => 0,
        ];
    }

    private function emptyClassificationCounts(): array
    {
        return [
            'product' => 0,
            'composite_code' => 0,
            'grouped_varios' => 0,
            'invalid_for_catalog_body' => 0,
            'requires_review' => 0,
            'empty' => 0,
            'invalid' => 0,
        ];
    }

    private function incrementClassificationCounts(array &$counts, array $classification): void
    {
        $lineType = $classification['line_type'];

        if (array_key_exists($lineType, $counts)) {
            $counts[$lineType]++;
        }

        if ($classification['workflow_status'] === 'invalid_for_catalog_body') {
            $counts['invalid_for_catalog_body']++;
        }

        if ($classification['requires_review']) {
            $counts['requires_review']++;
        }
    }

    private function sumLineTypes(array $reports): array
    {
        $totals = $this->emptyLineTypeCounts();

        foreach ($reports as $report) {
            foreach (($report['line_types'] ?? []) as $type => $count) {
                $totals[$type] = ($totals[$type] ?? 0) + (int) $count;
            }
        }

        return $totals;
    }

    private function sumClassificationCounts(array $reports): array
    {
        $totals = $this->emptyClassificationCounts();

        foreach ($reports as $report) {
            foreach (($report['classification_counts'] ?? []) as $type => $count) {
                $totals[$type] = ($totals[$type] ?? 0) + (int) $count;
            }
        }

        return $totals;
    }

    private function sumPriceCounts(array $reports): array
    {
        $totals = ['lista' => 0, 'oferta' => 0, 'tachado' => 0];

        foreach ($reports as $report) {
            foreach (($report['price_rows'] ?? []) as $type => $count) {
                $totals[$type] += (int) $count;
            }
        }

        return $totals;
    }

    private function matchingHeaderNames(array $headers, array $needles): array
    {
        return array_values(array_filter($headers, function (string $header) use ($needles): bool {
            $normalized = $this->normalizeHeader($header);

            return collect($needles)->contains(
                static fn (string $needle): bool => str_contains($normalized, $needle),
            );
        }));
    }

    private function nonEmptyCount(array $values): int
    {
        return count(array_filter($values, static fn (string $value): bool => trim($value) !== ''));
    }

    private function nestedValues(array $items, string $key): array
    {
        $values = [];

        foreach ($items as $item) {
            foreach (($item[$key] ?? []) as $value) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function categoryCorrespondence(?array $catalogInput, array $outputNames): array
    {
        if ($catalogInput === null) {
            return [];
        }

        $categories = $catalogInput['categories_tabs_detected'] ?? [];

        return array_map(function (string $outputName) use ($categories): array {
            $outputCategory = preg_replace('/\s+int$/iu', '', pathinfo($outputName, PATHINFO_FILENAME));
            $normalizedOutput = $this->normalizeHeader((string) $outputCategory);
            $matches = array_values(array_filter(
                $categories,
                fn (string $category): bool => str_contains($this->normalizeHeader($category), $normalizedOutput)
                    || str_contains($normalizedOutput, $this->normalizeHeader($category)),
            ));

            return [
                'output' => $outputName,
                'category' => $outputCategory,
                'matches' => $matches,
                'matched' => $matches !== [],
            ];
        }, $outputNames);
    }

    private function sameTextStructure(array $reports): ?bool
    {
        if ($reports === []) {
            return null;
        }

        $signatures = array_map(
            static fn (array $report): string => json_encode([
                $report['delimiter'],
                $report['headers'],
                $report['columns'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
            array_values($reports),
        );

        return count(array_unique($signatures)) === 1;
    }

    private function textStructureComparison(?array $promotionsOutput, array $catalogOutputs): ?array
    {
        if ($promotionsOutput === null || $catalogOutputs === []) {
            return null;
        }

        $catalogStructures = array_map(
            static fn (array $report): array => [
                'delimiter' => $report['delimiter'],
                'columns' => $report['columns'],
                'headers' => $report['headers'],
            ],
            array_values($catalogOutputs),
        );
        $promotionsStructure = [
            'delimiter' => $promotionsOutput['delimiter'],
            'columns' => $promotionsOutput['columns'],
            'headers' => $promotionsOutput['headers'],
        ];

        return [
            'promotions' => $promotionsStructure,
            'catalog_distinct_structures' => array_values(array_map(
                static fn (string $structure): array => json_decode($structure, true, flags: JSON_THROW_ON_ERROR),
                array_unique(array_map(
                    static fn (array $structure): string => json_encode(
                        $structure,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                    ),
                    $catalogStructures,
                )),
            )),
            'matches_any_catalog_structure' => collect($catalogStructures)->contains(
                static fn (array $structure): bool => $structure === $promotionsStructure,
            ),
        ];
    }
}
