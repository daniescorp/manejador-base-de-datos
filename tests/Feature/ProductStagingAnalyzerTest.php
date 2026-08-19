<?php

namespace Tests\Feature;

use App\Models\MasterProduct;
use App\Models\NormalizationRule;
use App\Models\NormalizationSuggestion;
use App\Models\ProductChangeLog;
use App\Models\ProductStagingRow;
use App\Services\Normalization\ProductStagingAnalyzer;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class ProductStagingAnalyzerTest extends TestCase
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

    public function test_it_creates_a_suggestion_for_a_simple_rule(): void
    {
        $rule = $this->createRule([
            'detected_value' => 'LIMON',
            'replacement_value' => 'LIMÓN',
            'rule_type' => 'accent',
            'confidence_level' => 'high',
        ]);
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'ALFAJOR LIMON 50 GR',
        ]);

        $this->analyzer()->analyze($row);

        $suggestion = $this->suggestionFor($row, $rule);

        $this->assertSame('descripcion_catalogo', $suggestion->field_name);
        $this->assertSame('ALFAJOR LIMON 50 GR', $suggestion->original_value);
        $this->assertSame('ALFAJOR LIMÓN 50 GR', $suggestion->suggested_value);
        $this->assertSame('high', $suggestion->confidence_level);
        $this->assertSame('pending', $suggestion->status);
        $this->assertNull($suggestion->reviewed_by_id);
        $this->assertNull($suggestion->reviewed_at);
        $this->assertNull($suggestion->applied_at);
    }

    public function test_it_does_not_duplicate_suggestions_when_reanalyzed(): void
    {
        $rule = $this->createRule([
            'detected_value' => 'LIMON',
            'replacement_value' => 'LIMÓN',
        ]);
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'ALFAJOR LIMON 50 GR',
        ]);

        $this->analyzer()->analyze($row);
        $suggestionId = $this->suggestionFor($row, $rule)->getKey();

        $this->analyzer()->analyze($row);

        $query = $this->suggestionQuery($row, $rule);

        $this->assertSame(1, $query->count());
        $this->assertSame($suggestionId, $query->firstOrFail()->getKey());
    }

    public function test_it_creates_a_compact_measurement_suggestion(): void
    {
        $rule = $this->createRule([
            'detected_value' => '750 CC',
            'replacement_value' => '750CC',
            'rule_type' => 'measurement',
        ]);
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'VINO TINTO 750 CC',
        ]);

        $this->analyzer()->analyze($row);

        $suggestion = $this->suggestionFor($row, $rule);

        $this->assertSame('VINO TINTO 750CC', $suggestion->suggested_value);
    }

    public function test_rell_creates_a_blocked_manual_review_suggestion(): void
    {
        $rule = $this->createRule([
            'detected_value' => 'RELL.',
            'replacement_value' => null,
            'rule_type' => 'manual_review',
            'is_automatic' => false,
            'requires_review' => true,
            'confidence_level' => 'blocked',
        ]);
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'ACEITUNA TIYUCA RELL. AJO 200 GR',
        ]);

        $this->analyzer()->analyze($row);

        $suggestion = $this->suggestionFor($row, $rule);

        $this->assertSame('manual_review', $rule->rule_type);
        $this->assertFalse($rule->is_automatic);
        $this->assertTrue($rule->requires_review);
        $this->assertNull($suggestion->suggested_value);
        $this->assertSame('pending', $suggestion->status);
        $this->assertSame('blocked', $suggestion->confidence_level);
        $this->assertSame(
            'Regla sensible: requiere revisión manual. No aplicar automáticamente.',
            $suggestion->suggestion_reason,
        );
    }

    public function test_a_review_rule_marks_the_row_without_losing_a_previous_reason(): void
    {
        $rule = $this->createRule([
            'detected_value' => 'C/',
            'replacement_value' => 'con',
            'rule_type' => 'slash_abbreviation',
            'is_automatic' => false,
            'requires_review' => true,
            'confidence_level' => 'contextual',
        ]);
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'GALLETAS C/ CHIPS',
            'requires_review' => true,
            'review_reason' => 'UXB pendiente',
        ]);

        $this->analyzer()->analyze($row);
        $row->refresh();

        $this->assertTrue($row->requires_review);
        $this->assertStringContainsString('UXB pendiente', $row->review_reason);
        $this->assertStringContainsString($rule->detected_value, $row->review_reason);
    }

    public function test_a_row_without_applicable_rules_is_marked_as_analyzed(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'PRODUCTO QZXWV 999',
        ]);

        $this->analyzer()->analyze($row);
        $row->refresh();

        $this->assertSame('analyzed', $row->status);
        $this->assertNotNull($row->analyzed_at);
        $this->assertSame(0, $row->suggestions()->count());
    }

    public function test_a_row_with_an_applicable_rule_is_marked_as_suggested(): void
    {
        $this->createRule([
            'detected_value' => 'LIMON',
            'replacement_value' => 'LIMÓN',
        ]);
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'CARAMELOS LIMON',
        ]);

        $this->analyzer()->analyze($row);
        $row->refresh();

        $this->assertSame('suggested', $row->status);
        $this->assertNotNull($row->analyzed_at);
        $this->assertNull($row->approved_at);
        $this->assertNull($row->approved_by_id);
    }

    public function test_analysis_does_not_modify_the_linked_master_product(): void
    {
        $rule = $this->createRule([
            'detected_value' => 'LIMON',
            'replacement_value' => 'LIMÓN',
        ]);
        $masterProduct = MasterProduct::factory()->create([
            'name' => 'Nombre maestro sin cambios',
        ]);
        $row = ProductStagingRow::factory()
            ->for($masterProduct, 'masterProduct')
            ->create(['nombre_sku_original' => 'CARAMELOS LIMON']);
        $masterAttributes = $masterProduct->fresh()->getAttributes();

        $this->analyzer()->analyze($row);

        $this->assertSame($masterAttributes, $masterProduct->fresh()->getAttributes());
        $this->assertNull($this->suggestionFor($row, $rule)->master_product_id);
    }

    public function test_analysis_does_not_create_product_change_logs(): void
    {
        $this->createRule([
            'detected_value' => 'LIMON',
            'replacement_value' => 'LIMÓN',
        ]);
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'CARAMELOS LIMON',
        ]);
        $changeLogCount = ProductChangeLog::query()->count();

        $this->analyzer()->analyze($row);

        $this->assertSame($changeLogCount, ProductChangeLog::query()->count());
    }

    public function test_analysis_preserves_terminal_suggestions(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'PRODUCTO UNO DOS TRES',
        ]);
        $snapshots = [];

        foreach (['approved', 'rejected', 'applied'] as $index => $status) {
            $detectedValue = ['UNO', 'DOS', 'TRES'][$index];
            $rule = $this->createRule([
                'detected_value' => $detectedValue,
                'replacement_value' => "REEMPLAZO {$index}",
            ]);
            $suggestion = NormalizationSuggestion::factory()
                ->for($row, 'stagingRow')
                ->for($rule, 'rule')
                ->create([
                    'field_name' => 'descripcion_catalogo',
                    'original_value' => "Original protegido {$index}",
                    'suggested_value' => "Sugerencia protegida {$index}",
                    'suggestion_reason' => "Decisión protegida {$index}",
                    'status' => $status,
                ]);

            $snapshots[$suggestion->getKey()] = $suggestion->getAttributes();
        }

        $this->analyzer()->analyze($row);

        foreach ($snapshots as $suggestionId => $snapshot) {
            $this->assertEquals(
                $snapshot,
                NormalizationSuggestion::query()->findOrFail($suggestionId)->getAttributes(),
            );
        }
    }

    public function test_analysis_preserves_manual_suggestions_without_a_rule(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'PRODUCTO SIN REGLAS',
        ]);
        $manualSuggestion = NormalizationSuggestion::factory()
            ->for($row, 'stagingRow')
            ->create([
                'normalization_rule_id' => null,
                'field_name' => 'descripcion_catalogo',
                'original_value' => 'Valor manual original',
                'suggested_value' => 'Valor manual sugerido',
                'suggestion_reason' => 'Sugerencia creada manualmente',
                'status' => 'pending',
            ]);
        $snapshot = $manualSuggestion->getAttributes();

        $this->analyzer()->analyze($row);

        $this->assertEquals($snapshot, $manualSuggestion->fresh()->getAttributes());
    }

    public function test_analysis_refreshes_pending_and_ignored_suggestions_without_changing_status(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'PRODUCTO LIMON',
        ]);

        foreach (['pending', 'ignored'] as $status) {
            $rule = $this->createRule([
                'detected_value' => 'LIMON',
                'replacement_value' => 'LIMÓN',
                'confidence_level' => 'high',
            ]);
            $suggestion = NormalizationSuggestion::factory()
                ->for($row, 'stagingRow')
                ->for($rule, 'rule')
                ->create([
                    'field_name' => 'descripcion_catalogo',
                    'original_value' => 'Valor anterior',
                    'suggested_value' => 'Sugerencia anterior',
                    'suggestion_reason' => 'Razón anterior',
                    'confidence_level' => 'low',
                    'status' => $status,
                ]);

            $this->analyzer()->analyze($row);
            $suggestion->refresh();

            $this->assertSame($status, $suggestion->status);
            $this->assertSame('PRODUCTO LIMON', $suggestion->original_value);
            $this->assertSame('PRODUCTO LIMÓN', $suggestion->suggested_value);
            $this->assertSame('high', $suggestion->confidence_level);
        }
    }

    public function test_c_s_and_p_slash_rules_remain_contextual_suggestions(): void
    {
        $cases = [
            ['C/', 'con', 'GALLETAS C/CHIPS'],
            ['S/', 'sin', 'YOGUR S/AZUCAR'],
            ['P/', 'para', 'BOLSAS P/FREEZER'],
        ];

        foreach ($cases as [$detectedValue, $replacementValue, $source]) {
            $rule = $this->createRule([
                'detected_value' => $detectedValue,
                'replacement_value' => $replacementValue,
                'rule_type' => 'slash_abbreviation',
                'is_automatic' => false,
                'requires_review' => true,
                'confidence_level' => 'contextual',
            ]);
            $row = ProductStagingRow::factory()->create([
                'nombre_sku_original' => $source,
            ]);

            $this->analyzer()->analyze($row);

            $suggestion = $this->suggestionFor($row, $rule);

            $this->assertSame('pending', $suggestion->status);
            $this->assertSame('contextual', $suggestion->confidence_level);
            $this->assertNotNull($suggestion->suggested_value);
            $this->assertTrue($row->fresh()->requires_review);
        }
    }

    public function test_a_wine_no_change_rule_does_not_propose_a_replacement(): void
    {
        $rule = $this->createRule([
            'detected_value' => 'CAB/MALBEC',
            'replacement_value' => null,
            'rule_type' => 'no_change',
            'is_automatic' => false,
            'requires_preview' => false,
            'requires_review' => false,
            'confidence_level' => 'confirmed_no_change',
        ]);
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'VINO TINTO CAB/MALBEC',
        ]);

        $this->analyzer()->analyze($row);
        $suggestion = $this->suggestionFor($row, $rule);
        $row->refresh();

        $this->assertNull($suggestion->suggested_value);
        $this->assertSame('pending', $suggestion->status);
        $this->assertSame('confirmed_no_change', $suggestion->confidence_level);
        $this->assertStringContainsString('mantener', $suggestion->suggestion_reason);
        $this->assertSame('suggested', $row->status);
        $this->assertFalse($row->requires_review);
    }

    public function test_an_empty_source_is_marked_for_review(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => '   ',
            'status' => 'pending',
            'requires_review' => false,
            'review_reason' => null,
        ]);

        $this->analyzer()->analyze($row);
        $row->refresh();

        $this->assertSame('requires_review', $row->status);
        $this->assertTrue($row->requires_review);
        $this->assertSame('Nombre Sku original vacío', $row->review_reason);
        $this->assertNotNull($row->analyzed_at);
        $this->assertSame(0, $row->suggestions()->count());
    }

    public function test_inactive_rules_are_not_applied(): void
    {
        $this->createRule([
            'detected_value' => 'LIMON',
            'replacement_value' => 'LIMÓN',
            'active' => false,
        ]);
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'CARAMELOS LIMON',
        ]);

        $this->analyzer()->analyze($row);
        $row->refresh();

        $this->assertSame('analyzed', $row->status);
        $this->assertSame(0, $row->suggestions()->count());
    }

    public function test_rules_for_other_target_fields_are_not_applied(): void
    {
        $this->createRule([
            'detected_value' => 'LIMON',
            'replacement_value' => 'LIMÓN',
            'applies_to_field' => 'marca_homologada',
        ]);
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'CARAMELOS LIMON',
        ]);

        $this->analyzer()->analyze($row);
        $row->refresh();

        $this->assertSame('analyzed', $row->status);
        $this->assertSame(0, $row->suggestions()->count());
    }

    public function test_each_suggestion_is_built_from_the_original_source(): void
    {
        $accentRule = $this->createRule([
            'detected_value' => 'LIMON',
            'replacement_value' => 'LIMÓN',
        ]);
        $measurementRule = $this->createRule([
            'detected_value' => '50 GR',
            'replacement_value' => '50GR',
            'rule_type' => 'measurement',
        ]);
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'ALFAJOR LIMON 50 GR',
        ]);

        $this->analyzer()->analyze($row);

        $this->assertSame(
            'ALFAJOR LIMÓN 50 GR',
            $this->suggestionFor($row, $accentRule)->suggested_value,
        );
        $this->assertSame(
            'ALFAJOR LIMON 50GR',
            $this->suggestionFor($row, $measurementRule)->suggested_value,
        );
    }

    public function test_matching_is_case_insensitive_and_treats_periods_as_literals(): void
    {
        $literalRule = $this->createRule([
            'detected_value' => 'RELL.',
            'replacement_value' => null,
            'rule_type' => 'manual_review',
            'requires_review' => true,
        ]);
        $matchingRow = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'ACEITUNA rell. AJO',
        ]);
        $nonMatchingRow = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'PRODUCTO RELLX',
        ]);

        $this->analyzer()->analyze($matchingRow);
        $this->analyzer()->analyze($nonMatchingRow);

        $this->assertSame(1, $this->suggestionQuery($matchingRow, $literalRule)->count());
        $this->assertSame(0, $this->suggestionQuery($nonMatchingRow, $literalRule)->count());
    }

    private function analyzer(): ProductStagingAnalyzer
    {
        return app(ProductStagingAnalyzer::class);
    }

    /**
     * @param  array<string, bool|int|string|null>  $attributes
     */
    private function createRule(array $attributes): NormalizationRule
    {
        return NormalizationRule::factory()->create(array_merge([
            'rule_name' => 'Regla para prueba de análisis',
            'rule_type' => 'abbreviation',
            'applies_to_field' => 'descripcion_catalogo',
            'context' => null,
            'priority' => 100,
            'is_automatic' => true,
            'requires_preview' => true,
            'requires_review' => false,
            'confidence_level' => 'high',
            'active' => true,
        ], $attributes));
    }

    private function suggestionFor(
        ProductStagingRow $row,
        NormalizationRule $rule,
    ): NormalizationSuggestion {
        return $this->suggestionQuery($row, $rule)->sole();
    }

    private function suggestionQuery(ProductStagingRow $row, NormalizationRule $rule)
    {
        return NormalizationSuggestion::query()->where([
            'product_staging_row_id' => $row->getKey(),
            'normalization_rule_id' => $rule->getKey(),
            'field_name' => 'descripcion_catalogo',
        ]);
    }
}
