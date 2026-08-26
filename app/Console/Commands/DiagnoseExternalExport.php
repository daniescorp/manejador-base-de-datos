<?php

namespace App\Console\Commands;

use App\Services\ExternalFiles\ExternalExportDiagnosisService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class DiagnoseExternalExport extends Command
{
    protected $signature = 'app:diagnose-external-export
                            {--file= : Archivo externo XLSX/TXT a diagnosticar}
                            {--workflow= : Workflow esperado: promo_tapa o catalog_body}
                            {--json : Imprimir el diagnóstico como JSON}';

    protected $description = 'Diagnostica un XLSX/TXT externo sin persistir datos ni generar una exportación';

    public function handle(ExternalExportDiagnosisService $diagnosisService): int
    {
        try {
            $requestedFile = trim((string) $this->option('file'));

            if ($requestedFile === '') {
                throw new InvalidArgumentException('La opción --file es obligatoria.');
            }

            $requestedWorkflow = trim((string) $this->option('workflow'));
            $result = $diagnosisService->diagnose(
                $this->inputPath($requestedFile),
                $requestedWorkflow !== '' ? $requestedWorkflow : null,
            );
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->writeJson([
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                ]);
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->writeJson($result);
        } else {
            $this->renderSummary($result);
        }

        return self::SUCCESS;
    }

    private function inputPath(string $requestedPath): string
    {
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $requestedPath) === 1) {
            return $requestedPath;
        }

        return base_path($requestedPath);
    }

    /** @param array<string, mixed> $result */
    private function renderSummary(array $result): void
    {
        $this->table(['Dato', 'Resultado'], [
            ['Estado', $result['status']],
            ['Workflow', $result['workflow_type']],
            ['Archivo', $result['source_file']],
            ['Formato', $result['format']],
            ['Filas', $result['rows_count']],
            ['Precios mapeados', $result['price_map_count']],
            ['Warnings', $result['warning_count']],
            ['Revisiones', $result['review_count']],
            ['Bloqueos', $result['blocked_count']],
            ['Exportable automáticamente', $result['can_export_automatically'] ? 'Sí' : 'No'],
        ]);

        foreach ($result['warnings'] as $warning) {
            $this->warn(sprintf(
                '[%s] %s (código: %s, fila: %s)',
                $warning['severity'] ?? 'warning',
                $warning['issue'] ?? 'unknown',
                $warning['code'] ?? '-',
                $warning['row_number'] ?? '-',
            ));
        }
    }

    /** @param array<string, mixed> $result */
    private function writeJson(array $result): void
    {
        $this->line(json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }
}
