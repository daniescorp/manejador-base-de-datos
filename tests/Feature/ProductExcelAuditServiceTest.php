<?php

namespace Tests\Feature;

use App\Models\MasterProduct;
use App\Models\ProductChangeLog;
use App\Models\ProductStagingRow;
use App\Services\Imports\ProductExcelAuditService;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

class ProductExcelAuditServiceTest extends TestCase
{
    use DatabaseTransactions;

    private const HEADERS = [
        'Sku',
        'Nombre Sku',
        'UXB',
        'Ean',
        'Categoria',
        'Grupo',
        'Familia',
        'Marca',
    ];

    protected array $connectionsToTransact = ['mysql'];

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function setUpTraits(): array
    {
        $environmentFile = base_path('.env');

        if (! is_file($environmentFile)) {
            throw new RuntimeException('A MySQL database configuration is required to run the domain tests.');
        }

        $environment = Dotenv::parse(file_get_contents($environmentFile));
        $database = $environment['DB_DATABASE'] ?? null;

        if (blank($database) || ($database === ':memory:')) {
            throw new RuntimeException('A persistent MySQL database name is required to run the domain tests.');
        }

        config()->set([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => $database,
        ]);

        return parent::setUpTraits();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_it_audits_a_valid_excel_with_a_base_sheet_and_expected_columns(): void
    {
        $report = $this->audit([
            $this->row('SKU-1', 'PRODUCTO UNO'),
        ]);

        $this->assertSame(1, $report['total_sheets']);
        $this->assertSame(['Base'], $report['sheet_names']);
        $this->assertSame('Base', $report['main_sheet']);
        $this->assertSame('A1:H2', $report['used_range']);
        $this->assertSame(self::HEADERS, $report['headers_detected']);
        $this->assertSame([], $report['missing_headers']);
    }

    public function test_it_returns_ok_when_all_columns_are_present_with_tolerant_spaces(): void
    {
        $headers = [' Sku ', 'Nombre   Sku', ' UXB', 'Ean ', 'Categoria', 'Grupo', 'Familia', 'Marca'];

        $report = $this->audit([$this->row()], $headers);

        $this->assertSame('ok', $report['status']);
        $this->assertSame([], $report['missing_headers']);
    }

    public function test_it_returns_failed_and_lists_missing_columns(): void
    {
        $headers = array_values(array_filter(
            self::HEADERS,
            static fn (string $header): bool => ! in_array($header, ['Ean', 'Marca'], true),
        ));

        $report = $this->audit([
            ['SKU-1', 'PRODUCTO', 1, 10, 20, 30],
        ], $headers);

        $this->assertSame('failed', $report['status']);
        $this->assertSame(['Ean', 'Marca'], $report['missing_headers']);
    }

    public function test_it_calculates_product_rows_and_ignores_fully_empty_rows(): void
    {
        $report = $this->audit([
            $this->row('SKU-1'),
            array_fill(0, 8, null),
            $this->row('SKU-2'),
        ]);

        $this->assertSame(3, $report['total_rows']);
        $this->assertSame(2, $report['product_rows']);
    }

    public function test_it_detects_duplicated_skus_and_returns_examples(): void
    {
        $report = $this->audit([
            $this->row(' SKU-1 ', 'PRODUCTO A', ean: '7790000000001', brand: 'MARCA A'),
            $this->row('SKU-1', 'PRODUCTO B', ean: '7790000000002', brand: 'MARCA B'),
            $this->row('SKU-2'),
        ]);

        $this->assertSame(1, $report['duplicated_sku_groups']);
        $this->assertSame(2, $report['duplicated_sku_rows']);
        $this->assertCount(1, $report['examples_duplicated_skus']);
        $this->assertSame('SKU-1', $report['examples_duplicated_skus'][0]['sku']);
        $this->assertSame([2, 3], $report['examples_duplicated_skus'][0]['row_numbers']);
        $this->assertSame(['PRODUCTO A', 'PRODUCTO B'], $report['examples_duplicated_skus'][0]['nombre_sku_values']);
        $this->assertSame(['7790000000001', '7790000000002'], $report['examples_duplicated_skus'][0]['ean_values']);
        $this->assertSame(['MARCA A', 'MARCA B'], $report['examples_duplicated_skus'][0]['marca_values']);
    }

    public function test_sku_duplicate_normalization_only_trims_outer_spaces(): void
    {
        $report = $this->audit([
            $this->row('SKU  1'),
            $this->row('SKU 1'),
        ]);

        $this->assertSame(0, $report['duplicated_sku_groups']);
        $this->assertSame(0, $report['duplicated_sku_rows']);
    }

    public function test_duplicated_skus_do_not_fail_or_block_the_audit(): void
    {
        $report = $this->audit([
            $this->row('SKU-1'),
            $this->row('SKU-1'),
        ]);

        $this->assertSame('ok', $report['status']);
        $this->assertFalse($report['duplicate_skus_are_blocking']);
        $this->assertStringContainsString(
            'No bloquear importación a staging; marcar para revisión.',
            $report['examples_duplicated_skus'][0]['observacion_sugerida'],
        );
    }

    public function test_it_detects_empty_non_numeric_and_zero_uxb_values(): void
    {
        $report = $this->audit([
            $this->row('SKU-1', uxb: ''),
            $this->row('SKU-2', uxb: 'NO NUMERICO'),
            $this->row('SKU-3', uxb: 0),
            $this->row('SKU-4', uxb: '2,5'),
            $this->row('SKU-5', uxb: 2.5),
        ]);

        $this->assertSame(1, $report['uxb_empty_rows']);
        $this->assertSame(1, $report['uxb_non_numeric_rows']);
        $this->assertSame(1, $report['uxb_zero_rows']);
    }

    public function test_it_detects_ean_edge_cases_and_duplicates(): void
    {
        $report = $this->audit([
            $this->row('SKU-1', ean: ''),
            $this->row('SKU-2', ean: 1),
            $this->row('SKU-3', ean: 2),
            $this->row('SKU-4', ean: 'ABC12345'),
            $this->row('SKU-5', ean: '1234567'),
            $this->row('SKU-6', ean: '7790000000001'),
            $this->row('SKU-7', ean: '7790000000001'),
        ]);

        $this->assertSame(1, $report['ean_empty_rows']);
        $this->assertSame(1, $report['ean_one_rows']);
        $this->assertSame(1, $report['ean_two_rows']);
        $this->assertSame(4, $report['ean_invalid_length_rows']);
        $this->assertSame(1, $report['duplicated_ean_groups']);
        $this->assertSame(2, $report['duplicated_ean_rows']);
    }

    public function test_it_detects_zero_or_empty_classification_and_brand_values(): void
    {
        $report = $this->audit([
            $this->row('SKU-1', category: 0, group: '0', family: '', brand: null),
            $this->row('SKU-2', category: '', group: null, family: 0, brand: '0'),
        ]);

        $this->assertSame(2, $report['categoria_zero_rows']);
        $this->assertSame(2, $report['grupo_zero_rows']);
        $this->assertSame(2, $report['familia_zero_rows']);
        $this->assertSame(2, $report['marca_zero_rows']);
    }

    public function test_it_detects_a_slash_in_nombre_sku(): void
    {
        $report = $this->audit([$this->row(name: 'CAFE/COGN')]);

        $this->assertSame(1, $report['rows_with_slash_in_nombre_sku']);
    }

    public function test_it_detects_a_dot_in_nombre_sku(): void
    {
        $report = $this->audit([$this->row(name: 'P.HIGIENICO')]);

        $this->assertSame(1, $report['rows_with_dot_in_nombre_sku']);
    }

    public function test_it_detects_double_spaces_in_nombre_sku(): void
    {
        $report = $this->audit([$this->row(name: 'PRODUCTO  DOBLE')]);

        $this->assertSame(1, $report['rows_with_double_spaces_in_nombre_sku']);
    }

    public function test_it_detects_mx_as_a_token_but_not_inside_another_word(): void
    {
        $report = $this->audit([
            $this->row('SKU-1', 'ROLLO 30 MX 4 UN'),
            $this->row('SKU-2', 'PRODUCTO BMX'),
            $this->row('SKU-3', 'mx-ESPECIAL'),
        ]);

        $this->assertSame(2, $report['rows_with_mx_in_nombre_sku']);
    }

    public function test_it_detects_arlistan_and_manon_brands_case_insensitively(): void
    {
        $report = $this->audit([
            $this->row('SKU-1', brand: ' arlistan '),
            $this->row('SKU-2', brand: 'MaNoN'),
            $this->row('SKU-3', brand: 'OTRA'),
        ]);

        $this->assertSame(1, $report['rows_with_arlistan_brand']);
        $this->assertSame(1, $report['rows_with_manon_brand']);
    }

    public function test_it_returns_the_expected_mapping_to_product_staging_rows(): void
    {
        $report = $this->audit([$this->row()]);

        $this->assertSame([
            'Sku' => 'codigo_producto_original',
            'Nombre Sku' => 'nombre_sku_original',
            'UXB' => 'uxb_original',
            'Ean' => 'ean_original',
            'Categoria' => 'categoria_original',
            'Grupo' => 'grupo_original',
            'Familia' => 'familia_original',
            'Marca' => 'marca_original',
            'Fila completa' => 'raw_data',
        ], $report['mapping']);
    }

    public function test_the_service_does_not_create_product_staging_rows(): void
    {
        $count = ProductStagingRow::query()->count();

        $this->audit([$this->row()]);

        $this->assertSame($count, ProductStagingRow::query()->count());
    }

    public function test_the_service_does_not_modify_master_products(): void
    {
        $count = MasterProduct::query()->count();
        $maximumUpdatedAt = MasterProduct::query()->max('updated_at');

        $this->audit([$this->row()]);

        $this->assertSame($count, MasterProduct::query()->count());
        $this->assertEquals($maximumUpdatedAt, MasterProduct::query()->max('updated_at'));
    }

    public function test_the_service_does_not_create_product_change_logs(): void
    {
        $count = ProductChangeLog::query()->count();

        $this->audit([$this->row()]);

        $this->assertSame($count, ProductChangeLog::query()->count());
    }

    public function test_the_artisan_command_audits_a_temporary_file(): void
    {
        $file = $this->createWorkbook([$this->row()]);

        $exitCode = Artisan::call('app:audit-product-excel', ['file' => $file]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Auditoría técnica del Excel de productos', $output);
        $this->assertStringContainsString('Mapping propuesto hacia product_staging_rows', $output);
    }

    public function test_the_artisan_command_supports_json_output(): void
    {
        $file = $this->createWorkbook([$this->row()]);

        $exitCode = Artisan::call('app:audit-product-excel', [
            'file' => $file,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame(1, $decoded['product_rows']);
        $this->assertSame('codigo_producto_original', $decoded['mapping']['Sku']);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $headers
     * @return array<string, mixed>
     */
    private function audit(array $rows, array $headers = self::HEADERS): array
    {
        return app(ProductExcelAuditService::class)->audit(
            $this->createWorkbook($rows, $headers),
        );
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $headers
     */
    private function createWorkbook(array $rows, array $headers = self::HEADERS): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Base');

        foreach ($headers as $column => $header) {
            $sheet->setCellValue([$column + 1, 1], $header);
        }

        foreach ($rows as $row => $values) {
            foreach ($values as $column => $value) {
                $sheet->setCellValue([$column + 1, $row + 2], $value);
            }
        }

        $file = tempnam(sys_get_temp_dir(), 'product-excel-audit-');

        if ($file === false) {
            throw new RuntimeException('No fue posible crear el archivo Excel temporal.');
        }

        (new Xlsx($spreadsheet))->save($file);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryFiles[] = $file;

        return $file;
    }

    /**
     * @return array<int, mixed>
     */
    private function row(
        mixed $sku = 'SKU-1',
        mixed $name = 'PRODUCTO UNO',
        mixed $uxb = 1,
        mixed $ean = '7790000000001',
        mixed $category = 10,
        mixed $group = 20,
        mixed $family = 30,
        mixed $brand = 'MARCA',
    ): array {
        return [$sku, $name, $uxb, $ean, $category, $group, $family, $brand];
    }
}
