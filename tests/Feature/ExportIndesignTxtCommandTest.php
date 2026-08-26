<?php

namespace Tests\Feature;

use App\Models\MasterProduct;
use App\Models\ProductChangeLog;
use App\Services\Exports\IndesignTxtExportService;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ExportIndesignTxtCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql'];

    /** @var list<string> */
    private array $createdFiles = [];

    protected function setUpTraits(): array
    {
        $environmentFile = base_path('.env');

        if (! is_file($environmentFile)) {
            throw new RuntimeException('A MySQL database configuration is required to run the export tests.');
        }

        $environment = Dotenv::parse(file_get_contents($environmentFile));
        $database = $environment['DB_DATABASE'] ?? null;

        if (blank($database) || $database === ':memory:') {
            throw new RuntimeException('A persistent MySQL database name is required to run the export tests.');
        }

        config()->set([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => $database,
        ]);

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        MasterProduct::query()->update(['status' => 'inactive']);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            File::delete($file);
        }

        parent::tearDown();
    }

    public function test_dry_run_returns_json_preview_honors_limit_and_creates_no_file(): void
    {
        $this->createProduct(['codigo_producto' => 'EXPORT-001']);
        $this->createProduct(['codigo_producto' => 'EXPORT-002', 'marca_homologada' => 'ZETA']);
        $this->createProduct(['codigo_producto' => 'EXCLUDED', 'estado_homologacion' => 'pendiente_revision']);
        $this->createProduct([
            'codigo_producto' => 'MISSING-MEASURE',
            'medida_catalogo' => null,
            'medida_requiere_revision' => false,
        ]);
        $path = storage_path('app/exports/dry-run-'.Str::uuid().'.txt');
        $masterCount = MasterProduct::query()->count();
        $logCount = ProductChangeLog::query()->count();

        $result = $this->runJson([
            '--dry-run' => true,
            '--json' => true,
            '--limit' => 1,
            '--output' => $path,
        ]);

        $this->assertSame('dry_run', $result['status']);
        $this->assertSame(1, $result['rows']);
        $this->assertNull($result['file_path']);
        $this->assertSame(IndesignTxtExportService::HEADER, $result['preview_lines'][0]);
        $this->assertSame('EXPORT-001', explode("\t", $result['preview_lines'][1])[2]);
        $this->assertSame('indesign_tapa_amba_tab_txt', $result['format']);
        $this->assertSame('tab', $result['delimiter']);
        $this->assertSame(15, $result['columns']);
        $this->assertSame('external_pending', $result['prices_source']);
        $this->assertNull($result['price_file']);
        $this->assertNull($result['price_reader_metadata']);
        $this->assertSame(0, $result['price_map_count']);
        $this->assertFalse($result['price_requires_review']);
        $this->assertSame(0, $result['price_review_count']);
        $this->assertSame(0, $result['price_blocked_count']);
        $this->assertSame([], $result['price_warnings']);
        $this->assertSame(1, $result['skipped_missing_measure']);
        $this->assertSame(['MISSING-MEASURE'], $result['skipped_missing_measure_codes']);
        $this->assertSame(0, $result['exported_measure_exceptions']);
        $this->assertSame([], $result['exported_measure_exception_codes']);
        $this->assertFileDoesNotExist($path);
        $this->assertSame($masterCount, MasterProduct::query()->count());
        $this->assertSame($logCount, ProductChangeLog::query()->count());
    }

    public function test_real_command_creates_the_expected_txt_without_including_unapproved_products(): void
    {
        $this->createProduct(['codigo_producto' => 'EXPORT-REAL']);
        $this->createProduct([
            'codigo_producto' => 'EXCLUDED-REAL',
            'estado_homologacion' => 'pendiente_revision',
            'descripcion_catalogo' => 'No exportar',
        ]);
        $path = storage_path('app/exports/indesign-command-test-'.Str::uuid().'.txt');
        $this->createdFiles[] = $path;
        $masterCount = MasterProduct::query()->count();
        $logCount = ProductChangeLog::query()->count();

        $result = $this->runJson([
            '--json' => true,
            '--output' => $path,
        ]);

        $this->assertSame('exported', $result['status']);
        $this->assertSame(1, $result['rows']);
        $this->assertSame($path, $result['file_path']);
        $this->assertSame(IndesignTxtExportService::HEADER, $result['preview_lines'][0]);
        $this->assertFileExists($path);
        $lines = explode("\r\n", File::get($path));
        $productColumns = explode("\t", $lines[1]);

        $this->assertSame(IndesignTxtExportService::HEADER, $lines[0]);
        $this->assertCount(15, $productColumns);
        $this->assertSame('.\\imagenes\\EXPORT-REAL.png', $productColumns[6]);
        $this->assertSame('', $productColumns[7]);
        $this->assertSame('', $productColumns[9]);
        $this->assertSame('', $productColumns[10]);
        $this->assertSame('external_pending', $result['prices_source']);
        $this->assertSame(0, $result['price_map_count']);
        $this->assertFalse($result['price_requires_review']);
        $this->assertSame(0, $result['price_blocked_count']);
        $this->assertSame([], $result['price_warnings']);
        $this->assertStringNotContainsString('No exportar', File::get($path));
        $this->assertSame($masterCount, MasterProduct::query()->count());
        $this->assertSame($logCount, ProductChangeLog::query()->count());
    }

    public function test_dry_run_reads_builds_and_passes_external_prices_while_reporting_warnings(): void
    {
        $this->createProduct(['codigo_producto' => '30385']);
        $this->createProduct(['codigo_producto' => '61267', 'marca_homologada' => 'NORTON']);
        $priceFile = $this->priceFile(
            "CODIGO\tPRECIOLISTA\tPRECIOOFERTA\tPRECIOTACHADO\n"
            ."30385\t\t3699\t\n"
            ."VARIOS\t\t1499\t\n"
            ."40104 - 40105\t\t1699\t\n"
            ."60157 -\t\t529\t",
        );
        $output = storage_path('app/exports/blocked-dry-run-'.Str::uuid().'.txt');
        $masterCount = MasterProduct::query()->count();
        $logCount = ProductChangeLog::query()->count();

        $result = $this->runJson([
            '--prices-file' => $priceFile,
            '--dry-run' => true,
            '--json' => true,
            '--output' => $output,
        ]);
        $issues = array_column($result['price_warnings'], 'issue');
        $firstProduct = explode("\t", $result['preview_lines'][1]);
        $secondProduct = explode("\t", $result['preview_lines'][2]);

        $this->assertSame('dry_run', $result['status']);
        $this->assertNull($result['reason']);
        $this->assertSame('external_file', $result['prices_source']);
        $this->assertSame($priceFile, $result['price_file']);
        $this->assertSame('txt', $result['price_reader_metadata']['format']);
        $this->assertSame(4, $result['price_reader_metadata']['row_count']);
        $this->assertSame(1, $result['price_map_count']);
        $this->assertTrue($result['price_requires_review']);
        $this->assertSame(3, $result['price_review_count']);
        $this->assertSame(1, $result['price_blocked_count']);
        $this->assertContains('grouped_varios_not_mapped', $issues);
        $this->assertContains('composite_code_not_mapped', $issues);
        $this->assertContains('incomplete_composite_code', $issues);
        $this->assertSame('$ 3.699', $firstProduct[9]);
        $this->assertSame('', $secondProduct[9]);
        $this->assertCount(15, explode("\t", $result['preview_lines'][0]));
        $this->assertCount(15, $firstProduct);
        $this->assertSame(IndesignTxtExportService::HEADER, $result['preview_lines'][0]);
        $this->assertFileDoesNotExist($output);
        $this->assertSame($masterCount, MasterProduct::query()->count());
        $this->assertSame($logCount, ProductChangeLog::query()->count());
    }

    public function test_real_export_aborts_without_writing_when_price_file_has_blocking_warnings(): void
    {
        $this->createProduct(['codigo_producto' => '30385']);
        $priceFile = $this->priceFile(
            "CODIGO\tPRECIOOFERTA\n30385\t3699\n60157 -\t529",
        );
        $output = storage_path('app/exports/blocked-price-export-'.Str::uuid().'.txt');
        $masterCount = MasterProduct::query()->count();
        $logCount = ProductChangeLog::query()->count();

        $result = $this->runJsonWithExitCode([
            '--prices-file' => $priceFile,
            '--json' => true,
            '--output' => $output,
        ], 1);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('price_file_has_blocking_warnings', $result['reason']);
        $this->assertNull($result['file_path']);
        $this->assertSame('external_file', $result['prices_source']);
        $this->assertSame(1, $result['price_map_count']);
        $this->assertSame(1, $result['price_blocked_count']);
        $this->assertTrue($result['price_requires_review']);
        $this->assertSame('incomplete_composite_code', $result['price_warnings'][0]['issue']);
        $this->assertFileDoesNotExist($output);
        $this->assertSame($masterCount, MasterProduct::query()->count());
        $this->assertSame($logCount, ProductChangeLog::query()->count());
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function runJson(array $options): array
    {
        return $this->runJsonWithExitCode($options, 0);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function runJsonWithExitCode(array $options, int $expectedExitCode): array
    {
        $exitCode = Artisan::call('app:export-indesign-txt', $options);
        $output = Artisan::output();

        $this->assertSame($expectedExitCode, $exitCode, $output);

        try {
            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Invalid command JSON: '.json_encode($output), previous: $exception);
        }
    }

    private function priceFile(string $content): string
    {
        $path = storage_path('app/exports/external-prices-'.Str::uuid().'.txt');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);
        $this->createdFiles[] = $path;

        return $path;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createProduct(array $attributes = []): MasterProduct
    {
        $token = (string) Str::uuid();

        return MasterProduct::factory()->create(array_merge([
            'sku' => "EXPORT-COMMAND-{$token}",
            'codigo_producto' => "EXPORT-COMMAND-{$token}",
            'status' => 'active',
            'estado_homologacion' => 'aprobado_catalogo',
            'requiere_revision' => false,
            'marca_homologada' => 'GALLO',
            'descripcion_catalogo' => 'Arroz curry',
            'uxb_original' => '10',
            'medida_catalogo' => '100GR',
            'medida_requiere_revision' => false,
        ], $attributes));
    }
}
