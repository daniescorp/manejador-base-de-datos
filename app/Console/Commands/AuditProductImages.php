<?php

namespace App\Console\Commands;

use App\Models\ProductStagingRow;
use App\Services\Products\ProductImageLocator;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class AuditProductImages extends Command
{
    protected $signature = 'app:audit-product-images
                            {--batch-id= : Limitar la auditoría a un ImportBatch}
                            {--limit= : Cantidad máxima de filas a auditar}
                            {--json : Imprimir el resultado completo como JSON}';

    protected $description = 'Audita imágenes PNG por código de producto sin escribir en la base de datos';

    public function handle(ProductImageLocator $locator): int
    {
        try {
            $batchId = $this->positiveIntegerOption('batch-id');
            $limit = $this->positiveIntegerOption('limit');
            $result = $this->audit($locator, $batchId, $limit);
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
            $this->table(['Métrica', 'Cantidad'], [
                ['Filas auditadas', $result['checked_rows']],
                ['Imágenes encontradas', $result['found_images']],
                ['Imágenes faltantes', $result['missing_images']],
                ['Ruta no configurada', $result['not_configured_count']],
                ['Códigos inválidos', $result['invalid_code_count']],
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function audit(
        ProductImageLocator $locator,
        ?int $batchId,
        ?int $limit,
    ): array {
        $query = ProductStagingRow::query()->orderBy('id');

        if ($batchId !== null) {
            $query->where('import_batch_id', $batchId);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $result = [
            'batch_id' => $batchId,
            'limit' => $limit,
            'checked_rows' => 0,
            'found_images' => 0,
            'missing_images' => 0,
            'not_configured_count' => 0,
            'invalid_code_count' => 0,
            'examples_missing' => [],
            'examples_found' => [],
        ];

        foreach ($query->get(['id', 'codigo_producto_original']) as $row) {
            $image = $locator->findByCode($row->codigo_producto_original);
            $result['checked_rows']++;

            match ($image['status']) {
                'found' => $result['found_images']++,
                'missing' => $result['missing_images']++,
                'not_configured' => $result['not_configured_count']++,
                'invalid' => $result['invalid_code_count']++,
            };

            if ($image['status'] === 'found' && count($result['examples_found']) < 5) {
                $result['examples_found'][] = $this->example($row->getKey(), $image);
            }

            if ($image['status'] === 'missing' && count($result['examples_missing']) < 5) {
                $result['examples_missing'][] = $this->example($row->getKey(), $image);
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $image
     * @return array<string, mixed>
     */
    private function example(int $rowId, array $image): array
    {
        return [
            'product_staging_row_id' => $rowId,
            'code' => $image['code'],
            'filename' => $image['filename'],
        ];
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
}
