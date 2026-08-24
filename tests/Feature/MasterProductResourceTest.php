<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\MasterProductResource;
use App\Filament\Admin\Resources\MasterProductResource\Pages\ListMasterProducts;
use App\Filament\Admin\Resources\MasterProductResource\Pages\ViewMasterProduct;
use App\Filament\Admin\Resources\MasterProductResource\RelationManagers\ProductChangeLogsRelationManager;
use App\Models\ImportBatch;
use App\Models\MasterProduct;
use App\Models\NormalizationRule;
use App\Models\ProductChangeLog;
use App\Models\User;
use Dotenv\Dotenv;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class MasterProductResourceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql'];

    private User $user;

    protected function setUpTraits(): array
    {
        $environmentFile = base_path('.env');

        if (! is_file($environmentFile)) {
            throw new RuntimeException('A MySQL database configuration is required to run the resource tests.');
        }

        $environment = Dotenv::parse(file_get_contents($environmentFile));
        $database = $environment['DB_DATABASE'] ?? null;

        if (blank($database) || ($database === ':memory:')) {
            throw new RuntimeException('A persistent MySQL database name is required to run the resource tests.');
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

        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_list_displays_v2_fields_and_searches_key_product_values(): void
    {
        $product = $this->masterProduct();

        Livewire::test(ListMasterProducts::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$product])
            ->assertSee($product->codigo_producto)
            ->assertSee(mb_substr($product->descripcion_catalogo, 0, 30))
            ->assertSee($product->marca_homologada);

        foreach ([
            $product->codigo_producto,
            $product->codigo_original,
            $product->sku_original,
            $product->ean_original,
            $product->nombre_original,
            $product->descripcion_catalogo,
            $product->marca_original,
            $product->marca_homologada,
            $product->categoria_original,
            $product->grupo_original,
            $product->familia_original,
            $product->estado_homologacion,
        ] as $term) {
            Livewire::test(ListMasterProducts::class)
                ->searchTable($term)
                ->assertCanSeeTableRecords([$product]);
        }
    }

    public function test_list_has_review_filters_and_filters_by_homologation_status(): void
    {
        $approved = $this->masterProduct(['estado_homologacion' => 'aprobado_catalogo']);
        $pending = $this->masterProduct(['estado_homologacion' => 'pendiente_revision']);

        Livewire::test(ListMasterProducts::class)
            ->assertTableFilterExists('estado_homologacion')
            ->assertTableFilterExists('requiere_revision')
            ->assertTableFilterExists('marca_homologada')
            ->assertTableFilterExists('categoria_original')
            ->assertTableFilterExists('has_approval')
            ->assertTableFilterExists('missing_ean')
            ->assertTableFilterExists('missing_homologated_brand')
            ->filterTable('estado_homologacion', 'aprobado_catalogo')
            ->assertCanSeeTableRecords([$approved])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_detail_displays_identification_descriptions_classification_control_and_json(): void
    {
        $product = $this->masterProduct();

        Livewire::test(ViewMasterProduct::class, ['record' => $product->getRouteKey()])
            ->assertSuccessful()
            ->assertSee($product->codigo_producto)
            ->assertSee($product->codigo_original)
            ->assertSee($product->sku_original)
            ->assertSee($product->ean_original)
            ->assertSee($product->ean_validado)
            ->assertSee($product->nombre_original)
            ->assertSee($product->descripcion_catalogo)
            ->assertSee($product->marca_original)
            ->assertSee($product->marca_homologada)
            ->assertSee($product->categoria_original)
            ->assertSee($product->grupo_original)
            ->assertSee($product->familia_original)
            ->assertSee($product->medida_original)
            ->assertSee($product->uxb_original)
            ->assertSee((string) $product->uxb_validado)
            ->assertSee($product->estado_homologacion)
            ->assertSee($this->user->name)
            ->assertSee('master-resource-json-marker');
    }

    public function test_change_history_displays_associated_logs_and_is_read_only(): void
    {
        $product = $this->masterProduct();
        $batch = ImportBatch::factory()->create();
        $rule = NormalizationRule::factory()->create(['rule_name' => 'Regla visible en historial']);
        $log = ProductChangeLog::factory()
            ->for($product, 'masterProduct')
            ->for($this->user, 'changedBy')
            ->for($rule, 'rule')
            ->for($batch, 'batch')
            ->create([
                'source' => 'batch_approval',
                'field_name' => 'descripcion_catalogo',
                'old_value' => 'Descripción anterior',
                'new_value' => 'Descripción homologada visible',
                'change_reason' => 'Aprobación individual controlada',
            ]);

        Livewire::test(ProductChangeLogsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => ViewMasterProduct::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$log])
            ->assertSee('batch_approval')
            ->assertSee('descripcion_catalogo')
            ->assertSee('Descripción anterior')
            ->assertSee('Descripción homologada visible')
            ->assertSee('Aprobación individual controlada')
            ->assertSee($this->user->name)
            ->assertSee('Regla visible en historial')
            ->assertTableActionDoesNotExist('create')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');
    }

    public function test_resource_is_read_only_and_reading_does_not_mutate_master_or_logs(): void
    {
        $product = $this->masterProduct();
        $log = ProductChangeLog::factory()
            ->for($product, 'masterProduct')
            ->create();
        $productSnapshot = $product->fresh()->getAttributes();
        $logSnapshot = $log->fresh()->getAttributes();
        $productCount = MasterProduct::query()->count();
        $logCount = ProductChangeLog::query()->count();
        $pages = MasterProductResource::getPages();

        $this->assertSame(['index', 'view'], array_keys($pages));
        $this->assertFalse(MasterProductResource::canCreate());
        $this->assertFalse(MasterProductResource::canEdit($product));
        $this->assertFalse(MasterProductResource::canDelete($product));
        $this->assertFalse(MasterProductResource::canDeleteAny());
        $this->assertFalse(MasterProductResource::canForceDelete($product));
        $this->assertFalse(MasterProductResource::canForceDeleteAny());
        $this->assertFalse(MasterProductResource::canRestore($product));
        $this->assertFalse(MasterProductResource::canRestoreAny());

        Livewire::test(ListMasterProducts::class)
            ->assertActionDoesNotExist('create')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableActionDoesNotExist('forceDelete')
            ->assertTableActionDoesNotExist('restore')
            ->assertTableBulkActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('forceDelete')
            ->assertTableBulkActionDoesNotExist('restore');

        Livewire::test(ViewMasterProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('delete')
            ->assertActionDoesNotExist('forceDelete')
            ->assertActionDoesNotExist('restore');

        $this->assertSame($productSnapshot, $product->fresh()->getAttributes());
        $this->assertSame($logSnapshot, $log->fresh()->getAttributes());
        $this->assertSame($productCount, MasterProduct::query()->count());
        $this->assertSame($logCount, ProductChangeLog::query()->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function masterProduct(array $attributes = []): MasterProduct
    {
        $token = (string) Str::uuid();

        return MasterProduct::factory()->create(array_merge([
            'codigo_producto' => "MASTER-{$token}",
            'codigo_original' => "ORIGINAL-{$token}",
            'sku_original' => "SKU-ORIGINAL-{$token}",
            'ean_original' => '7791234567890',
            'ean_validado' => '7791234567890',
            'nombre_original' => "NOMBRE ORIGINAL {$token}",
            'nombre_sin_marca' => 'Papas fritas 140GR',
            'nombre_homologado' => 'Papas fritas crema y cebolla 140GR',
            'descripcion_catalogo' => "Descripción catálogo {$token}",
            'titulo_shopify' => 'Papas fritas crema y cebolla',
            'descripcion_app' => 'Papas fritas para la app',
            'descripcion_interna' => 'Descripción interna controlada',
            'marca_original' => 'TARAGUI',
            'marca_homologada' => "Taragüi {$token}",
            'marca_detectada_en_nombre' => true,
            'marca_inferida' => 'Taragüi',
            'requiere_revision_marca' => false,
            'nivel_confianza_marca' => 'high',
            'categoria_original' => 'Almacén',
            'categoria_homologada' => 'Almacén',
            'grupo_original' => "Snacks {$token}",
            'grupo_homologado' => 'Snacks',
            'familia_original' => "Papas fritas {$token}",
            'familia_homologada' => 'Papas fritas',
            'medida_original' => '140 GRS',
            'contenido_valor' => 140,
            'unidad_original' => 'GRS',
            'unidad_normalizada' => 'GR',
            'cantidad_unidades' => 1,
            'medida_valor' => 140,
            'medida_catalogo' => '140GR',
            'medida_requiere_revision' => false,
            'uxb_original' => '12',
            'uxb_validado' => 12,
            'uxb_requiere_revision' => false,
            'estado_homologacion' => 'aprobado_catalogo',
            'requiere_revision' => false,
            'observaciones' => 'Producto aprobado desde staging',
            'approved_by_id' => $this->user->getKey(),
            'approved_at' => now(),
            'data' => ['marker' => 'master-resource-json-marker'],
        ], $attributes));
    }
}
