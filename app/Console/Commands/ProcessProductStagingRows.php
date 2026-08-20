<?php

namespace App\Console\Commands;

use App\Models\NormalizationSuggestion;
use App\Models\ProductStagingRow;
use App\Services\Normalization\ProductStagingAnalyzer;
use App\Services\Normalization\ProductStagingPreviewComposer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

class ProcessProductStagingRows extends Command
{
    private const TERMINAL_STATUSES = [
        'approved',
        'rejected',
        'imported_to_master',
        'excluded',
    ];

    private const PROCESSING_MODES = [
        'analyze',
        'preview',
        'all',
    ];

    protected $signature = 'app:process-product-staging-rows
                            {--id= : Limitar el procesamiento a una fila de staging}
                            {--batch-id= : Limitar el procesamiento a un ImportBatch}
                            {--limit= : Cantidad máxima de filas a procesar}
                            {--only=all : analyze, preview o all}
                            {--json : Imprimir el resultado completo como JSON}
                            {--dry-run : Contar filas sin escribir en la base de datos}';

    protected $description = 'Ejecuta analyzer y preview sobre staging sin aprobar ni modificar productos maestros';

    public function handle(
        ProductStagingAnalyzer $analyzer,
        ProductStagingPreviewComposer $previewComposer,
    ): int {
        try {
            $rowId = $this->positiveIntegerOption('id');
            $batchId = $this->positiveIntegerOption('batch-id');
            $limit = $this->positiveIntegerOption('limit');
            $only = mb_strtolower((string) $this->option('only'), 'UTF-8');

            if (! in_array($only, self::PROCESSING_MODES, true)) {
                throw new InvalidArgumentException('La opción --only debe ser analyze, preview o all.');
            }

            $result = $this->process(
                $analyzer,
                $previewComposer,
                $rowId,
                $batchId,
                $limit,
                $only,
                (bool) $this->option('dry-run'),
            );
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

        return $result['status'] === 'completed_with_errors' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function process(
        ProductStagingAnalyzer $analyzer,
        ProductStagingPreviewComposer $previewComposer,
        ?int $rowId,
        ?int $batchId,
        ?int $limit,
        string $only,
        bool $dryRun,
    ): array {
        $query = ProductStagingRow::query();

        if ($rowId !== null) {
            $query->whereKey($rowId);
        }

        if ($batchId !== null) {
            $query->where('import_batch_id', $batchId);
        }

        $matchedRows = (clone $query)->count();
        $eligibleQuery = $this->eligibleQuery(clone $query);
        $eligibleRows = (clone $eligibleQuery)->count();
        $selectedRows = $this->selectedRows($eligibleQuery, $limit);
        $selectedIds = $selectedRows->modelKeys();
        $suggestionsBefore = $this->suggestionCount($selectedIds);
        $previewsBefore = $this->previewCount($selectedIds);
        $warnings = $batchId === null && $rowId === null
            ? ['No se indicó --id ni --batch-id; el alcance incluye todas las filas elegibles de staging.']
            : [];
        $result = [
            'status' => $dryRun ? 'dry_run' : ($selectedRows->isEmpty() ? 'empty' : 'completed'),
            'dry_run' => $dryRun,
            'id' => $rowId,
            'batch_id' => $batchId,
            'limit' => $limit,
            'only' => $only,
            'matched_rows' => $matchedRows,
            'eligible_rows' => $eligibleRows,
            'would_process_rows' => $selectedRows->count(),
            'processed_rows' => 0,
            'analyzed_rows' => 0,
            'previewed_rows' => 0,
            'skipped_rows' => max(0, $matchedRows - $selectedRows->count()),
            'suggestions_before' => $suggestionsBefore,
            'suggestions_after' => $suggestionsBefore,
            'previews_before' => $previewsBefore,
            'previews_after' => $previewsBefore,
            'requires_review_count' => $this->requiresReviewCount($selectedIds),
            'warnings' => $warnings,
            'errors' => [],
        ];

        if ($dryRun || $selectedRows->isEmpty()) {
            return $result;
        }

        foreach ($selectedRows as $row) {
            try {
                if ($only === 'analyze' || $only === 'all') {
                    $analyzer->analyze($row);
                    $result['analyzed_rows']++;
                }

                if ($only === 'preview' || $only === 'all') {
                    $previewComposer->compose($row->fresh());
                    $result['previewed_rows']++;
                }

                $result['processed_rows']++;
            } catch (Throwable $exception) {
                $result['errors'][] = [
                    'product_staging_row_id' => $row->getKey(),
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $result['skipped_rows'] = max(0, $matchedRows - $result['processed_rows']);
        $result['suggestions_after'] = $this->suggestionCount($selectedIds);
        $result['previews_after'] = $this->previewCount($selectedIds);
        $result['requires_review_count'] = $this->requiresReviewCount($selectedIds);

        if ($result['errors'] !== []) {
            $result['status'] = 'completed_with_errors';
        }

        return $result;
    }

    /**
     * @param  Builder<ProductStagingRow>  $query
     * @return Builder<ProductStagingRow>
     */
    private function eligibleQuery(Builder $query): Builder
    {
        return $query
            ->whereNull('approved_at')
            ->whereNull('approved_by_id')
            ->whereNotIn('status', self::TERMINAL_STATUSES);
    }

    /**
     * @param  Builder<ProductStagingRow>  $query
     * @return Collection<int, ProductStagingRow>
     */
    private function selectedRows(Builder $query, ?int $limit): Collection
    {
        $query->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * @param  array<int, int|string>  $rowIds
     */
    private function suggestionCount(array $rowIds): int
    {
        return $rowIds === []
            ? 0
            : NormalizationSuggestion::query()
                ->whereIn('product_staging_row_id', $rowIds)
                ->count();
    }

    /**
     * @param  array<int, int|string>  $rowIds
     */
    private function previewCount(array $rowIds): int
    {
        return $rowIds === []
            ? 0
            : ProductStagingRow::query()
                ->whereIn('id', $rowIds)
                ->whereNotNull('normalized_preview')
                ->count();
    }

    /**
     * @param  array<int, int|string>  $rowIds
     */
    private function requiresReviewCount(array $rowIds): int
    {
        return $rowIds === []
            ? 0
            : ProductStagingRow::query()
                ->whereIn('id', $rowIds)
                ->where('requires_review', true)
                ->count();
    }

    private function positiveIntegerOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($validated === false) {
            throw new InvalidArgumentException("La opción --{$name} debe ser un entero positivo.");
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderSummary(array $result): void
    {
        $this->info($result['dry_run']
            ? 'Dry-run de procesamiento de staging'
            : 'Procesamiento de staging finalizado');
        $this->table(['Dato', 'Resultado'], [
            ['Estado', $result['status']],
            ['Fila', $result['id'] ?? 'Todas'],
            ['Batch', $result['batch_id'] ?? 'Todos'],
            ['Modo', $result['only']],
            ['Filas que procesaría', $result['would_process_rows']],
            ['Filas procesadas', $result['processed_rows']],
            ['Analizadas', $result['analyzed_rows']],
            ['Con preview ejecutado', $result['previewed_rows']],
            ['Omitidas por filtro/límite/error', $result['skipped_rows']],
            ['Sugerencias antes/después', "{$result['suggestions_before']} / {$result['suggestions_after']}"],
            ['Previews antes/después', "{$result['previews_before']} / {$result['previews_after']}"],
            ['Requieren revisión', $result['requires_review_count']],
        ]);

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        foreach ($result['errors'] as $error) {
            $this->error("Fila {$error['product_staging_row_id']}: {$error['message']}");
        }

        $this->newLine();
        $this->line('El comando no aprueba filas, no modifica master_products y no crea product_change_logs.');
    }
}
