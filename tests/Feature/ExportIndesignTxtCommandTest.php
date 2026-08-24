<?php

namespace Tests\Feature;

use App\Models\MasterProduct;
use App\Models\ProductChangeLog;
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
        $this->assertSame(['GALLO;Arroz curry'], $result['preview_lines']);
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
        $this->assertSame(['GALLO;Arroz curry'], $result['preview_lines']);
        $this->assertFileExists($path);
        $this->assertSame('GALLO;Arroz curry', File::get($path));
        $this->assertStringNotContainsString('No exportar', File::get($path));
        $this->assertSame($masterCount, MasterProduct::query()->count());
        $this->assertSame($logCount, ProductChangeLog::query()->count());
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function runJson(array $options): array
    {
        $exitCode = Artisan::call('app:export-indesign-txt', $options);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);

        try {
            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Invalid command JSON: '.json_encode($output), previous: $exception);
        }
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
        ], $attributes));
    }
}
