<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\ProductStagingRowResource;
use App\Filament\Admin\Resources\ProductStagingRowResource\Pages\ListProductStagingRows;
use App\Filament\Admin\Resources\ProductStagingRowResource\Pages\ViewProductStagingRow;
use App\Filament\Admin\Resources\ProductStagingRowResource\RelationManagers\NormalizationSuggestionsRelationManager;
use App\Models\MasterProduct;
use App\Models\NormalizationRule;
use App\Models\NormalizationSuggestion;
use App\Models\ProductChangeLog;
use App\Models\ProductStagingRow;
use App\Models\User;
use Dotenv\Dotenv;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ProductStagingRowResourceTest extends TestCase
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

    public function test_an_authenticated_admin_can_access_and_search_the_list(): void
    {
        $row = $this->createReviewRow();

        Livewire::test(ListProductStagingRows::class)
            ->assertSuccessful()
            ->searchTable($row->codigo_producto_original)
            ->assertCanSeeTableRecords([$row])
            ->assertSee($row->codigo_producto_original)
            ->assertSee('CAFE ARLISTAN 170 GRS')
            ->assertSee($row->status)
            ->assertSee('CAFÉ ARLISTAN 170GR')
            ->assertSee('Arlistán');

        $this->assertSame(
            'Revisión de Productos Importados',
            ProductStagingRowResource::getNavigationLabel(),
        );
        $this->assertSame('Manejador de Datos', ProductStagingRowResource::getNavigationGroup());
    }

    public function test_an_authenticated_admin_can_view_originals_preview_and_review_state(): void
    {
        $row = $this->createReviewRow();

        Livewire::test(ViewProductStagingRow::class, ['record' => $row->getRouteKey()])
            ->assertSuccessful()
            ->assertSee($row->codigo_producto_original)
            ->assertSee($row->nombre_sku_original)
            ->assertSee('ARLISTAN')
            ->assertSee('CAFÉ ARLISTAN 170GR')
            ->assertSee('Arlistán')
            ->assertSee('SKU duplicado; Marca requiere revisión')
            ->assertSee('raw-test-marker')
            ->assertSee('descripcion_catalogo');
    }

    public function test_detail_exposes_associated_suggestions_and_rule_context_read_only(): void
    {
        $row = $this->createReviewRow();
        $rule = NormalizationRule::factory()->create([
            'detected_value' => 'ARLISTAN',
            'replacement_value' => 'Arlistán',
            'rule_type' => 'brand_normalization',
            'applies_to_field' => 'marca_homologada',
            'is_automatic' => false,
            'requires_review' => true,
            'confidence_level' => 'contextual',
        ]);
        $suggestion = NormalizationSuggestion::factory()
            ->for($row, 'stagingRow')
            ->for($rule, 'rule')
            ->create([
                'field_name' => 'marca_homologada',
                'original_value' => 'ARLISTAN',
                'suggested_value' => 'Arlistán',
                'suggestion_reason' => 'Homologación de marca pendiente',
                'confidence_level' => 'contextual',
                'status' => 'pending',
            ]);

        Livewire::test(NormalizationSuggestionsRelationManager::class, [
            'ownerRecord' => $row,
            'pageClass' => ViewProductStagingRow::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$suggestion])
            ->assertSee('marca_homologada')
            ->assertSee('ARLISTAN')
            ->assertSee('Arlistán')
            ->assertSee('brand_normalization')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');
    }

    public function test_resource_has_stable_review_filters(): void
    {
        Livewire::test(ListProductStagingRows::class)
            ->assertTableFilterExists('import_batch_id')
            ->assertTableFilterExists('status')
            ->assertTableFilterExists('requires_review')
            ->assertTableFilterExists('marca_original')
            ->assertTableFilterExists('categoria_original')
            ->assertTableFilterExists('grupo_original')
            ->assertTableFilterExists('familia_original')
            ->assertTableFilterExists('has_preview')
            ->assertTableFilterExists('has_suggestions')
            ->assertTableFilterExists('has_brand_suggestion')
            ->assertTableFilterExists('duplicate_sku')
            ->assertTableFilterExists('ean_review')
            ->assertTableFilterExists('brand_review')
            ->assertTableFilterExists('mx_token')
            ->assertTableFilterExists('arlistan')
            ->assertTableFilterExists('manon');
    }

    public function test_create_edit_and_destructive_operations_are_not_available(): void
    {
        $row = $this->createReviewRow();
        $pages = ProductStagingRowResource::getPages();

        $this->assertSame(['index', 'view'], array_keys($pages));
        $this->assertFalse(ProductStagingRowResource::canCreate());
        $this->assertFalse(ProductStagingRowResource::canEdit($row));
        $this->assertFalse(ProductStagingRowResource::canDelete($row));
        $this->assertFalse(ProductStagingRowResource::canDeleteAny());
        $this->assertFalse(ProductStagingRowResource::canForceDelete($row));
        $this->assertFalse(ProductStagingRowResource::canForceDeleteAny());

        Livewire::test(ListProductStagingRows::class)
            ->assertActionDoesNotExist('create')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableActionDoesNotExist('forceDelete')
            ->assertTableBulkActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('forceDelete');

        Livewire::test(ViewProductStagingRow::class, ['record' => $row->getRouteKey()])
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('delete')
            ->assertActionDoesNotExist('forceDelete');

    }

    public function test_reading_the_resource_does_not_mutate_protected_data(): void
    {
        $masterProduct = MasterProduct::factory()->create([
            'name' => 'Producto maestro protegido por UI de staging',
        ]);
        $row = $this->createReviewRow([
            'master_product_id' => $masterProduct->getKey(),
        ]);
        $rowSnapshot = $row->fresh()->getAttributes();
        $masterSnapshot = $masterProduct->fresh()->getAttributes();
        $masterCount = MasterProduct::query()->count();
        $changeLogCount = ProductChangeLog::query()->count();

        Livewire::test(ListProductStagingRows::class)
            ->searchTable($row->codigo_producto_original)
            ->assertCanSeeTableRecords([$row]);
        Livewire::test(ViewProductStagingRow::class, ['record' => $row->getRouteKey()])
            ->assertSuccessful();

        $this->assertSame($rowSnapshot, $row->fresh()->getAttributes());
        $this->assertSame($masterSnapshot, $masterProduct->fresh()->getAttributes());
        $this->assertSame($masterCount, MasterProduct::query()->count());
        $this->assertSame($changeLogCount, ProductChangeLog::query()->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createReviewRow(array $attributes = []): ProductStagingRow
    {
        $token = (string) Str::uuid();

        return ProductStagingRow::factory()->create(array_merge([
            'codigo_producto_original' => "UI-{$token}",
            'nombre_sku_original' => "CAFE ARLISTAN 170 GRS {$token}",
            'marca_original' => 'ARLISTAN',
            'categoria_original' => 'Almacén',
            'grupo_original' => 'Café',
            'familia_original' => 'Café instantáneo',
            'status' => 'previewed',
            'requires_review' => true,
            'review_reason' => 'SKU duplicado; Marca requiere revisión',
            'raw_data' => ['marker' => 'raw-test-marker'],
            'normalized_preview' => [
                'descripcion_catalogo' => 'CAFÉ ARLISTAN 170GR',
                'marca_homologada' => 'Arlistán',
                'source_text' => "CAFE ARLISTAN 170 GRS {$token}",
                'source_brand' => 'ARLISTAN',
                'fields' => [
                    'descripcion_catalogo' => ['preview' => 'CAFÉ ARLISTAN 170GR'],
                    'marca_homologada' => ['preview' => 'Arlistán'],
                ],
            ],
            'analyzed_at' => now(),
            'approved_at' => null,
            'approved_by_id' => null,
        ], $attributes));
    }
}
