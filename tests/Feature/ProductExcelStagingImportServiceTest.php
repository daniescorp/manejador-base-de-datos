<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\ImportFile;
use App\Models\MasterProduct;
use App\Models\ProductChangeLog;
use App\Models\ProductStagingRow;
use App\Services\Imports\ProductExcelStagingImportService;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

class ProductExcelStagingImportServiceTest extends TestCase
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

    public function test_dry_run_does_not_write_to_the_database(): void
    {
        $before = $this->databaseCounts();

        $result = $this->import([$this->row()], ['dry_run' => true]);

        $this->assertSame('dry_run', $result['status']);
        $this->assertSame(1, $result['would_import_rows']);
        $this->assertSame(0, $result['imported_rows']);
        $this->assertNull($result['batch_id']);
        $this->assertNull($result['file_id']);
        $this->assertSame($before, $this->databaseCounts());
    }

    public function test_real_import_creates_an_import_batch(): void
    {
        $result = $this->import([$this->row()], ['batch_name' => 'Lote temporal de productos']);
        $batch = ImportBatch::query()->findOrFail($result['batch_id']);

        $this->assertSame('Lote temporal de productos', $batch->name);
        $this->assertSame('product_excel_import', $batch->process_type);
        $this->assertSame('product_excel', $batch->source_type);
        $this->assertSame('completed', $batch->status);
        $this->assertNotNull($batch->started_at);
        $this->assertNotNull($batch->finished_at);
    }

    public function test_real_import_creates_an_import_file(): void
    {
        $result = $this->import([$this->row()]);
        $file = ImportFile::query()->findOrFail($result['file_id']);

        $this->assertSame($result['batch_id'], $file->import_batch_id);
        $this->assertSame('xlsx', $file->file_type);
        $this->assertSame(1, $file->total_rows);
        $this->assertSame(1, $file->valid_rows);
        $this->assertSame(0, $file->error_rows);
        $this->assertSame('ok', $file->meta['audit_status']);
    }

    public function test_real_import_creates_a_product_staging_row(): void
    {
        $result = $this->import([$this->row()]);
        $stagingRow = ProductStagingRow::query()->where('import_file_id', $result['file_id'])->sole();

        $this->assertSame('pending', $stagingRow->status);
        $this->assertFalse($stagingRow->requires_review);
        $this->assertNull($stagingRow->master_product_id);
        $this->assertNull($stagingRow->import_row_id);
    }

    public function test_it_maps_sku_to_codigo_producto_original(): void
    {
        $this->import([$this->row(sku: 'SKU-MAPEADO')]);

        $this->assertSame('SKU-MAPEADO', ProductStagingRow::query()->latest('id')->value('codigo_producto_original'));
    }

    public function test_it_maps_nombre_sku_to_nombre_sku_original(): void
    {
        $this->import([$this->row(name: 'NOMBRE ORIGINAL SIN CAMBIOS')]);

        $this->assertSame(
            'NOMBRE ORIGINAL SIN CAMBIOS',
            ProductStagingRow::query()->latest('id')->value('nombre_sku_original'),
        );
    }

    public function test_raw_data_preserves_the_original_row_with_excel_headers(): void
    {
        $source = $this->row(
            sku: ' SKU CON ESPACIOS ',
            name: 'PRODUCTO  ORIGINAL',
            uxb: '2,5',
            ean: ' 7790000000001 ',
            category: '01',
            group: '02',
            family: '03',
            brand: ' MARCA ',
        );

        $this->import([$source]);
        $rawData = ProductStagingRow::query()->latest('id')->firstOrFail()->raw_data;

        $this->assertEquals(array_combine(self::HEADERS, $source), $rawData);
    }

    public function test_duplicated_skus_do_not_block_import(): void
    {
        $result = $this->import([
            $this->row(sku: 'SKU-DUPLICADO', ean: '7790000000001'),
            $this->row(sku: ' SKU-DUPLICADO ', ean: '7790000000002'),
        ]);

        $this->assertSame(2, $result['imported_rows']);
        $this->assertSame(2, ProductStagingRow::query()->where('import_batch_id', $result['batch_id'])->count());
    }

    public function test_duplicated_skus_are_marked_for_review(): void
    {
        $result = $this->import([
            $this->row(sku: 'SKU-DUPLICADO', ean: '7790000000001'),
            $this->row(sku: 'SKU-DUPLICADO', ean: '7790000000002'),
        ]);

        $rows = ProductStagingRow::query()->where('import_batch_id', $result['batch_id'])->get();

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every->requires_review);
        $rows->each(fn (ProductStagingRow $row) => $this->assertStringContainsString(
            'SKU duplicado en base origen',
            $row->review_reason,
        ));
    }

    public function test_uxb_zero_is_marked_for_review(): void
    {
        $row = $this->importedRow($this->row(uxb: 0));

        $this->assertReviewReason($row, 'UXB igual a 0');
    }

    public function test_empty_uxb_is_marked_for_review(): void
    {
        $row = $this->importedRow($this->row(uxb: ''));

        $this->assertReviewReason($row, 'UXB vacío');
    }

    public function test_non_numeric_uxb_is_marked_for_review(): void
    {
        $row = $this->importedRow($this->row(uxb: 'NO NUMERICO'));

        $this->assertReviewReason($row, 'UXB no numérico');
    }

    public function test_brand_zero_is_marked_for_review(): void
    {
        $row = $this->importedRow($this->row(brand: 0));

        $this->assertReviewReason($row, 'Marca original vacía o 0');
    }

    public function test_zero_category_group_and_family_are_marked_for_review(): void
    {
        $row = $this->importedRow($this->row(category: 0, group: '0', family: null));

        $this->assertReviewReason($row, 'Categoria original vacía o 0');
        $this->assertReviewReason($row, 'Grupo original vacía o 0');
        $this->assertReviewReason($row, 'Familia original vacía o 0');
    }

    public function test_ean_one_is_marked_for_review(): void
    {
        $this->assertReviewReason($this->importedRow($this->row(ean: 1)), 'EAN inválido o sospechoso');
    }

    public function test_ean_two_is_marked_for_review(): void
    {
        $this->assertReviewReason($this->importedRow($this->row(ean: 2)), 'EAN inválido o sospechoso');
    }

    public function test_ean_with_invalid_length_is_marked_for_review(): void
    {
        $this->assertReviewReason($this->importedRow($this->row(ean: '1234567')), 'EAN inválido o sospechoso');
    }

    public function test_ean_with_letters_is_marked_for_review(): void
    {
        $this->assertReviewReason($this->importedRow($this->row(ean: 'ABC12345')), 'EAN inválido o sospechoso');
    }

    public function test_import_is_idempotent_by_row_hash(): void
    {
        $file = $this->createWorkbook([$this->row(sku: 'SKU-IDEMPOTENTE')]);
        $service = app(ProductExcelStagingImportService::class);

        $first = $service->import($file);
        $countAfterFirstImport = ProductStagingRow::query()->count();
        $batchCountAfterFirstImport = ImportBatch::query()->count();
        $fileCountAfterFirstImport = ImportFile::query()->count();
        $second = $service->import($file);

        $this->assertSame(1, $first['imported_rows']);
        $this->assertSame('already_imported', $second['status']);
        $this->assertSame(0, $second['imported_rows']);
        $this->assertSame(1, $second['skipped_existing_rows']);
        $this->assertNull($second['batch_id']);
        $this->assertNull($second['file_id']);
        $this->assertSame($batchCountAfterFirstImport, ImportBatch::query()->count());
        $this->assertSame($fileCountAfterFirstImport, ImportFile::query()->count());
        $this->assertSame($countAfterFirstImport, ProductStagingRow::query()->count());
        $this->assertSame(
            'No se crearon batch/file porque todas las filas ya existían por row_hash.',
            $second['message'],
        );
    }

    public function test_command_reports_an_idempotent_import_without_creating_empty_batch_or_file(): void
    {
        $file = $this->createWorkbook([$this->row(sku: 'SKU-COMANDO-IDEMPOTENTE')]);
        app(ProductExcelStagingImportService::class)->import($file);
        $before = $this->databaseCounts();

        $exitCode = Artisan::call('app:import-product-excel-to-staging', ['file' => $file]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Importación omitida', $output);
        $this->assertStringContainsString(
            'No se crearon batch/file porque todas las filas ya existían por row_hash.',
            $output,
        );
        $this->assertSame($before, $this->databaseCounts());
    }

    public function test_row_hash_includes_the_original_row_number(): void
    {
        $result = $this->import([
            $this->row(sku: 'SKU-MISMO', ean: '7790000000001'),
            $this->row(sku: 'SKU-MISMO', ean: '7790000000001'),
        ]);
        $hashes = ProductStagingRow::query()
            ->where('import_batch_id', $result['batch_id'])
            ->pluck('row_hash');

        $this->assertCount(2, $hashes);
        $this->assertCount(2, $hashes->unique());
        $this->assertTrue($hashes->every(static fn (?string $hash): bool => strlen((string) $hash) === 64));
    }

    public function test_import_does_not_modify_master_products(): void
    {
        $count = MasterProduct::query()->count();
        $maximumUpdatedAt = MasterProduct::query()->max('updated_at');

        $this->import([$this->row()]);

        $this->assertSame($count, MasterProduct::query()->count());
        $this->assertEquals($maximumUpdatedAt, MasterProduct::query()->max('updated_at'));
    }

    public function test_import_does_not_create_product_change_logs(): void
    {
        $count = ProductChangeLog::query()->count();

        $this->import([$this->row()]);

        $this->assertSame($count, ProductChangeLog::query()->count());
    }

    public function test_command_works_with_dry_run(): void
    {
        $file = $this->createWorkbook([$this->row()]);
        $before = $this->databaseCounts();

        $exitCode = Artisan::call('app:import-product-excel-to-staging', [
            'file' => $file,
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Dry-run de importación Excel a staging', Artisan::output());
        $this->assertSame($before, $this->databaseCounts());
    }

    public function test_command_works_with_json(): void
    {
        $file = $this->createWorkbook([$this->row()]);

        $exitCode = Artisan::call('app:import-product-excel-to-staging', [
            'file' => $file,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('dry_run', $result['status']);
        $this->assertSame(1, $result['would_import_rows']);
        $this->assertSame(0, $result['imported_rows']);
    }

    public function test_real_import_command_works_with_a_temporary_file(): void
    {
        $file = $this->createWorkbook([$this->row(sku: 'SKU-COMANDO')]);

        $exitCode = Artisan::call('app:import-product-excel-to-staging', [
            'file' => $file,
            '--batch-name' => 'Lote desde comando temporal',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Importación Excel a staging completada', Artisan::output());
        $this->assertDatabaseHas('import_batches', ['name' => 'Lote desde comando temporal']);
        $this->assertDatabaseHas('product_staging_rows', ['codigo_producto_original' => 'SKU-COMANDO']);
    }

    public function test_missing_columns_return_a_safe_error_without_importing(): void
    {
        $headers = array_values(array_filter(
            self::HEADERS,
            static fn (string $header): bool => $header !== 'Marca',
        ));
        $file = $this->createWorkbook([
            ['SKU-1', 'PRODUCTO', 1, '7790000000001', 1, 2, 3],
        ], $headers);
        $before = $this->databaseCounts();

        $result = app(ProductExcelStagingImportService::class)->import($file);

        $this->assertSame('failed', $result['status']);
        $this->assertSame(0, $result['imported_rows']);
        $this->assertStringContainsString('Marca', $result['errors'][0]);
        $this->assertSame($before, $this->databaseCounts());
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function import(array $rows, array $options = []): array
    {
        return app(ProductExcelStagingImportService::class)->import(
            $this->createWorkbook($rows),
            $options,
        );
    }

    /**
     * @param  array<int, mixed>  $source
     */
    private function importedRow(array $source): ProductStagingRow
    {
        $result = $this->import([$source]);

        return ProductStagingRow::query()->where('import_batch_id', $result['batch_id'])->sole();
    }

    private function assertReviewReason(ProductStagingRow $row, string $reason): void
    {
        $this->assertSame('requires_review', $row->status);
        $this->assertTrue($row->requires_review);
        $this->assertStringContainsString($reason, $row->review_reason);
    }

    /**
     * @return array{batches: int, files: int, staging_rows: int}
     */
    private function databaseCounts(): array
    {
        return [
            'batches' => ImportBatch::query()->count(),
            'files' => ImportFile::query()->count(),
            'staging_rows' => ProductStagingRow::query()->count(),
        ];
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

        $temporaryFile = tempnam(sys_get_temp_dir(), 'product-staging-import-');

        if ($temporaryFile === false) {
            throw new RuntimeException('No fue posible crear el archivo Excel temporal.');
        }

        $file = $temporaryFile.'.xlsx';
        unlink($temporaryFile);
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
