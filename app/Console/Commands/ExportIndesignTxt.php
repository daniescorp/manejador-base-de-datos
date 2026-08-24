<?php

namespace App\Console\Commands;

use App\Services\Exports\IndesignTxtExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ExportIndesignTxt extends Command
{
    protected $signature = 'app:export-indesign-txt
                            {--dry-run : Generar la vista previa sin crear el archivo}
                            {--output= : Nombre o ruta personalizada del archivo TXT}
                            {--limit= : Cantidad máxima de productos a exportar}
                            {--json : Imprimir el resultado como JSON}';

    protected $description = 'Exporta productos maestros aprobados a un TXT delimitado para InDesign';

    public function handle(IndesignTxtExportService $exportService): int
    {
        try {
            $limit = $this->positiveIntegerOption('limit');
            $dryRun = (bool) $this->option('dry-run');
            $export = $exportService->generate($limit);
            $filePath = null;

            if (! $dryRun) {
                $filePath = $this->outputPath();
                File::ensureDirectoryExists(dirname($filePath));

                if (File::put($filePath, $export['content']) === false) {
                    throw new RuntimeException("No se pudo escribir el archivo {$filePath}.");
                }
            }

            $result = [
                'status' => $dryRun ? 'dry_run' : 'exported',
                'rows' => $export['rows'],
                'file_path' => $filePath,
                'preview_lines' => array_slice($export['lines'], 0, 10),
            ];
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
        } else {
            $this->table(['Dato', 'Resultado'], [
                ['Estado', $result['status']],
                ['Filas', $result['rows']],
                ['Archivo', $result['file_path'] ?? 'No creado (dry-run)'],
            ]);

            foreach ($result['preview_lines'] as $line) {
                $this->line($line);
            }
        }

        return self::SUCCESS;
    }

    private function positiveIntegerOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new InvalidArgumentException("La opción --{$name} debe ser un entero positivo.");
        }

        return (int) $value;
    }

    private function outputPath(): string
    {
        $requestedPath = trim((string) $this->option('output'));

        if ($requestedPath === '') {
            return storage_path('app/exports/indesign-products-'.now()->format('Ymd-His').'.txt');
        }

        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $requestedPath) === 1) {
            return $requestedPath;
        }

        return storage_path('app/exports/'.$requestedPath);
    }
}
