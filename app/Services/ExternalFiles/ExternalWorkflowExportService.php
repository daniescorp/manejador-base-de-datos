<?php

namespace App\Services\ExternalFiles;

use DomainException;
use Illuminate\Support\Str;

class ExternalWorkflowExportService
{
    public const DELIMITER = "\t";

    public const LINE_ENDING = "\r\n";

    public function __construct(
        private readonly ExternalExportDiagnosisService $diagnosisService,
        private readonly ExternalRowsReader $rowsReader,
        private readonly ExternalPriceFormatter $priceFormatter,
    ) {}

    /**
     * @return array{content: string, rows: int, columns: int, diagnosis: array<string, mixed>}
     */
    public function export(string $filePath, string $workflow): array
    {
        $diagnosis = $this->diagnosisService->diagnose($filePath, $workflow);

        if (($diagnosis['status'] ?? null) !== 'ok') {
            throw new DomainException('Solo se pueden exportar archivos con diagnóstico OK.');
        }

        $readResult = $this->rowsReader->read($filePath, $workflow);
        $rowEnvelopes = array_values($readResult['rows'] ?? []);
        $metadata = $readResult['metadata'] ?? [];
        $safeHeaders = array_values($metadata['headers'] ?? array_keys($rowEnvelopes[0]['data'] ?? []));
        $rawHeaders = array_values($metadata['raw_headers'] ?? $safeHeaders);

        if (count($rawHeaders) !== count($safeHeaders)) {
            $rawHeaders = $safeHeaders;
        }

        $lines = [implode(self::DELIMITER, array_map($this->txtValue(...), $rawHeaders))];

        foreach ($rowEnvelopes as $envelope) {
            $data = $envelope['data'] ?? [];
            $values = [];

            foreach ($safeHeaders as $header) {
                $value = $data[$header] ?? '';

                if ($this->isPriceHeader($header)) {
                    $formatted = $this->priceFormatter->format($value);

                    if ($formatted['requires_review']) {
                        throw new DomainException('El archivo contiene precios que requieren revisión.');
                    }

                    $value = $formatted['formatted_value'];
                }

                $values[] = $this->txtValue($value);
            }

            $lines[] = implode(self::DELIMITER, $values);
        }

        return [
            'content' => implode(self::LINE_ENDING, $lines),
            'rows' => count($rowEnvelopes),
            'columns' => count($safeHeaders),
            'diagnosis' => $diagnosis,
        ];
    }

    private function isPriceHeader(string $header): bool
    {
        $normalized = mb_strtolower(Str::ascii(trim($header)), 'UTF-8');
        $normalized = preg_replace('/[^a-z0-9]+/u', '', $normalized) ?? $normalized;

        return preg_match('/^(?:preciolista|preciooferta|preciotachado)(?:\d+)?$/', $normalized) === 1;
    }

    private function txtValue(mixed $value): string
    {
        return (string) (preg_replace('/[\t\r\n]+/u', ' ', (string) $value) ?? $value);
    }
}
