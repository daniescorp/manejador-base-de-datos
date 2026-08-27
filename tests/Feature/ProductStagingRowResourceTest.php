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
        $this->assertSame('Base de Datos', ProductStagingRowResource::getNavigationGroup());
    }

    public function test_global_search_includes_all_requested_original_fields(): void
    {
        foreach ([
            'codigo_producto_original',
            'nombre_sku_original',
            'marca_original',
            'categoria_original',
            'grupo_original',
            'familia_original',
            'ean_original',
            'uxb_original',
            'review_reason',
        ] as $column) {
            $term = 'original-'.Str::uuid();
            $row = $this->createReviewRow([$column => $term]);

            Livewire::test(ListProductStagingRows::class)
                ->searchTable($term)
                ->assertCanSeeTableRecords([$row]);
        }
    }

    public function test_global_search_includes_requested_normalized_preview_fields(): void
    {
        foreach ([
            'descripcion_catalogo',
            'marca_homologada',
            'source_text',
            'source_brand',
            'fields.descripcion_catalogo.value',
            'fields.marca_homologada.value',
        ] as $path) {
            $term = 'preview-'.Str::uuid();
            $preview = [
                'descripcion_catalogo' => 'Producto base',
                'marca_homologada' => 'Marca base',
                'source_text' => 'Texto fuente base',
                'source_brand' => 'Marca fuente base',
                'fields' => [
                    'descripcion_catalogo' => ['value' => 'Valor base', 'preview' => 'Preview base'],
                    'marca_homologada' => ['value' => 'Marca base', 'preview' => 'Marca preview base'],
                ],
            ];
            data_set($preview, $path, $term);
            $row = $this->createReviewRow(['normalized_preview' => $preview]);

            Livewire::test(ListProductStagingRows::class)
                ->searchTable($term)
                ->assertCanSeeTableRecords([$row]);
        }
    }

    public function test_global_search_includes_suggestion_and_related_rule_fields(): void
    {
        $suggestionColumns = [
            'field_name',
            'original_value',
            'suggested_value',
            'suggestion_reason',
            'confidence_level',
            'status',
        ];
        $ruleColumns = [
            'rule_name',
            'detected_value',
            'replacement_value',
            'rule_type',
            'context',
            'notes',
        ];

        foreach ($suggestionColumns as $column) {
            $term = 'suggestion-'.Str::uuid();
            $row = $this->createReviewRow();
            NormalizationSuggestion::factory()
                ->for($row, 'stagingRow')
                ->create([$column => $term]);

            Livewire::test(ListProductStagingRows::class)
                ->searchTable($term)
                ->assertCanSeeTableRecords([$row]);
        }

        foreach ($ruleColumns as $column) {
            $term = 'rule-'.Str::uuid();
            $row = $this->createReviewRow();
            $rule = NormalizationRule::factory()->create([$column => $term]);
            NormalizationSuggestion::factory()
                ->for($row, 'stagingRow')
                ->for($rule, 'rule')
                ->create();

            Livewire::test(ListProductStagingRows::class)
                ->searchTable($term)
                ->assertCanSeeTableRecords([$row]);
        }
    }

    public function test_global_search_finds_representative_product_and_review_terms(): void
    {
        $row = $this->createReviewRow([
            'nombre_sku_original' => 'PAPAS FRITAS TARAGUI 750CC',
            'marca_original' => 'TARAGUI',
            'status' => '000-search-test',
            'review_reason' => 'SKU duplicado; EAN sospechoso; requiere revisión',
            'normalized_preview' => [
                'descripcion_catalogo' => 'Té sin ensobrar crema y cebolla 140GR 50 sobres',
                'marca_homologada' => 'Taragüi',
            ],
        ]);
        $rule = NormalizationRule::factory()->create([
            'rule_name' => 'Homologar ARLISTAN y MANON',
            'detected_value' => 'CEBOLL',
            'replacement_value' => 'CEBOLLA',
            'notes' => 'Revisar token MX',
        ]);
        NormalizationSuggestion::factory()
            ->for($row, 'stagingRow')
            ->for($rule, 'rule')
            ->create();

        foreach ([
            'cebolla',
            'crema y cebolla',
            'TARAGUI',
            'Taragüi',
            'Té',
            'sin ensobrar',
            'sobres',
            'papas fritas',
            '140GR',
            '750CC',
            'ARLISTAN',
            'MANON',
            'CEBOLL',
            'MX',
            'EAN sospechoso',
            'SKU duplicado',
            'requiere revisión',
        ] as $term) {
            Livewire::test(ListProductStagingRows::class)
                ->searchTable($term)
                ->assertCanSeeTableRecords([$row]);
        }
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

    public function test_detail_shows_individual_approval_only_for_eligible_rows(): void
    {
        $eligibleRow = $this->createReviewRow([
            'status' => 'previewed',
            'requires_review' => false,
            'review_reason' => null,
        ]);

        Livewire::test(ViewProductStagingRow::class, ['record' => $eligibleRow->getRouteKey()])
            ->assertActionExists('approveProduct')
            ->assertActionVisible('approveProduct');

        $blockedRows = [
            $this->createReviewRow([
                'status' => 'approved',
                'requires_review' => false,
                'approved_at' => now(),
                'approved_by_id' => $this->user->getKey(),
            ]),
            $this->createReviewRow(['requires_review' => true]),
            $this->createReviewRow([
                'requires_review' => false,
                'normalized_preview' => null,
            ]),
            $this->createReviewRow([
                'requires_review' => false,
                'codigo_producto_original' => ' ',
            ]),
            $this->createReviewRow([
                'requires_review' => false,
                'normalized_preview' => ['marca_homologada' => 'Marca'],
            ]),
        ];

        foreach ($blockedRows as $blockedRow) {
            Livewire::test(ViewProductStagingRow::class, ['record' => $blockedRow->getRouteKey()])
                ->assertActionHidden('approveProduct');
        }
    }

    public function test_detail_approval_action_creates_master_logs_and_success_notification(): void
    {
        $row = $this->createReviewRow([
            'status' => 'previewed',
            'requires_review' => false,
            'review_reason' => null,
        ]);
        $suggestion = NormalizationSuggestion::factory()
            ->for($row, 'stagingRow')
            ->create(['status' => 'pending']);

        Livewire::test(ViewProductStagingRow::class, ['record' => $row->getRouteKey()])
            ->callAction('approveProduct')
            ->assertNotified('Producto aprobado y enviado a Productos Maestros.');

        $row->refresh();

        $this->assertSame('approved', $row->status);
        $this->assertSame($this->user->getKey(), $row->approved_by_id);
        $this->assertNotNull($row->approved_at);
        $this->assertNotNull($row->master_product_id);
        $this->assertDatabaseHas('master_products', [
            'id' => $row->master_product_id,
            'codigo_producto' => $row->codigo_producto_original,
            'descripcion_catalogo' => 'CAFÉ ARLISTAN 170GR',
            'approved_by_id' => $this->user->getKey(),
        ]);
        $this->assertDatabaseHas('product_change_logs', [
            'master_product_id' => $row->master_product_id,
            'changed_by_id' => $this->user->getKey(),
            'source' => 'batch_approval',
            'field_name' => 'descripcion_catalogo',
        ]);
        $this->assertSame('pending', $suggestion->fresh()->status);
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
            ->assertActionDoesNotExist('approveProduct')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableActionDoesNotExist('forceDelete')
            ->assertTableActionDoesNotExist('approveProduct')
            ->assertTableBulkActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('forceDelete')
            ->assertTableBulkActionDoesNotExist('approveProduct');

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
        $rule = NormalizationRule::factory()->create([
            'detected_value' => 'SEARCH-READ-ONLY',
        ]);
        $suggestion = NormalizationSuggestion::factory()
            ->for($row, 'stagingRow')
            ->for($rule, 'rule')
            ->create([
                'suggested_value' => 'Resultado protegido de búsqueda',
                'status' => 'pending',
            ]);
        $rowSnapshot = $row->fresh()->getAttributes();
        $masterSnapshot = $masterProduct->fresh()->getAttributes();
        $ruleSnapshot = $rule->fresh()->getAttributes();
        $suggestionSnapshot = $suggestion->fresh()->getAttributes();
        $masterCount = MasterProduct::query()->count();
        $changeLogCount = ProductChangeLog::query()->count();

        Livewire::test(ListProductStagingRows::class)
            ->searchTable('Resultado protegido de búsqueda')
            ->assertCanSeeTableRecords([$row]);
        Livewire::test(ViewProductStagingRow::class, ['record' => $row->getRouteKey()])
            ->assertSuccessful();

        $this->assertSame($rowSnapshot, $row->fresh()->getAttributes());
        $this->assertSame($masterSnapshot, $masterProduct->fresh()->getAttributes());
        $this->assertSame($ruleSnapshot, $rule->fresh()->getAttributes());
        $this->assertSame($suggestionSnapshot, $suggestion->fresh()->getAttributes());
        $this->assertSame($masterCount, MasterProduct::query()->count());
        $this->assertSame($changeLogCount, ProductChangeLog::query()->count());
        $this->assertNull($row->fresh()->approved_at);
        $this->assertSame('pending', $suggestion->fresh()->status);
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
