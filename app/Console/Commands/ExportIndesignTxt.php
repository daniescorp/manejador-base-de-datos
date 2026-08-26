<?php

namespace App\Console\Commands;

use App\Services\Exports\IndesignTxtExportService;
use App\Services\ExternalFiles\ExternalPriceMapBuilder;
use App\Services\ExternalFiles\ExternalRowsReader;
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
                            {--prices-file= : Archivo externo XLSX/TXT usado como fuente de precios}
                            {--limit= : Cantidad máxima de productos a exportar}
                            {--include-category-group : Completar categoría y grupo desde el producto maestro}
                            {--json : Imprimir el resultado como JSON}';

    protected $description = 'Exporta productos maestros aprobados al formato TXT tabulado TAPA AMBA';

    public function handle(
        IndesignTxtExportService $exportService,
        ExternalRowsReader $rowsReader,
        ExternalPriceMapBuilder $priceMapBuilder,
    ): int {
        try {
            $limit = $this->positiveIntegerOption('limit');
            $dryRun = (bool) $this->option('dry-run');
            $requestedPriceFile = trim((string) $this->option('prices-file'));
            $priceMap = [];
            $priceWarnings = [];
            $priceReaderMetadata = null;

            if ($requestedPriceFile !== '') {
                $priceRows = $rowsReader->read($this->inputPath($requestedPriceFile));
                $priceBuild = $priceMapBuilder->build($rowsReader->rowsForPriceMap($priceRows));
                $priceMap = $priceBuild['price_map'];
                $priceWarnings = [
                    ...$priceRows['warnings'],
                    ...$priceBuild['warnings'],
                ];
                $priceReaderMetadata = $priceRows['metadata'];
            }

            $export = $exportService->generate(
                $limit,
                (bool) $this->option('include-category-group'),
                $priceMap,
            );
            $priceWarnings = [...$priceWarnings, ...$export['price_warnings']];
            $priceReviewCount = count(array_filter(
                $priceWarnings,
                static fn (array $warning): bool => (bool) ($warning['requires_review'] ?? false),
            ));
            $priceBlockedCount = count(array_filter(
                $priceWarnings,
                static fn (array $warning): bool => ($warning['severity'] ?? null) === 'blocked',
            ));
            $blocked = ! $dryRun && $priceBlockedCount > 0;
            $filePath = null;

            if (! $dryRun && ! $blocked) {
                $filePath = $this->outputPath();
                File::ensureDirectoryExists(dirname($filePath));

                if (File::put($filePath, $export['content']) === false) {
                    throw new RuntimeException("No se pudo escribir el archivo {$filePath}.");
                }
            }

            $result = [
                'status' => $blocked ? 'blocked' : ($dryRun ? 'dry_run' : 'exported'),
                'reason' => $blocked ? 'price_file_has_blocking_warnings' : null,
                'rows' => $export['rows'],
                'file_path' => $filePath,
                'preview_lines' => array_slice($export['lines'], 0, 10),
                'format' => IndesignTxtExportService::FORMAT,
                'delimiter' => 'tab',
                'columns' => IndesignTxtExportService::COLUMNS,
                'prices_source' => $requestedPriceFile === '' ? $export['prices_source'] : 'external_file',
                'price_file' => $requestedPriceFile !== '' ? $requestedPriceFile : null,
                'price_reader_metadata' => $priceReaderMetadata,
                'price_map_count' => count($priceMap),
                'price_requires_review' => $priceReviewCount > 0,
                'price_review_count' => $priceReviewCount,
                'price_blocked_count' => $priceBlockedCount,
                'price_warnings' => $priceWarnings,
                'skipped_missing_measure' => $export['skipped_missing_measure'],
                'skipped_missing_measure_codes' => $export['skipped_missing_measure_codes'],
                'exported_measure_exceptions' => $export['exported_measure_exceptions'],
                'exported_measure_exception_codes' => $export['exported_measure_exception_codes'],
            ];
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->writeJson([
                    'status' => 'error',
                    'reason' => 'price_file_or_export_error',
                    'message' => $exception->getMessage(),
                    'file_path' => null,
                ]);
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        $this->renderResult($result);

        if ($blocked) {
            if (! $this->option('json')) {
                $this->error('El archivo de precios contiene warnings bloqueantes. Corregilo o usá --dry-run para diagnosticar.');
            }

            return self::FAILURE;
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

    private function inputPath(string $requestedPath): string
    {
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $requestedPath) === 1) {
            return $requestedPath;
        }

        return base_path($requestedPath);
    }

    /** @param array<string, mixed> $result */
    private function renderResult(array $result): void
    {
        if ($this->option('json')) {
            $this->writeJson($result);

            return;
        }

        $this->table(['Dato', 'Resultado'], [
            ['Estado', $result['status']],
            ['Filas', $result['rows']],
            ['Fuente de precios', $result['prices_source']],
            ['Precios mapeados', $result['price_map_count']],
            ['Warnings de precios', $result['price_review_count']],
            ['Bloqueos de precios', $result['price_blocked_count']],
            ['Omitidos sin medida', $result['skipped_missing_measure']],
            ['Exportados por excepción', $result['exported_measure_exceptions']],
            ['Archivo', $result['file_path'] ?? 'No creado'],
        ]);

        foreach ($result['preview_lines'] as $line) {
            $this->line($line);
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
