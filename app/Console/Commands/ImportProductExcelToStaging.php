<?php

namespace App\Console\Commands;

use App\Services\Imports\ProductExcelStagingImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportProductExcelToStaging extends Command
{
    protected $signature = 'app:import-product-excel-to-staging
                            {file : Ruta al archivo Excel de productos}
                            {--dry-run : Auditar y calcular el resultado sin escribir en la base de datos}
                            {--batch-name= : Nombre del lote de importación}
                            {--replace-existing : Solicitar reemplazo seguro sin borrar historial}
                            {--json : Imprimir el resultado completo como JSON}';

    protected $description = 'Importa un Excel de productos a staging sin modificar productos maestros';

    public function handle(ProductExcelStagingImportService $importService): int
    {
        try {
            $result = $importService->import((string) $this->argument('file'), [
                'dry_run' => (bool) $this->option('dry-run'),
                'batch_name' => $this->option('batch-name'),
                'replace_existing' => (bool) $this->option('replace-existing'),
            ]);
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
            $this->renderSummary($result);
        }

        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderSummary(array $result): void
    {
        $this->info(match ($result['status']) {
            'dry_run' => 'Dry-run de importación Excel a staging',
            'already_imported' => 'Importación omitida: el archivo ya estaba importado',
            'failed' => 'Importación Excel a staging fallida',
            default => 'Importación Excel a staging completada',
        });

        $this->table(['Dato', 'Resultado'], [
            ['Estado', $result['status']],
            ['Filas de producto', $result['product_rows']],
            ['Filas que importaría', $result['would_import_rows']],
            ['Filas importadas', $result['imported_rows']],
            ['Filas existentes omitidas', $result['skipped_existing_rows']],
            ['Filas para revisión', $result['requires_review_rows']],
            ['Filas pendientes sin observaciones', $result['pending_rows']],
            ['ImportBatch', $result['batch_id'] ?? 'No creado'],
            ['ImportFile', $result['file_id'] ?? 'No creado'],
        ]);

        if (filled($result['message'] ?? null)) {
            $this->line($result['message']);
        }

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        $this->newLine();
        $this->line('Este flujo no modifica master_products, no aprueba normalizaciones y no genera product_change_logs.');
    }
}
