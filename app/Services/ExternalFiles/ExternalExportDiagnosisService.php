<?php

namespace App\Services\ExternalFiles;

use App\Services\Audits\ExternalFormatSamplesAuditService;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ExternalExportDiagnosisService
{
    private const PRICE_FIELDS = [
        'preciolista',
        'preciooferta',
        'preciotachado',
    ];

    private const WORKFLOWS = [
        ExternalFormatSamplesAuditService::WORKFLOW_PROMO_TAPA,
        ExternalFormatSamplesAuditService::WORKFLOW_CATALOG_BODY,
    ];

    public function __construct(
        private readonly ExternalRowsReader $rowsReader,
        private readonly ExternalPriceMapBuilder $priceMapBuilder,
        private readonly ExternalFormatSamplesAuditService $codeClassifier,
        private readonly ExternalDescriptionFormatter $descriptionFormatter,
    ) {}

    /** @return array<string, mixed> */
    public function diagnose(string $filePath, ?string $requestedWorkflow = null): array
    {
        if ($requestedWorkflow !== null && $requestedWorkflow !== '') {
            $this->validateWorkflow($requestedWorkflow);
        }

        $readResult = $this->rowsReader->read($filePath, $requestedWorkflow ?: null);
        $metadata = $readResult['metadata'];
        $workflow = (string) $metadata['workflow_type'];
        $this->validateWorkflow($workflow);
        $rows = $this->rowsReader->rowsForPriceMap($readResult);
        $this->validateWorkbookStructure($metadata, $rows);
        $hasPriceColumns = $this->hasPriceColumns($rows, $metadata);
        $priceBuild = $hasPriceColumns
            ? $this->priceMapBuilder->build($rows)
            : $this->emptyPriceBuild();
        $warnings = [
            ...$readResult['warnings'],
            ...array_map(
                fn (array $warning): array => $this->workflowWarning($warning, $workflow),
                $priceBuild['warnings'],
            ),
        ];
        $summary = $this->summary($rows, $workflow, $metadata, $warnings);
        $blockedCount = $this->countWarnings($warnings, 'blocked');
        $reviewCount = $this->countWarnings($warnings, 'review');
        $status = $blockedCount > 0
            ? 'blocked'
            : ($warnings !== [] ? 'review_required' : 'ok');
        $sectionSummary = $workflow === ExternalFormatSamplesAuditService::WORKFLOW_CATALOG_BODY
            ? $this->sectionSummary($readResult, $warnings)
            : [];

        return [
            'status' => $status,
            'workflow_type' => $workflow,
            'source_file' => $metadata['source_file'],
            'format' => $metadata['format'],
            'delimiter' => $metadata['delimiter'],
            'encoding' => $metadata['encoding'],
            'column_count' => $metadata['column_count'],
            'sheet_count' => $metadata['sheet_count'] ?? null,
            'sheets_read' => $metadata['sheets_read'] ?? [],
            'ignored_secondary_block_count' => $metadata['ignored_secondary_block_count'] ?? 0,
            'rows_count' => count($rows),
            'price_map_count' => count($priceBuild['price_map']),
            'warning_count' => count($warnings),
            'review_count' => $reviewCount,
            'blocked_count' => $blockedCount,
            'can_export_automatically' => $status === 'ok',
            'price_map' => $priceBuild['price_map'],
            'warnings' => array_map(
                static fn (array $warning): array => [
                    'issue' => $warning['issue'] ?? null,
                    'severity' => $warning['severity'] ?? null,
                    'code' => $warning['code'] ?? null,
                    'row_number' => $warning['row_number'] ?? null,
                    'original_value' => $warning['original_value'] ?? null,
                    'recommendation' => $warning['recommendation'] ?? null,
                    'section' => $warning['section'] ?? null,
                    'detected_blocks' => $warning['detected_blocks'] ?? null,
                    'origins' => $warning['origins'] ?? null,
                    'message' => $warning['message'] ?? null,
                ],
                $warnings,
            ),
            'preview_rows' => $this->previewRows(
                $readResult['rows'] ?? [],
                $warnings,
                $priceBuild['price_map'],
            ),
            'summary' => $summary,
            'category_summary' => $sectionSummary,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rowEnvelopes
     * @param  list<array<string, mixed>>  $warnings
     * @param  array<string, array<string, string>>  $priceMap
     * @return list<array<string, mixed>>
     */
    private function previewRows(array $rowEnvelopes, array $warnings, array $priceMap): array
    {
        return array_values(array_map(
            function (array $envelope, int $index) use ($warnings, $priceMap): array {
                $data = $envelope['data'] ?? [];
                $code = trim((string) ($data['CODIGO'] ?? $data['SKU'] ?? ''));
                $sourceRowNumber = $envelope['row_number'] ?? ($index + 1);
                $rowWarnings = array_values(array_filter(
                    $warnings,
                    static fn (array $warning): bool => in_array(
                        $warning['row_number'] ?? null,
                        [$index + 1, $sourceRowNumber],
                        true,
                    )
                        || (($warning['code'] ?? null) !== null && (string) $warning['code'] === $code),
                ));
                $isBlocked = count(array_filter(
                    $rowWarnings,
                    static fn (array $warning): bool => ($warning['severity'] ?? null) === 'blocked',
                )) > 0;
                $prices = $priceMap[$code] ?? [];
                $badgeValues = $this->badgeValues($data);

                return [
                    'row_number' => $sourceRowNumber,
                    'code' => $code,
                    'brand' => (string) ($data['MARCA'] ?? ''),
                    'description' => $this->descriptionFormatter->format((string) ($data['DESCRIPCION'] ?? '')),
                    'units_per_box' => (string) ($data['UXB'] ?? ''),
                    'price_list' => (string) ($prices['precio_lista'] ?? $data['PRECIOLISTA'] ?? ''),
                    'price_offer' => (string) ($prices['precio_oferta'] ?? $data['PRECIOOFERTA'] ?? ''),
                    'price_strikethrough' => (string) ($prices['precio_tachado'] ?? $data['PRECIOTACHADO'] ?? ''),
                    'section' => (string) ($envelope['section'] ?? ''),
                    'has_badge' => $badgeValues !== [],
                    'badge' => implode(' | ', $badgeValues),
                    'status' => $isBlocked ? 'blocked' : ($rowWarnings !== [] ? 'review_required' : 'ok'),
                    'issues' => array_values(array_unique(array_filter(array_column($rowWarnings, 'issue')))),
                ];
            },
            array_slice($rowEnvelopes, 0, 20),
            array_keys(array_slice($rowEnvelopes, 0, 20)),
        ));
    }

    private function validateWorkflow(string $workflow): void
    {
        if (! in_array($workflow, self::WORKFLOWS, true)) {
            throw new InvalidArgumentException("Workflow no soportado: {$workflow}");
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<array<string, mixed>>  $rows
     */
    private function validateWorkbookStructure(array $metadata, array $rows): void
    {
        if (($metadata['format'] ?? null) !== 'xlsx') {
            return;
        }

        $headers = array_map(
            fn (string $header): string => $this->normalizeField($header),
            is_array($metadata['headers'] ?? null) ? $metadata['headers'] : [],
        );
        $hasCode = array_intersect(['codigo', 'sku'], $headers) !== [];
        $hasPrice = array_intersect(self::PRICE_FIELDS, $headers) !== [];

        if ($rows === [] || ! $hasCode || ! $hasPrice) {
            throw new InvalidArgumentException(
                'No se pudo reconocer la estructura del Excel. Verifique encabezados y formato.',
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $metadata
     */
    private function hasPriceColumns(array $rows, array $metadata): bool
    {
        $headers = is_array($metadata['headers'] ?? null)
            ? $metadata['headers']
            : array_keys($rows[0] ?? []);

        return count(array_intersect(
            self::PRICE_FIELDS,
            array_map(fn (string $header): string => $this->normalizeField($header), $headers),
        )) > 0;
    }

    private function normalizeField(string $field): string
    {
        $field = mb_strtolower(Str::ascii(trim($field)), 'UTF-8');

        return preg_replace('/[^a-z0-9]+/u', '', $field) ?? $field;
    }

    /** @return array<string, mixed> */
    private function emptyPriceBuild(): array
    {
        return [
            'price_map' => [],
            'warnings' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function workflowWarning(array $warning, string $workflow): array
    {
        if ($workflow !== ExternalFormatSamplesAuditService::WORKFLOW_CATALOG_BODY
            || ($warning['issue'] ?? null) !== 'composite_code_not_mapped') {
            return $warning;
        }

        return [
            ...$warning,
            'severity' => 'blocked',
            'recommendation' => 'resolve_invalid_catalog_code_manually',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $metadata
     * @param  list<array<string, mixed>>  $warnings
     * @return array<string, int>
     */
    private function summary(array $rows, string $workflow, array $metadata, array $warnings): array
    {
        $counts = [
            'product' => 0,
            'grouped_varios' => 0,
            'composite_code' => 0,
            'incomplete_composite_code' => 0,
        ];

        foreach ($rows as $row) {
            $code = $row['CODIGO'] ?? $row['SKU'] ?? null;
            $classification = $this->codeClassifier->classifyCode(
                $code === null ? null : (string) $code,
                $workflow,
            );
            $lineType = $classification['line_type'];

            if (array_key_exists($lineType, $counts)) {
                $counts[$lineType]++;
            }
        }

        return [
            'product_count' => $counts['product'],
            'grouped_varios_count' => $counts['grouped_varios'],
            'composite_code_count' => $counts['composite_code'],
            'incomplete_composite_code_count' => $counts['incomplete_composite_code'],
            'duplicate_catalog_section_count' => count($metadata['duplicate_catalog_sections'] ?? []),
            'price_requires_review_count' => count(array_filter(
                $warnings,
                static fn (array $warning): bool => (bool) ($warning['requires_review'] ?? false),
            )),
        ];
    }

    /** @param list<array<string, mixed>> $warnings */
    private function countWarnings(array $warnings, string $severity): int
    {
        return count(array_filter(
            $warnings,
            static fn (array $warning): bool => ($warning['severity'] ?? null) === $severity,
        ));
    }

    /** @return list<string> */
    private function badgeValues(array $data): array
    {
        $values = [];

        foreach ($data as $header => $value) {
            $normalized = $this->normalizeField((string) $header);

            if (preg_match('/^cucarda\d*$/', $normalized) === 1 && trim((string) $value) !== '') {
                $values[] = trim((string) $value);
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param array<string, mixed> $readResult
     * @param list<array<string, mixed>> $warnings
     * @return list<array<string, mixed>>
     */
    private function sectionSummary(array $readResult, array $warnings): array
    {
        $rows = $readResult['rows'] ?? [];

        return array_values(array_map(function (array $section) use ($rows, $warnings): array {
            $sectionRows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => ($row['section_key'] ?? null) === ($section['key'] ?? null),
            ));
            $codes = array_values(array_filter(array_map(
                static fn (array $row): string => trim((string) ($row['data']['CODIGO'] ?? $row['data']['SKU'] ?? '')),
                $sectionRows,
            )));
            $sectionWarnings = array_values(array_filter(
                $warnings,
                static fn (array $warning): bool => ($warning['section'] ?? null) === ($section['normalized_section'] ?? null)
                    || (($warning['code'] ?? null) !== null && in_array((string) $warning['code'], $codes, true)),
            ));
            $blocked = (bool) ($section['requires_review'] ?? false)
                || $this->countWarnings($sectionWarnings, 'blocked') > 0;
            $review = ! $blocked && $sectionWarnings !== [];

            return [
                'key' => $section['key'] ?? null,
                'name' => $section['section'] ?? null,
                'normalized_name' => $section['normalized_section'] ?? null,
                'sheet' => $section['sheet'] ?? null,
                'range' => $section['range'] ?? null,
                'rows' => count($sectionRows),
                'status' => $blocked ? 'blocked' : ($review ? 'review_required' : 'ok'),
                'badge_count' => count(array_filter(
                    $sectionRows,
                    fn (array $row): bool => $this->badgeValues($row['data'] ?? []) !== [],
                )),
                'warning_count' => count($sectionWarnings),
                'blocked_count' => $blocked ? max(1, $this->countWarnings($sectionWarnings, 'blocked')) : 0,
            ];
        }, $readResult['metadata']['catalog_sections'] ?? []));
    }
}
