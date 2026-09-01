<?php

namespace App\Services\ExternalFiles;

use App\Services\Audits\ExternalFormatSamplesAuditService;
use Illuminate\Support\Str;

class ExternalPriceMapBuilder
{
    private const PRICE_FIELDS = [
        'precio_lista' => 'PRECIOLISTA',
        'precio_oferta' => 'PRECIOOFERTA',
        'precio_tachado' => 'PRECIOTACHADO',
    ];

    public function __construct(
        private readonly ExternalPriceFormatter $priceFormatter,
        private readonly ExternalFormatSamplesAuditService $codeClassifier,
    ) {}

    /**
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return array{
     *     price_map: array<string, array{precio_lista: string, precio_oferta: string, precio_tachado: string}>,
     *     warnings: list<array<string, mixed>>,
     *     requires_review: bool,
     *     blocked_count: int,
     *     review_count: int,
     *     formatted_count: int,
     *     empty_price_count: int,
     *     duplicate_code_count: int,
     *     invalid_code_count: int
     * }
     */
    public function build(array $rows): array
    {
        $priceMap = [];
        $warnings = [];
        $duplicateCodes = [];
        $formattedCount = 0;
        $emptyPriceCount = 0;
        $invalidCodeCount = 0;

        foreach (array_values($rows) as $index => $row) {
            $rowNumber = $index + 1;
            $normalizedRow = $this->normalizedRow($row);
            $originalCode = $normalizedRow['codigo'] ?? $normalizedRow['sku'] ?? null;
            $code = trim((string) $originalCode);

            if ($code === '') {
                $invalidCodeCount++;
                $warnings[] = $this->warning(
                    null,
                    $rowNumber,
                    'CODIGO',
                    $originalCode,
                    'missing_code',
                    'blocked',
                    'complete_code_manually',
                );

                continue;
            }

            $classification = $this->codeClassifier->classifyCode(
                $code,
                ExternalFormatSamplesAuditService::WORKFLOW_PROMO_TAPA,
            );

            if ($classification['line_type'] !== 'product') {
                $invalidCodeCount++;
                $warnings[] = $this->codeWarning($classification, $rowNumber);

                continue;
            }

            $formattedPrices = [];

            foreach (self::PRICE_FIELDS as $mapField => $externalField) {
                $priceValues = $this->priceValues($normalizedRow, $externalField);
                $formattedPrices[$mapField] = '';

                foreach ($priceValues as $occurrence => $originalValue) {
                    $formatted = $this->priceFormatter->format($originalValue);

                    if ($occurrence === 0) {
                        $formattedPrices[$mapField] = $formatted['formatted_value'];
                    }

                    if ($formatted['status'] === 'formatted') {
                        $formattedCount++;
                    } elseif ($formatted['status'] === 'empty') {
                        $emptyPriceCount++;
                    }

                    if ($formatted['requires_review']) {
                        $warnings[] = $this->warning(
                            $code,
                            $rowNumber,
                            $externalField,
                            $originalValue,
                            'price_requires_review',
                            'review',
                            'review_price_manually',
                            $formatted['warning'],
                        );
                    }
                }
            }

            if (! array_key_exists($code, $priceMap)) {
                $priceMap[$code] = $formattedPrices;

                continue;
            }

            $duplicateCodes[$code] = true;

            if ($priceMap[$code] !== $formattedPrices) {
                $warnings[] = $this->warning(
                    $code,
                    $rowNumber,
                    null,
                    $row,
                    'duplicate_price_code',
                    'blocked',
                    'select_price_row_manually',
                    'El mismo código tiene precios distintos; se conserva el primer registro hasta revisión.',
                    [
                        'first_prices' => $priceMap[$code],
                        'duplicate_prices' => $formattedPrices,
                    ],
                );
            }
        }

        return [
            'price_map' => $priceMap,
            'warnings' => $warnings,
            'requires_review' => $warnings !== [],
            'blocked_count' => count(array_filter(
                $warnings,
                static fn (array $warning): bool => $warning['severity'] === 'blocked',
            )),
            'review_count' => count(array_filter(
                $warnings,
                static fn (array $warning): bool => $warning['requires_review'],
            )),
            'formatted_count' => $formattedCount,
            'empty_price_count' => $emptyPriceCount,
            'duplicate_code_count' => count($duplicateCodes),
            'invalid_code_count' => $invalidCodeCount,
        ];
    }

    /** @return array<string, mixed> */
    private function normalizedRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $field => $value) {
            $normalized[$this->normalizeField((string) $field)] = $value;
        }

        return $normalized;
    }

    private function normalizeField(string $field): string
    {
        $field = mb_strtolower(Str::ascii(trim($field)), 'UTF-8');

        return preg_replace('/[^a-z0-9]+/u', '', $field) ?? $field;
    }

    /** @return list<mixed> */
    private function priceValues(array $normalizedRow, string $externalField): array
    {
        $base = $this->normalizeField($externalField);
        $values = [];

        foreach ($normalizedRow as $field => $value) {
            if (preg_match('/^'.preg_quote($base, '/').'(?:\d+)?$/', $field) === 1) {
                $values[] = $value;
            }
        }

        return $values === [] ? [null] : $values;
    }

    /** @return array<string, mixed> */
    private function codeWarning(array $classification, int $rowNumber): array
    {
        $lineType = $classification['line_type'];
        [$issue, $severity, $recommendation, $message] = match ($lineType) {
            'grouped_varios' => [
                'grouped_varios_not_mapped',
                'review',
                'resolve_grouped_varios_manually',
                'VARIOS no se mezcla con el mapa automático de productos.',
            ],
            'composite_code' => [
                'composite_code_not_mapped',
                'review',
                'resolve_composite_code_manually',
                'El código compuesto no se agrega como una clave de producto simple.',
            ],
            'incomplete_composite_code' => [
                'incomplete_composite_code',
                'blocked',
                'correct_code_manually',
                $classification['warning'],
            ],
            default => [
                'invalid_code',
                'blocked',
                'correct_code_manually',
                'El código no tiene un formato numérico válido.',
            ],
        };

        return $this->warning(
            $classification['original_code'],
            $rowNumber,
            'CODIGO',
            $classification['original_code'],
            $issue,
            $severity,
            $recommendation,
            $message,
            [
                'line_type' => $lineType,
                'component_codes' => $classification['component_codes'],
                'missing_component' => $classification['missing_component'],
            ],
        );
    }

    /** @return array<string, mixed> */
    private function warning(
        ?string $code,
        int $rowNumber,
        ?string $field,
        mixed $originalValue,
        string $issue,
        string $severity,
        string $recommendation,
        ?string $message = null,
        array $context = [],
    ): array {
        return [
            'code' => $code,
            'row_number' => $rowNumber,
            'field' => $field,
            'original_value' => $originalValue,
            'issue' => $issue,
            'severity' => $severity,
            'requires_review' => true,
            'recommendation' => $recommendation,
            'message' => $message,
            ...$context,
        ];
    }
}
