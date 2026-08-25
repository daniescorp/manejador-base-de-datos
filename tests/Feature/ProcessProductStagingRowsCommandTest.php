<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\MasterProduct;
use App\Models\NormalizationRule;
use App\Models\NormalizationSuggestion;
use App\Models\ProductChangeLog;
use App\Models\ProductStagingRow;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

class ProcessProductStagingRowsCommandTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        NormalizationRule::query()->update(['active' => false]);
    }

    public function test_dry_run_does_not_modify_the_database(): void
    {
        $batch = $this->createBatch();
        $row = $this->createRow($batch, ['nombre_sku_original' => 'PRODUCTO TOKEN']);
        $this->createDescriptionRule();
        $snapshot = $row->fresh()->getAttributes();
        $suggestions = NormalizationSuggestion::query()->count();

        $result = $this->runJson($batch, ['--limit' => 1, '--dry-run' => true]);

        $this->assertSame('dry_run', $result['status']);
        $this->assertSame(1, $result['would_process_rows']);
        $this->assertSame(0, $result['processed_rows']);
        $this->assertEquals($snapshot, $row->fresh()->getAttributes());
        $this->assertSame($suggestions, NormalizationSuggestion::query()->count());
    }

    public function test_only_analyze_generates_suggestions(): void
    {
        $batch = $this->createBatch();
        $row = $this->createRow($batch, ['nombre_sku_original' => 'PRODUCTO TOKEN']);
        $this->createDescriptionRule();

        $result = $this->runJson($batch, ['--only' => 'analyze']);

        $this->assertSame(1, $result['processed_rows']);
        $this->assertSame(1, $result['analyzed_rows']);
        $this->assertSame(0, $result['previewed_rows']);
        $this->assertSame(1, $row->suggestions()->count());
        $this->assertNotNull($row->fresh()->analyzed_at);
        $this->assertNull($row->fresh()->normalized_preview);
    }

    public function test_only_preview_generates_normalized_preview_when_suggestions_exist(): void
    {
        $batch = $this->createBatch();
        $row = $this->createRow($batch, ['nombre_sku_original' => 'PRODUCTO TOKEN']);
        $rule = $this->createDescriptionRule();
        $this->createSuggestion($row, $rule);

        $result = $this->runJson($batch, ['--only' => 'preview']);

        $this->assertSame(0, $result['analyzed_rows']);
        $this->assertSame(1, $result['previewed_rows']);
        $this->assertSame(0, $result['previews_before']);
        $this->assertSame(1, $result['previews_after']);
        $this->assertSame('Producto normalizado', $row->fresh()->normalized_preview['descripcion_catalogo']);
    }

    public function test_only_all_runs_analyzer_then_preview_composer(): void
    {
        $batch = $this->createBatch();
        $row = $this->createRow($batch, ['nombre_sku_original' => 'PRODUCTO TOKEN']);
        $this->createDescriptionRule();

        $result = $this->runJson($batch, ['--only' => 'all']);

        $this->assertSame(1, $result['processed_rows']);
        $this->assertSame(1, $result['analyzed_rows']);
        $this->assertSame(1, $result['previewed_rows']);
        $this->assertSame(0, $result['suggestions_before']);
        $this->assertSame(1, $result['suggestions_after']);
        $this->assertNotNull($row->fresh()->analyzed_at);
        $this->assertNotNull($row->fresh()->normalized_preview);
    }

    public function test_limit_respects_the_maximum_number_of_rows(): void
    {
        $batch = $this->createBatch();
        $rows = collect(range(1, 3))->map(
            fn (int $number): ProductStagingRow => $this->createRow($batch, [
                'codigo_producto_original' => "SKU-LIMIT-{$number}",
                'nombre_sku_original' => "PRODUCTO TOKEN {$number}",
            ]),
        );
        $this->createDescriptionRule();

        $result = $this->runJson($batch, ['--only' => 'analyze', '--limit' => 2]);

        $this->assertSame(2, $result['processed_rows']);
        $this->assertSame(2, $result['would_process_rows']);
        $this->assertSame(1, $result['skipped_rows']);
        $this->assertSame(2, ProductStagingRow::query()
            ->whereIn('id', $rows->pluck('id')->all())
            ->whereNotNull('analyzed_at')
            ->count());
    }

    public function test_command_processes_contextual_envelope_counts_and_ns_residuals(): void
    {
        $batch = $this->createBatch();
        $rawData = ['Nombre Sku' => 'TE TARAGUI S/ENS.  50s'];
        $row = $this->createRow($batch, [
            'nombre_sku_original' => 'TE TARAGUI S/ENS.  50s',
            'marca_original' => 'TARAGUI',
            'raw_data' => $rawData,
        ]);
        $this->createRule([
            'detected_value' => 'S/E',
            'replacement_value' => 'Sin ensobrar',
            'rule_type' => 'slash_abbreviation',
            'applies_to_field' => 'descripcion_catalogo',
            'priority' => 10,
        ]);
        $this->createRule([
            'detected_value' => 'CANTIDAD+S',
            'replacement_value' => 'sobres',
            'rule_type' => 'contextual_abbreviation',
            'context' => 'te_infusiones_ensobrados',
            'applies_to_field' => 'descripcion_catalogo',
            'priority' => 20,
        ]);
        $this->createRule([
            'detected_value' => 'TARAGUI',
            'replacement_value' => 'Taragüi',
            'rule_type' => 'brand_normalization',
            'applies_to_field' => 'marca_homologada',
            'is_automatic' => false,
            'requires_review' => true,
            'confidence_level' => 'contextual',
            'priority' => 30,
        ]);
        $originalSnapshot = $row->only(['nombre_sku_original', 'marca_original', 'raw_data']);

        $result = $this->runJson($batch, ['--id' => $row->getKey()]);
        $row->refresh();

        $this->assertSame(1, $result['processed_rows']);
        $this->assertSame('Té sin ensobrar 50 sobres', $row->normalized_preview['descripcion_catalogo']);
        $this->assertSame('Taragüi', $row->normalized_preview['marca_homologada']);
        $this->assertSame($originalSnapshot, $row->only(['nombre_sku_original', 'marca_original', 'raw_data']));
        $this->assertSame(3, $row->suggestions()->where('status', 'pending')->count());
        $this->assertNull($row->approved_at);
        $this->assertNull($row->approved_by_id);
    }

    public function test_id_filters_the_processing_to_one_staging_row(): void
    {
        $batch = $this->createBatch();
        $selectedRow = $this->createRow($batch, ['nombre_sku_original' => 'PRODUCTO TOKEN UNO']);
        $otherRow = $this->createRow($batch, ['nombre_sku_original' => 'PRODUCTO TOKEN DOS']);
        $this->createDescriptionRule();

        $result = $this->runJson($batch, ['--id' => $selectedRow->getKey()]);

        $this->assertSame($selectedRow->getKey(), $result['id']);
        $this->assertSame(1, $result['matched_rows']);
        $this->assertNotNull($selectedRow->fresh()->analyzed_at);
        $this->assertNull($otherRow->fresh()->analyzed_at);
    }

    public function test_batch_id_filters_the_rows(): void
    {
        $selectedBatch = $this->createBatch();
        $otherBatch = $this->createBatch();
        $selectedRow = $this->createRow($selectedBatch, ['nombre_sku_original' => 'PRODUCTO TOKEN UNO']);
        $otherRow = $this->createRow($otherBatch, ['nombre_sku_original' => 'PRODUCTO TOKEN DOS']);
        $this->createDescriptionRule();

        $result = $this->runJson($selectedBatch, ['--only' => 'analyze']);

        $this->assertSame(1, $result['matched_rows']);
        $this->assertNotNull($selectedRow->fresh()->analyzed_at);
        $this->assertNull($otherRow->fresh()->analyzed_at);
    }

    public function test_command_does_not_modify_master_products(): void
    {
        $batch = $this->createBatch();
        $this->createRow($batch, ['nombre_sku_original' => 'PRODUCTO TOKEN']);
        $this->createDescriptionRule();
        $count = MasterProduct::query()->count();
        $maximumUpdatedAt = MasterProduct::query()->max('updated_at');

        $this->runJson($batch);

        $this->assertSame($count, MasterProduct::query()->count());
        $this->assertEquals($maximumUpdatedAt, MasterProduct::query()->max('updated_at'));
    }

    public function test_command_does_not_create_product_change_logs(): void
    {
        $batch = $this->createBatch();
        $this->createRow($batch, ['nombre_sku_original' => 'PRODUCTO TOKEN']);
        $this->createDescriptionRule();
        $count = ProductChangeLog::query()->count();

        $this->runJson($batch);

        $this->assertSame($count, ProductChangeLog::query()->count());
    }

    public function test_command_does_not_approve_rows(): void
    {
        $batch = $this->createBatch();
        $row = $this->createRow($batch, ['nombre_sku_original' => 'PRODUCTO TOKEN']);
        $this->createDescriptionRule();

        $this->runJson($batch);

        $this->assertNotSame('approved', $row->fresh()->status);
        $this->assertNotSame('imported_to_master', $row->fresh()->status);
    }

    public function test_command_does_not_fill_approved_at(): void
    {
        $batch = $this->createBatch();
        $row = $this->createRow($batch);

        $this->runJson($batch);

        $this->assertNull($row->fresh()->approved_at);
    }

    public function test_command_does_not_fill_approved_by_id(): void
    {
        $batch = $this->createBatch();
        $row = $this->createRow($batch);

        $this->runJson($batch);

        $this->assertNull($row->fresh()->approved_by_id);
    }

    public function test_json_option_returns_valid_json(): void
    {
        $batch = $this->createBatch();
        $this->createRow($batch);

        $result = $this->runJson($batch, ['--dry-run' => true]);

        $this->assertSame('dry_run', $result['status']);
        $this->assertSame($batch->getKey(), $result['batch_id']);
        $this->assertIsArray($result['errors']);
    }

    public function test_command_does_not_fail_when_there_are_no_rows(): void
    {
        $batch = $this->createBatch();

        $result = $this->runJson($batch);

        $this->assertSame('empty', $result['status']);
        $this->assertSame(0, $result['processed_rows']);
        $this->assertSame([], $result['errors']);
    }

    public function test_brand_rules_generate_brand_suggestions_and_preview(): void
    {
        $batch = $this->createBatch();
        $row = $this->createRow($batch, ['marca_original' => 'ARLISTAN']);
        $this->createRule([
            'detected_value' => 'ARLISTAN',
            'replacement_value' => 'Arlistán',
            'rule_type' => 'brand_normalization',
            'applies_to_field' => 'marca_homologada',
        ]);

        $result = $this->runJson($batch);
        $row->refresh();

        $this->assertSame(1, $result['suggestions_after']);
        $this->assertSame('marca_homologada', $row->suggestions()->sole()->field_name);
        $this->assertSame('Arlistán', $row->normalized_preview['marca_homologada']);
        $this->assertSame('ARLISTAN', $row->marca_original);
    }

    public function test_command_processes_the_norton_elegido_case_without_approval_side_effects(): void
    {
        $batch = $this->createBatch();
        $rawData = ['Nombre Sku' => 'VINO NORTON ELEGIDO CHARDONNAY', 'Marca' => 'ELEGIDO'];
        $row = $this->createRow($batch, [
            'nombre_sku_original' => 'VINO NORTON ELEGIDO CHARDONNAY',
            'marca_original' => 'ELEGIDO',
            'raw_data' => $rawData,
        ]);
        $this->createRule([
            'detected_value' => 'ELEGIDO',
            'replacement_value' => 'NORTON',
            'rule_type' => 'brand_normalization',
            'applies_to_field' => 'marca_homologada',
            'context' => 'nombre_sku_contains:NORTON',
        ]);
        $masterCount = MasterProduct::query()->count();
        $changeLogCount = ProductChangeLog::query()->count();

        $result = $this->runJson($batch, ['--id' => $row->getKey()]);
        $row->refresh();

        $this->assertSame(1, $result['processed_rows']);
        $this->assertSame('NORTON', $row->normalized_preview['marca_homologada']);
        $this->assertSame('Vino elegido chardonnay', $row->normalized_preview['descripcion_catalogo']);
        $this->assertSame('VINO NORTON ELEGIDO CHARDONNAY', $row->nombre_sku_original);
        $this->assertSame('ELEGIDO', $row->marca_original);
        $this->assertEquals($rawData, $row->raw_data);
        $this->assertFalse($row->requires_review);
        $this->assertSame('previewed', $row->status);
        $this->assertNull($row->approved_at);
        $this->assertNull($row->approved_by_id);
        $this->assertNull($row->master_product_id);
        $this->assertSame($masterCount, MasterProduct::query()->count());
        $this->assertSame($changeLogCount, ProductChangeLog::query()->count());
        $this->assertSame(0, $row->suggestions()->whereIn('status', ['approved', 'applied', 'rejected'])->count());
    }

    public function test_description_rules_remain_compatible_with_descripcion_catalogo(): void
    {
        $batch = $this->createBatch();
        $row = $this->createRow($batch, ['nombre_sku_original' => 'PRODUCTO TOKEN']);
        $this->createDescriptionRule();

        $this->runJson($batch);
        $row->refresh();

        $this->assertSame('descripcion_catalogo', $row->suggestions()->sole()->field_name);
        $this->assertSame('Producto normalizado', $row->normalized_preview['descripcion_catalogo']);
        $this->assertSame('PRODUCTO TOKEN', $row->nombre_sku_original);
    }

    private function createBatch(): ImportBatch
    {
        return ImportBatch::factory()->create([
            'process_type' => 'product_excel_import',
            'source_type' => 'product_excel',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createRow(ImportBatch $batch, array $attributes = []): ProductStagingRow
    {
        return ProductStagingRow::factory()->create(array_merge([
            'import_batch_id' => $batch->getKey(),
            'nombre_sku_original' => 'PRODUCTO SIN REGLA',
            'marca_original' => 'MARCA SEGURA',
            'status' => 'pending',
            'requires_review' => false,
            'review_reason' => null,
            'normalized_preview' => null,
            'analyzed_at' => null,
            'approved_at' => null,
            'approved_by_id' => null,
        ], $attributes));
    }

    private function createDescriptionRule(): NormalizationRule
    {
        return $this->createRule([
            'detected_value' => 'TOKEN',
            'replacement_value' => 'NORMALIZADO',
            'rule_type' => 'abbreviation',
            'applies_to_field' => 'descripcion_catalogo',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createRule(array $attributes): NormalizationRule
    {
        return NormalizationRule::factory()->create(array_merge([
            'rule_name' => 'Regla temporal para comando de staging',
            'priority' => 10,
            'is_automatic' => true,
            'requires_preview' => true,
            'requires_review' => false,
            'confidence_level' => 'high',
            'active' => true,
        ], $attributes));
    }

    private function createSuggestion(
        ProductStagingRow $row,
        NormalizationRule $rule,
    ): NormalizationSuggestion {
        return NormalizationSuggestion::factory()
            ->for($row, 'stagingRow')
            ->for($rule, 'rule')
            ->create([
                'field_name' => 'descripcion_catalogo',
                'original_value' => 'PRODUCTO TOKEN',
                'suggested_value' => 'PRODUCTO NORMALIZADO',
                'status' => 'pending',
            ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function runJson(ImportBatch $batch, array $options = []): array
    {
        $exitCode = Artisan::call('app:process-product-staging-rows', array_merge([
            '--batch-id' => $batch->getKey(),
            '--only' => 'all',
            '--json' => true,
        ], $options));
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }
}
