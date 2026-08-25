<?php

namespace App\Console\Commands;

use App\Services\Audits\ExternalFormatSamplesAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Throwable;

class AuditExternalFormatSamples extends Command
{
    protected $signature = 'app:audit-external-format-samples
                            {--base-path= : Carpeta local que contiene las muestras XLSX y TXT}
                            {--json : Imprimir el reporte completo como JSON}
                            {--sample=5 : Cantidad de ejemplos por archivo}
                            {--output= : Guardar además el reporte JSON en esta ruta explícita}';

    protected $description = 'Audita muestras externas de catálogo y promociones sin escribir en la base de datos';

    public function handle(ExternalFormatSamplesAuditService $auditService): int
    {
        try {
            $sampleSize = $this->positiveIntegerOption('sample');
            $report = $auditService->auditDirectory(
                (string) $this->option('base-path'),
                $sampleSize,
            );
            $json = json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            $output = trim((string) $this->option('output'));

            if ($output !== '') {
                $outputPath = $this->outputPath($output);
                File::ensureDirectoryExists(dirname($outputPath));

                if (File::put($outputPath, $json.PHP_EOL) === false) {
                    throw new InvalidArgumentException("No se pudo escribir el reporte {$outputPath}.");
                }

                $report['output_path'] = $outputPath;
                $json = json_encode(
                    $report,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line($json);
        } else {
            $this->renderSummary($report);
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $report */
    private function renderSummary(array $report): void
    {
        $this->info('Auditoría técnica de formatos externos');
        $this->table(['Dato', 'Resultado'], [
            ['Estado', $report['status']],
            ['Carpeta', $report['base_path']],
            ['Archivos detectados', count($report['detected_files'])],
            ['Archivos faltantes', count($report['missing_files'])],
            ['Excel', $report['totals']['xlsx_files']],
            ['TXT catálogo', $report['totals']['catalog_txt_files']],
            ['TXT promociones', $report['totals']['promotions_txt_files']],
            ['Filas irregulares', $report['totals']['irregular_rows']],
            ['Secciones duplicadas de catálogo', count($report['catalog']['duplicate_sections'])],
        ]);

        if ($report['missing_files'] !== []) {
            $this->warn('Faltan muestras: '.implode(', ', $report['missing_files']));
        }

        $this->table(
            ['line_type', 'Cantidad'],
            collect($report['totals']['line_types'])
                ->map(static fn (int $count, string $type): array => [$type, $count])
                ->values()
                ->all(),
        );

        foreach ($report['workflow_totals'] as $workflowType => $counts) {
            $this->newLine();
            $this->line("<info>Workflow {$workflowType}</info>");
            $this->table(
                ['Clasificación', 'Cantidad'],
                collect($counts)
                    ->only([
                        'product',
                        'composite_code',
                        'grouped_varios',
                        'invalid_for_catalog_body',
                        'requires_review',
                    ])
                    ->map(static fn (int $count, string $type): array => [$type, $count])
                    ->values()
                    ->all(),
            );
        }

        foreach ($report['catalog']['duplicate_sections'] as $duplicateSection) {
            $this->warn($duplicateSection['message']);
        }

        if (isset($report['output_path'])) {
            $this->line('Reporte guardado en: '.$report['output_path']);
        }
    }

    private function positiveIntegerOption(string $name): int
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($value === false) {
            throw new InvalidArgumentException("La opción --{$name} debe ser un entero positivo.");
        }

        return (int) $value;
    }

    private function outputPath(string $path): string
    {
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }
}
