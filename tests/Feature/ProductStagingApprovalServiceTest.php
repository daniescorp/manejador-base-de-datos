<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\MasterProduct;
use App\Models\NormalizationSuggestion;
use App\Models\ProductChangeLog;
use App\Models\ProductStagingRow;
use App\Models\User;
use App\Services\Products\ProductStagingApprovalService;
use DomainException;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ProductStagingApprovalServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql'];

    protected function setUpTraits(): array
    {
        $environmentFile = base_path('.env');

        if (! is_file($environmentFile)) {
            throw new RuntimeException('A MySQL database configuration is required to run the approval tests.');
        }

        $environment = Dotenv::parse(file_get_contents($environmentFile));
        $database = $environment['DB_DATABASE'] ?? null;

        if (blank($database) || ($database === ':memory:')) {
            throw new RuntimeException('A persistent MySQL database name is required to run the approval tests.');
        }

        config()->set([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => $database,
        ]);

        return parent::setUpTraits();
    }

    public function test_it_creates_a_master_product_logs_changes_and_preserves_staging_originals(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create();
        $row = $this->validRow(['import_batch_id' => $batch->getKey()]);
        $otherRow = $this->validRow();
        $suggestion = NormalizationSuggestion::factory()
            ->for($row, 'stagingRow')
            ->create(['status' => 'pending']);
        $originalSnapshot = $row->only([
            'raw_data',
            'nombre_sku_original',
            'marca_original',
        ]);
        $otherSnapshot = $otherRow->fresh()->getAttributes();

        $masterProduct = $this->service()->approve($row, $user);
        $row->refresh();

        $this->assertSame($row->codigo_producto_original, $masterProduct->codigo_producto);
        $this->assertSame($row->codigo_producto_original, $masterProduct->codigo_original);
        $this->assertSame($row->codigo_producto_original, $masterProduct->sku_original);
        $this->assertSame($row->codigo_producto_original, $masterProduct->sku);
        $this->assertSame($row->ean_original, $masterProduct->ean_original);
        $this->assertSame($row->ean_original, $masterProduct->barcode);
        $this->assertSame($row->nombre_sku_original, $masterProduct->nombre_original);
        $this->assertSame('Papas fritas crema y cebolla 140GR', $masterProduct->descripcion_catalogo);
        $this->assertSame('Papas fritas crema y cebolla 140GR', $masterProduct->name);
        $this->assertSame($row->marca_original, $masterProduct->marca_original);
        $this->assertSame('Taragüi', $masterProduct->marca_homologada);
        $this->assertSame('Taragüi', $masterProduct->brand);
        $this->assertSame($row->categoria_original, $masterProduct->categoria_original);
        $this->assertSame($row->categoria_original, $masterProduct->category);
        $this->assertSame($row->grupo_original, $masterProduct->grupo_original);
        $this->assertSame($row->familia_original, $masterProduct->familia_original);
        $this->assertSame($row->uxb_original, $masterProduct->uxb_original);
        $this->assertSame('aprobado_catalogo', $masterProduct->estado_homologacion);
        $this->assertFalse($masterProduct->requiere_revision);
        $this->assertSame('active', $masterProduct->status);
        $this->assertSame($row->raw_data, $masterProduct->data);
        $this->assertSame($user->getKey(), $masterProduct->approved_by_id);
        $this->assertSame($batch->getKey(), $masterProduct->last_import_batch_id);
        $this->assertNotNull($masterProduct->approved_at);

        $this->assertSame('approved', $row->status);
        $this->assertSame($masterProduct->getKey(), $row->master_product_id);
        $this->assertSame($user->getKey(), $row->approved_by_id);
        $this->assertNotNull($row->approved_at);
        $this->assertFalse($row->requires_review);
        $this->assertEquals($originalSnapshot, $row->only(array_keys($originalSnapshot)));
        $this->assertSame('pending', $suggestion->fresh()->status);
        $this->assertSame($otherSnapshot, $otherRow->fresh()->getAttributes());

        foreach (['codigo_producto', 'descripcion_catalogo', 'marca_homologada', 'nombre_original'] as $field) {
            $this->assertDatabaseHas('product_change_logs', [
                'master_product_id' => $masterProduct->getKey(),
                'changed_by_id' => $user->getKey(),
                'import_batch_id' => $batch->getKey(),
                'source' => 'batch_approval',
                'field_name' => $field,
                'old_value' => null,
                'change_reason' => "Approved from product staging row ID {$row->getKey()}",
            ]);
        }
    }

    public function test_it_updates_the_single_matching_master_and_logs_only_changed_values(): void
    {
        $user = User::factory()->create();
        $row = $this->validRow();
        $masterProduct = MasterProduct::factory()->create([
            'codigo_producto' => $row->codigo_producto_original,
            'sku' => $row->codigo_producto_original,
            'descripcion_catalogo' => 'Descripción anterior',
            'name' => 'Descripción anterior',
            'marca_original' => $row->marca_original,
            'marca_homologada' => 'Taragüi',
            'brand' => 'Taragüi',
        ]);

        $approved = $this->service()->approve($row, $user);

        $this->assertSame($masterProduct->getKey(), $approved->getKey());
        $this->assertSame('Papas fritas crema y cebolla 140GR', $approved->descripcion_catalogo);
        $this->assertDatabaseHas('product_change_logs', [
            'master_product_id' => $masterProduct->getKey(),
            'field_name' => 'descripcion_catalogo',
            'old_value' => 'Descripción anterior',
            'new_value' => 'Papas fritas crema y cebolla 140GR',
        ]);
        $this->assertDatabaseMissing('product_change_logs', [
            'master_product_id' => $masterProduct->getKey(),
            'field_name' => 'marca_original',
        ]);
        $this->assertDatabaseMissing('product_change_logs', [
            'master_product_id' => $masterProduct->getKey(),
            'field_name' => 'marca_homologada',
        ]);
    }

    public function test_it_blocks_invalid_already_approved_and_review_required_rows_without_partial_writes(): void
    {
        $user = User::factory()->create();
        $cases = [
            ['codigo_producto_original' => '  '],
            ['normalized_preview' => null],
            ['normalized_preview' => ['marca_homologada' => 'Marca']],
            ['requires_review' => true],
            ['status' => 'approved', 'approved_at' => now(), 'approved_by_id' => $user->getKey()],
        ];

        foreach ($cases as $attributes) {
            $row = $this->validRow($attributes);
            $rowSnapshot = $row->fresh()->getAttributes();
            $masterCount = MasterProduct::query()->count();
            $logCount = ProductChangeLog::query()->count();

            try {
                $this->service()->approve($row, $user);
                $this->fail('Expected the staging approval to be blocked.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }

            $this->assertSame($masterCount, MasterProduct::query()->count());
            $this->assertSame($logCount, ProductChangeLog::query()->count());
            $this->assertSame($rowSnapshot, $row->fresh()->getAttributes());
        }
    }

    public function test_it_requires_a_persisted_user_and_blocks_duplicate_master_codes(): void
    {
        $row = $this->validRow();

        foreach ([null, new User] as $invalidUser) {
            try {
                $this->service()->approve($row, $invalidUser);
                $this->fail('Expected an invalid user to be rejected.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('usuario válido', $exception->getMessage());
            }
        }

        MasterProduct::factory()->count(2)->create([
            'codigo_producto' => $row->codigo_producto_original,
        ]);
        $masterCount = MasterProduct::query()->count();
        $logCount = ProductChangeLog::query()->count();
        $user = User::factory()->create();

        try {
            $this->service()->approve($row, $user);
            $this->fail('Expected duplicate master product codes to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('más de un producto maestro', $exception->getMessage());
        }

        $this->assertSame($masterCount, MasterProduct::query()->count());
        $this->assertSame($logCount, ProductChangeLog::query()->count());
        $this->assertSame('previewed', $row->fresh()->status);
    }

    public function test_a_log_failure_rolls_back_master_logs_and_staging_approval(): void
    {
        $user = User::factory()->create();
        $row = $this->validRow();
        $rowSnapshot = $row->fresh()->getAttributes();
        $masterCount = MasterProduct::query()->count();
        $logCount = ProductChangeLog::query()->count();
        $eventName = 'eloquent.creating: '.ProductChangeLog::class;

        Event::listen($eventName, function (): never {
            throw new RuntimeException('Forced change-log failure.');
        });

        try {
            $this->service()->approve($row, $user);
            $this->fail('Expected the forced log failure to abort approval.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced change-log failure.', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame($masterCount, MasterProduct::query()->count());
        $this->assertSame($logCount, ProductChangeLog::query()->count());
        $this->assertSame($rowSnapshot, $row->fresh()->getAttributes());
    }

    private function service(): ProductStagingApprovalService
    {
        return app(ProductStagingApprovalService::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function validRow(array $attributes = []): ProductStagingRow
    {
        $token = (string) Str::uuid();

        return ProductStagingRow::factory()->create(array_merge([
            'codigo_producto_original' => "APPROVE-{$token}",
            'nombre_sku_original' => 'PAPAS FRITAS TARAGUI CREMA Y CEBOLLA 140GR',
            'uxb_original' => '12',
            'ean_original' => '7791234567890',
            'categoria_original' => 'Almacén',
            'grupo_original' => 'Snacks',
            'familia_original' => 'Papas fritas',
            'marca_original' => 'TARAGUI',
            'raw_data' => ['source' => 'approval-test', 'token' => $token],
            'normalized_preview' => [
                'descripcion_catalogo' => 'Papas fritas crema y cebolla 140GR',
                'marca_homologada' => 'Taragüi',
            ],
            'status' => 'previewed',
            'requires_review' => false,
            'approved_at' => null,
            'approved_by_id' => null,
        ], $attributes));
    }
}
