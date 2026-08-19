<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\MasterProduct;
use App\Models\User;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class MasterProductV2FieldsTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql'];

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

    public function test_it_can_store_a_product_code(): void
    {
        $product = MasterProduct::factory()->create([
            'codigo_producto' => 'COD-V2-1001',
            'codigo_original' => 'CODIGO ORIGINAL 1001',
            'sku_original' => 'SKU FUENTE 1001',
            'ean_original' => '7790000001001',
            'ean_validado' => '7790000001001',
        ])->fresh();

        $this->assertDatabaseHas('master_products', [
            'id' => $product->getKey(),
            'codigo_producto' => 'COD-V2-1001',
        ]);
        $this->assertSame('CODIGO ORIGINAL 1001', $product->codigo_original);
        $this->assertSame('SKU FUENTE 1001', $product->sku_original);
        $this->assertSame('7790000001001', $product->ean_original);
        $this->assertSame('7790000001001', $product->ean_validado);
        $this->assertFalse($product->marca_detectada_en_nombre);
        $this->assertFalse($product->requiere_revision_marca);
        $this->assertFalse($product->medida_requiere_revision);
        $this->assertFalse($product->uxb_requiere_revision);
        $this->assertFalse($product->requiere_revision);
    }

    public function test_product_code_is_not_unique_yet(): void
    {
        $productCode = 'COD-DUP-'.Str::uuid();

        MasterProduct::factory()->create([
            'sku' => null,
            'codigo_producto' => $productCode,
        ]);
        MasterProduct::factory()->create([
            'sku' => null,
            'codigo_producto' => $productCode,
        ]);

        $this->assertSame(
            2,
            MasterProduct::query()->where('codigo_producto', $productCode)->count(),
        );
    }

    public function test_it_stores_original_and_destination_descriptions(): void
    {
        $product = MasterProduct::factory()->create([
            'nombre_original' => 'PRODUCTO ORIGINAL',
            'nombre_sin_marca' => 'Producto sin marca',
            'nombre_homologado' => 'Producto homologado',
            'descripcion_catalogo' => 'Producto para catálogo',
            'titulo_shopify' => 'Producto para Shopify',
            'descripcion_app' => 'Producto para aplicación',
            'descripcion_interna' => 'Descripción operativa interna',
        ])->fresh();

        $this->assertSame('PRODUCTO ORIGINAL', $product->nombre_original);
        $this->assertSame('Producto sin marca', $product->nombre_sin_marca);
        $this->assertSame('Producto homologado', $product->nombre_homologado);
        $this->assertSame('Producto para catálogo', $product->descripcion_catalogo);
        $this->assertSame('Producto para Shopify', $product->titulo_shopify);
        $this->assertSame('Producto para aplicación', $product->descripcion_app);
        $this->assertSame('Descripción operativa interna', $product->descripcion_interna);
    }

    public function test_it_stores_brand_and_classification_fields(): void
    {
        $product = MasterProduct::factory()->create([
            'marca_original' => 'MARCA ORIGINAL',
            'marca_homologada' => 'Marca Homologada',
            'marca_inferida' => 'Marca Inferida',
            'nivel_confianza_marca' => 'high',
            'categoria_original' => 'CATEGORIA ORIGINAL',
            'categoria_homologada' => 'Categoría homologada',
            'grupo_original' => 'GRUPO ORIGINAL',
            'grupo_homologado' => 'Grupo homologado',
            'familia_original' => 'FAMILIA ORIGINAL',
            'familia_homologada' => 'Familia homologada',
        ])->fresh();

        $this->assertSame('MARCA ORIGINAL', $product->marca_original);
        $this->assertSame('Marca Homologada', $product->marca_homologada);
        $this->assertSame('Marca Inferida', $product->marca_inferida);
        $this->assertSame('high', $product->nivel_confianza_marca);
        $this->assertSame('CATEGORIA ORIGINAL', $product->categoria_original);
        $this->assertSame('Categoría homologada', $product->categoria_homologada);
        $this->assertSame('GRUPO ORIGINAL', $product->grupo_original);
        $this->assertSame('Grupo homologado', $product->grupo_homologado);
        $this->assertSame('FAMILIA ORIGINAL', $product->familia_original);
        $this->assertSame('Familia homologada', $product->familia_homologada);
    }

    public function test_it_stores_structured_measurement_fields(): void
    {
        $product = MasterProduct::factory()->create([
            'medida_original' => '750 CC',
            'contenido_valor' => 750,
            'unidad_original' => 'CC',
            'unidad_normalizada' => 'CC',
            'cantidad_unidades' => 1,
            'medida_valor' => 750,
            'medida_catalogo' => '750CC',
        ])->fresh();

        $this->assertSame('750 CC', $product->medida_original);
        $this->assertSame('750.000', $product->contenido_valor);
        $this->assertSame('CC', $product->unidad_original);
        $this->assertSame('CC', $product->unidad_normalizada);
        $this->assertSame(1, $product->cantidad_unidades);
        $this->assertSame('750.000', $product->medida_valor);
        $this->assertSame('750CC', $product->medida_catalogo);
    }

    public function test_it_stores_uxb_fields(): void
    {
        $product = MasterProduct::factory()->create([
            'uxb_original' => '12',
            'uxb_validado' => 12,
            'uxb_requiere_revision' => true,
        ])->fresh();

        $this->assertSame('12', $product->uxb_original);
        $this->assertSame(12, $product->uxb_validado);
        $this->assertTrue($product->uxb_requiere_revision);
    }

    public function test_boolean_control_fields_are_cast_to_booleans(): void
    {
        $product = MasterProduct::factory()->create([
            'marca_detectada_en_nombre' => 1,
            'requiere_revision_marca' => 0,
            'medida_requiere_revision' => 1,
            'uxb_requiere_revision' => 0,
            'requiere_revision' => 1,
            'estado_homologacion' => 'pendiente_revision',
            'observaciones' => 'Requiere validación administrativa',
        ])->fresh();

        $this->assertTrue($product->marca_detectada_en_nombre);
        $this->assertFalse($product->requiere_revision_marca);
        $this->assertTrue($product->medida_requiere_revision);
        $this->assertFalse($product->uxb_requiere_revision);
        $this->assertTrue($product->requiere_revision);
        $this->assertSame('pendiente_revision', $product->estado_homologacion);
        $this->assertSame('Requiere validación administrativa', $product->observaciones);
    }

    public function test_approval_fields_are_cast_and_linked_to_a_user(): void
    {
        $approver = User::factory()->create();
        $product = MasterProduct::factory()
            ->for($approver, 'approvedBy')
            ->create(['approved_at' => now()])
            ->fresh();

        $this->assertTrue($product->approvedBy->is($approver));
        $this->assertSame($approver->getKey(), $product->approved_by_id);
        $this->assertInstanceOf(Carbon::class, $product->approved_at);
    }

    public function test_approver_foreign_key_is_null_when_the_user_is_deleted(): void
    {
        $approver = User::factory()->create();
        $product = MasterProduct::factory()
            ->for($approver, 'approvedBy')
            ->create();

        $approver->delete();
        $product->refresh();

        $this->assertNull($product->approved_by_id);
        $this->assertNull($product->approvedBy);
    }

    public function test_legacy_fields_remain_available(): void
    {
        $batch = ImportBatch::factory()->create();
        $legacySku = 'SKU-LEGACY-'.Str::uuid();
        $product = MasterProduct::factory()
            ->for($batch, 'lastImportBatch')
            ->create([
                'sku' => $legacySku,
                'barcode' => '7790000000001',
                'name' => 'Legacy product name',
                'brand' => 'Legacy brand',
                'category' => 'Legacy category',
                'status' => 'active',
                'source_reference' => 'legacy-test',
                'data' => ['legacy' => true],
            ])
            ->fresh();

        $this->assertSame($legacySku, $product->sku);
        $this->assertSame('7790000000001', $product->barcode);
        $this->assertSame('Legacy product name', $product->name);
        $this->assertSame('Legacy brand', $product->brand);
        $this->assertSame('Legacy category', $product->category);
        $this->assertSame('active', $product->status);
        $this->assertSame('legacy-test', $product->source_reference);
        $this->assertSame(['legacy' => true], $product->data);
        $this->assertTrue($product->lastImportBatch->is($batch));
    }

    public function test_master_products_still_use_soft_deletes(): void
    {
        $product = MasterProduct::factory()->create();

        $product->delete();

        $this->assertSoftDeleted($product);
        $this->assertNull(MasterProduct::find($product->getKey()));
        $this->assertNotNull(MasterProduct::withTrashed()->find($product->getKey()));
    }

    public function test_all_v2_columns_and_requested_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('master_products', [
            'codigo_producto',
            'codigo_original',
            'sku_original',
            'ean_original',
            'ean_validado',
            'nombre_original',
            'nombre_sin_marca',
            'nombre_homologado',
            'descripcion_catalogo',
            'titulo_shopify',
            'descripcion_app',
            'descripcion_interna',
            'marca_original',
            'marca_homologada',
            'marca_detectada_en_nombre',
            'marca_inferida',
            'requiere_revision_marca',
            'nivel_confianza_marca',
            'categoria_original',
            'categoria_homologada',
            'grupo_original',
            'grupo_homologado',
            'familia_original',
            'familia_homologada',
            'medida_original',
            'contenido_valor',
            'unidad_original',
            'unidad_normalizada',
            'cantidad_unidades',
            'medida_valor',
            'medida_catalogo',
            'medida_requiere_revision',
            'uxb_original',
            'uxb_validado',
            'uxb_requiere_revision',
            'estado_homologacion',
            'requiere_revision',
            'observaciones',
            'approved_by_id',
            'approved_at',
        ]));

        foreach ([
            'codigo_producto',
            'marca_homologada',
            'categoria_homologada',
            'estado_homologacion',
            'requiere_revision',
        ] as $indexedColumn) {
            $this->assertTrue(Schema::hasIndex('master_products', [$indexedColumn]));
        }
    }
}
