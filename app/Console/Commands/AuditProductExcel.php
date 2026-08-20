<?php

namespace App\Console\Commands;

use App\Services\Imports\ProductExcelAuditService;
use Illuminate\Console\Command;
use Throwable;

class AuditProductExcel extends Command
{
    protected $signature = 'app:audit-product-excel
                            {file : Ruta al archivo Excel de productos}
                            {--json : Imprimir el reporte completo como JSON}';

    protected $description = 'Audita un Excel de productos sin importar datos ni escribir en la base de datos';

    public function handle(ProductExcelAuditService $auditService): int
    {
        try {
            $report = $auditService->audit((string) $this->argument('file'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
        } else {
            $this->renderSummary($report);
        }

        return $report['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderSummary(array $report): void
    {
        $this->info('Auditoría técnica del Excel de productos');
        $this->table(['Dato', 'Resultado'], [
            ['Estado estructural', $report['status']],
            ['Hoja principal', $report['main_sheet'] ?? 'No encontrada'],
            ['Hojas', implode(', ', $report['sheet_names'])],
            ['Rango usado', $report['used_range'] ?? 'N/D'],
            ['Filas de producto', $report['product_rows']],
            ['Columnas detectadas', implode(', ', $report['headers_detected'])],
            ['Columnas faltantes', $report['missing_headers'] === [] ? 'Ninguna' : implode(', ', $report['missing_headers'])],
        ]);

        $this->newLine();
        $this->line('<info>Duplicados SKU</info>');
        $this->table(['Métrica', 'Cantidad'], [
            ['Grupos duplicados', $report['duplicated_sku_groups']],
            ['Filas involucradas', $report['duplicated_sku_rows']],
            ['Bloquean staging', $report['duplicate_skus_are_blocking'] ? 'Sí' : 'No; requieren revisión'],
        ]);

        $this->newLine();
        $this->line('<info>UXB y EAN</info>');
        $this->table(['Métrica', 'Cantidad'], [
            ['UXB vacío', $report['uxb_empty_rows']],
            ['UXB no numérico', $report['uxb_non_numeric_rows']],
            ['UXB cero', $report['uxb_zero_rows']],
            ['EAN vacío', $report['ean_empty_rows']],
            ['EAN = 1', $report['ean_one_rows']],
            ['EAN = 2', $report['ean_two_rows']],
            ['EAN inválido/sospechoso', $report['ean_invalid_length_rows']],
            ['Grupos EAN duplicados', $report['duplicated_ean_groups']],
            ['Filas con EAN duplicado', $report['duplicated_ean_rows']],
        ]);

        $this->newLine();
        $this->line('<info>Clasificación y marca</info>');
        $this->table(['Métrica', 'Cantidad'], [
            ['Categoría cero/vacía', $report['categoria_zero_rows']],
            ['Grupo cero/vacío', $report['grupo_zero_rows']],
            ['Familia cero/vacía', $report['familia_zero_rows']],
            ['Marca cero/vacía', $report['marca_zero_rows']],
        ]);

        $this->newLine();
        $this->line('<info>Hallazgos de descripción y marca</info>');
        $this->table(['Métrica', 'Cantidad'], [
            ['Nombre con /', $report['rows_with_slash_in_nombre_sku']],
            ['Nombre con .', $report['rows_with_dot_in_nombre_sku']],
            ['Nombre con doble espacio', $report['rows_with_double_spaces_in_nombre_sku']],
            ['Nombre con token MX', $report['rows_with_mx_in_nombre_sku']],
            ['Marca ARLISTAN', $report['rows_with_arlistan_brand']],
            ['Marca MANON', $report['rows_with_manon_brand']],
        ]);

        $this->newLine();
        $this->line('<info>Mapping propuesto hacia product_staging_rows</info>');
        $this->table(
            ['Columna Excel', 'Campo staging'],
            collect($report['mapping'])->map(
                static fn (string $target, string $source): array => [$source, $target],
            )->values()->all(),
        );
    }
}
