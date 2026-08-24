<?php

namespace Tests\Feature;

use App\Models\MasterProduct;
use App\Models\ProductChangeLog;
use App\Services\Exports\IndesignTxtExportService;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class IndesignTxtExportServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql'];

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

    public function test_it_exports_only_approved_active_complete_products_without_database_writes(): void
    {
        $this->createProduct([
            'codigo_producto' => 'EXPORT-100',
            'marca_homologada' => 'GALLO',
            'descripcion_catalogo' => 'Arroz curry',
        ]);
        $this->createProduct(['codigo_producto' => 'EXCLUDED-NOT-APPROVED', 'estado_homologacion' => null]);
        $this->createProduct(['codigo_producto' => 'EXCLUDED-OTHER-STATE', 'estado_homologacion' => 'pendiente_revision']);
        $this->createProduct(['codigo_producto' => 'EXCLUDED-INACTIVE', 'status' => 'inactive']);
        $this->createProduct(['codigo_producto' => 'EXCLUDED-REVIEW', 'requiere_revision' => true]);
        $this->createProduct(['codigo_producto' => 'EXCLUDED-NO-BRAND', 'marca_homologada' => '  ']);
        $this->createProduct(['codigo_producto' => 'EXCLUDED-NO-DESCRIPTION', 'descripcion_catalogo' => null]);
        $masterCount = MasterProduct::query()->count();
        $logCount = ProductChangeLog::query()->count();

        $export = $this->service()->generate();

        $this->assertSame(1, $export['rows']);
        $this->assertSame(['GALLO;Arroz curry'], $export['lines']);
        $this->assertSame('GALLO;Arroz curry', $export['content']);
        $this->assertSame($masterCount, MasterProduct::query()->count());
        $this->assertSame($logCount, ProductChangeLog::query()->count());
    }

    public function test_it_orders_by_brand_description_and_code_and_applies_a_limit(): void
    {
        $this->createProduct(['codigo_producto' => '002', 'marca_homologada' => 'BETA', 'descripcion_catalogo' => 'Zeta']);
        $this->createProduct(['codigo_producto' => '004', 'marca_homologada' => 'ALFA', 'descripcion_catalogo' => 'Zeta']);
        $this->createProduct(['codigo_producto' => '003', 'marca_homologada' => 'ALFA', 'descripcion_catalogo' => 'Arroz']);
        $this->createProduct(['codigo_producto' => '001', 'marca_homologada' => 'ALFA', 'descripcion_catalogo' => 'Arroz']);

        $products = $this->service()->approvedProducts();
        $limitedExport = $this->service()->generate(2);

        $this->assertSame(['001', '003', '004', '002'], $products->pluck('codigo_producto')->all());
        $this->assertSame(2, $limitedExport['rows']);
        $this->assertSame(['ALFA;Arroz', 'ALFA;Arroz'], $limitedExport['lines']);
    }

    private function service(): IndesignTxtExportService
    {
        return app(IndesignTxtExportService::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createProduct(array $attributes = []): MasterProduct
    {
        $token = (string) Str::uuid();

        return MasterProduct::factory()->create(array_merge([
            'sku' => "EXPORT-{$token}",
            'codigo_producto' => "EXPORT-{$token}",
            'status' => 'active',
            'estado_homologacion' => 'aprobado_catalogo',
            'requiere_revision' => false,
            'marca_homologada' => 'MARCA EXPORT',
            'descripcion_catalogo' => 'Producto exportable',
        ], $attributes));
    }
}
