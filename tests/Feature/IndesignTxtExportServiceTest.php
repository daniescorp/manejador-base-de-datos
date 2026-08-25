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
            'codigo_producto' => '30385',
            'marca_homologada' => 'GALLO',
            'descripcion_catalogo' => 'Arroz curry',
            'uxb_original' => '10',
        ]);
        $this->createProduct(['codigo_producto' => 'EXCLUDED-NOT-APPROVED', 'estado_homologacion' => null]);
        $this->createProduct(['codigo_producto' => 'EXCLUDED-OTHER-STATE', 'estado_homologacion' => 'pendiente_revision']);
        $this->createProduct(['codigo_producto' => 'EXCLUDED-INACTIVE', 'status' => 'inactive']);
        $this->createProduct(['codigo_producto' => 'EXCLUDED-REVIEW', 'requiere_revision' => true]);
        $this->createProduct(['codigo_producto' => 'EXCLUDED-NO-BRAND', 'marca_homologada' => '  ']);
        $this->createProduct(['codigo_producto' => 'EXCLUDED-NO-DESCRIPTION', 'descripcion_catalogo' => null]);
        $this->createProduct([
            'codigo_producto' => 'EXCLUDED-NO-MEASURE',
            'medida_catalogo' => null,
            'medida_requiere_revision' => false,
        ]);
        $masterCount = MasterProduct::query()->count();
        $logCount = ProductChangeLog::query()->count();

        $export = $this->service()->generate();
        $headerColumns = explode("\t", $export['lines'][0]);
        $productColumns = explode("\t", $export['lines'][1]);
        $expectedHeader = "CATEGORIA\tGRUPO\tCODIGO\tMARCA\tDESCRIPCION\tUXB\t@folder\tPRECIOLISTA\t@folder\t PRECIOOFERTA \t PRECIOTACHADO \t@folder\t@folder\tConca\tConca";

        $this->assertSame(1, $export['rows']);
        $this->assertSame($expectedHeader, IndesignTxtExportService::HEADER);
        $this->assertSame($expectedHeader, $export['lines'][0]);
        $this->assertCount(15, $headerColumns);
        $this->assertCount(15, $productColumns);
        $this->assertSame('', $productColumns[0]);
        $this->assertSame('', $productColumns[1]);
        $this->assertSame('30385', $productColumns[2]);
        $this->assertSame('GALLO', $productColumns[3]);
        $this->assertSame('Arroz curry', $productColumns[4]);
        $this->assertSame('10', $productColumns[5]);
        $this->assertSame('.\\imagenes\\30385.png', $productColumns[6]);
        $this->assertSame('', $productColumns[7]);
        $this->assertSame('', $productColumns[9]);
        $this->assertSame('', $productColumns[10]);
        $this->assertStringNotContainsString(';', $export['content']);
        $this->assertSame(
            $expectedHeader."\r\n".$export['lines'][1],
            $export['content'],
        );
        $this->assertFalse(str_starts_with($export['content'], "\xEF\xBB\xBF"));
        $this->assertSame(1, $export['skipped_missing_measure']);
        $this->assertSame(['EXCLUDED-NO-MEASURE'], $export['skipped_missing_measure_codes']);
        $this->assertSame(0, $export['exported_measure_exceptions']);
        $this->assertSame([], $export['exported_measure_exception_codes']);
        $this->assertSame('external_pending', $export['prices_source']);
        $this->assertFalse($export['price_requires_review']);
        $this->assertSame(0, $export['price_review_count']);
        $this->assertSame([], $export['price_warnings']);
        $this->assertSame($masterCount, MasterProduct::query()->count());
        $this->assertSame($logCount, ProductChangeLog::query()->count());
    }

    public function test_it_formats_external_prices_in_the_three_indesign_columns(): void
    {
        $this->createProduct(['codigo_producto' => '30385']);

        $export = $this->service()->generate(externalPrices: [
            '30385' => [
                'precio_lista' => 3699,
                'PRECIOOFERTA' => '3.699',
                'precio_tachado' => '$3.699',
            ],
        ]);
        $columns = explode("\t", $export['lines'][1]);

        $this->assertCount(15, $columns);
        $this->assertSame('$ 3.699', $columns[7]);
        $this->assertSame('$ 3.699', $columns[9]);
        $this->assertSame('$ 3.699', $columns[10]);
        $this->assertSame('external_provided', $export['prices_source']);
        $this->assertFalse($export['price_requires_review']);
        $this->assertSame([], $export['price_warnings']);
    }

    public function test_it_accepts_zero_decimal_prices_and_keeps_empty_prices_empty(): void
    {
        $prices = $this->service()->formatExternalPrices([
            'PRECIOLISTA' => '3.699,00',
            'precio_oferta' => '',
            'PRECIOTACHADO' => null,
        ]);

        $this->assertSame([
            'precio_lista' => '$ 3.699',
            'precio_oferta' => '',
            'precio_tachado' => '',
        ], $prices['formatted_values']);
        $this->assertFalse($prices['requires_review']);
        $this->assertSame([], $prices['warnings']);
    }

    public function test_it_does_not_export_real_cents_and_reports_price_review(): void
    {
        $this->createProduct(['codigo_producto' => '61267']);

        $export = $this->service()->generate(externalPrices: [
            ['CODIGO' => '61267', 'PRECIOLISTA' => '1699,50'],
        ]);
        $columns = explode("\t", $export['lines'][1]);
        $warning = $export['price_warnings'][0];

        $this->assertSame('', $columns[7]);
        $this->assertTrue($export['price_requires_review']);
        $this->assertSame(1, $export['price_review_count']);
        $this->assertSame('61267', $warning['code']);
        $this->assertSame('PRECIOLISTA', $warning['field']);
        $this->assertSame('1699,50', $warning['original_value']);
        $this->assertSame('requires_review', $warning['status']);
        $this->assertTrue($warning['requires_review']);
        $this->assertStringContainsString('centavos', $warning['warning']);
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
        $this->assertCount(3, $limitedExport['lines']);
        $this->assertSame('001', explode("\t", $limitedExport['lines'][1])[2]);
        $this->assertSame('003', explode("\t", $limitedExport['lines'][2])[2]);
    }

    public function test_it_can_optionally_include_category_and_group_without_price_fields(): void
    {
        $product = $this->createProduct([
            'codigo_producto' => 'WITH-CATEGORY',
            'categoria_original' => 'Alimentos',
            'grupo_original' => 'Arroz',
        ]);

        $export = $this->service()->generate(includeCategoryGroup: true);
        $columns = explode("\t", $export['lines'][1]);

        $this->assertSame('Alimentos', $columns[0]);
        $this->assertSame('Arroz', $columns[1]);
        $this->assertSame([], array_intersect(
            ['precio_lista', 'precio_oferta', 'precio_tachado'],
            array_keys($product->fresh()->getAttributes()),
        ));
    }

    public function test_it_exports_a_manual_measurement_exception_and_reports_its_code(): void
    {
        $this->createProduct([
            'codigo_producto' => 'NO-MEASURE-APPLIES',
            'medida_catalogo' => null,
            'medida_requiere_revision' => false,
            'data' => [
                'measurement' => [
                    'not_applicable' => true,
                    'not_applicable_reason' => 'Producto sin presentación física',
                ],
            ],
        ]);

        $export = $this->service()->generate();

        $this->assertSame(1, $export['rows']);
        $this->assertSame(1, $export['exported_measure_exceptions']);
        $this->assertSame(['NO-MEASURE-APPLIES'], $export['exported_measure_exception_codes']);
        $this->assertSame(0, $export['skipped_missing_measure']);
        $this->assertSame('NO-MEASURE-APPLIES', explode("\t", $export['lines'][1])[2]);
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
            'medida_catalogo' => '100GR',
            'medida_requiere_revision' => false,
        ], $attributes));
    }
}
