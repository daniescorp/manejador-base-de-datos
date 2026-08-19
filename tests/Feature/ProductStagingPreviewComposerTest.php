<?php

namespace Tests\Feature;

use App\Models\MasterProduct;
use App\Models\NormalizationRule;
use App\Models\NormalizationSuggestion;
use App\Models\ProductChangeLog;
use App\Models\ProductStagingRow;
use App\Services\Normalization\ProductStagingPreviewComposer;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ProductStagingPreviewComposerTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_combines_multiple_applicable_suggestions(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'ALFAJOR LIMON 50 GR',
        ]);
        $accentRule = $this->createRule([
            'detected_value' => 'LIMON',
            'replacement_value' => 'LIMÓN',
            'rule_type' => 'accent',
            'priority' => 10,
        ]);
        $measurementRule = $this->createRule([
            'detected_value' => '50 GR',
            'replacement_value' => '50GR',
            'rule_type' => 'measurement',
            'priority' => 20,
        ]);
        $accentSuggestion = $this->createSuggestion($row, $accentRule, [
            'suggested_value' => 'ALFAJOR LIMÓN 50 GR',
        ]);
        $measurementSuggestion = $this->createSuggestion($row, $measurementRule, [
            'suggested_value' => 'ALFAJOR LIMON 50GR',
        ]);

        $preview = $this->composer()->compose($row);
        $row->refresh();

        $this->assertSame('ALFAJOR LIMÓN 50GR', $preview['descripcion_catalogo']);
        $this->assertSame(
            [$accentSuggestion->getKey(), $measurementSuggestion->getKey()],
            $preview['applied_suggestion_ids'],
        );
        $this->assertSame('previewed', $row->status);
        $this->assertFalse($row->requires_review);
        $this->assertNull($row->approved_at);
        $this->assertNull($row->approved_by_id);
    }

    public function test_it_persists_the_expected_normalized_preview_structure(): void
    {
        Carbon::setTestNow('2026-08-19 14:30:00');
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'CARAMELOS LIMON',
        ]);
        $rule = $this->createRule([
            'detected_value' => 'LIMON',
            'replacement_value' => 'LIMÓN',
        ]);
        $suggestion = $this->createSuggestion($row, $rule);

        $preview = $this->composer()->compose($row);
        $storedPreview = $row->fresh()->normalized_preview;

        $this->assertSame($preview, $storedPreview);
        $this->assertSame('CARAMELOS LIMÓN', $preview['descripcion_catalogo']);
        $this->assertSame('CARAMELOS LIMON', $preview['source_text']);
        $this->assertSame('descripcion_catalogo', $preview['field']);
        $this->assertSame([$suggestion->getKey()], $preview['applied_suggestion_ids']);
        $this->assertSame([], $preview['blocked_suggestion_ids']);
        $this->assertSame([], $preview['manual_review_suggestion_ids']);
        $this->assertSame([], $preview['no_change_suggestion_ids']);
        $this->assertSame('ProductStagingPreviewComposer', $preview['generated_by']);
        $this->assertSame(now()->toISOString(), $preview['generated_at']);
    }

    public function test_composition_does_not_modify_the_linked_master_product(): void
    {
        $masterProduct = MasterProduct::factory()->create([
            'name' => 'Producto maestro intacto',
        ]);
        $row = ProductStagingRow::factory()
            ->for($masterProduct, 'masterProduct')
            ->create(['nombre_sku_original' => 'CARAMELOS LIMON']);
        $rule = $this->createRule([
            'detected_value' => 'LIMON',
            'replacement_value' => 'LIMÓN',
        ]);
        $this->createSuggestion($row, $rule);
        $masterAttributes = $masterProduct->fresh()->getAttributes();

        $this->composer()->compose($row);

        $this->assertSame($masterAttributes, $masterProduct->fresh()->getAttributes());
    }

    public function test_composition_does_not_create_product_change_logs(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'CARAMELOS LIMON',
        ]);
        $rule = $this->createRule([
            'detected_value' => 'LIMON',
            'replacement_value' => 'LIMÓN',
        ]);
        $this->createSuggestion($row, $rule);
        $changeLogCount = ProductChangeLog::query()->count();

        $this->composer()->compose($row);

        $this->assertSame($changeLogCount, ProductChangeLog::query()->count());
    }

    public function test_composition_does_not_modify_suggestions_or_use_non_pending_entries(): void
    {
        $tokens = [
            'pending' => 'TOKEN_PENDING',
            'approved' => 'TOKEN_APPROVED',
            'rejected' => 'TOKEN_REJECTED',
            'applied' => 'TOKEN_APPLIED',
            'ignored' => 'TOKEN_IGNORED',
            'requires_review' => 'TOKEN_REVIEW',
        ];
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'PRODUCTO '.implode(' ', $tokens).' TOKEN_FIELD',
        ]);
        $suggestions = [];

        foreach ($tokens as $status => $token) {
            $rule = $this->createRule([
                'detected_value' => $token,
                'replacement_value' => "NORMALIZADO_{$status}",
            ]);
            $suggestions[] = $this->createSuggestion($row, $rule, [
                'status' => $status,
                'suggestion_reason' => "Estado protegido: {$status}",
            ]);
        }

        $otherFieldRule = $this->createRule([
            'detected_value' => 'TOKEN_FIELD',
            'replacement_value' => 'NORMALIZADO_FIELD',
        ]);
        $suggestions[] = $this->createSuggestion($row, $otherFieldRule, [
            'field_name' => 'marca_homologada',
            'suggestion_reason' => 'Otro campo protegido',
        ]);
        $snapshots = collect($suggestions)->mapWithKeys(
            fn (NormalizationSuggestion $suggestion): array => [
                $suggestion->getKey() => $suggestion->getAttributes(),
            ],
        );

        $preview = $this->composer()->compose($row);

        $this->assertSame([$suggestions[0]->getKey()], $preview['applied_suggestion_ids']);
        $this->assertSame(
            str_replace('TOKEN_PENDING', 'NORMALIZADO_pending', $row->nombre_sku_original),
            $preview['descripcion_catalogo'],
        );
        $this->assertSame([], $preview['blocked_suggestion_ids']);
        $this->assertSame([], $preview['manual_review_suggestion_ids']);
        $this->assertSame([], $preview['no_change_suggestion_ids']);

        foreach ($snapshots as $suggestionId => $snapshot) {
            $this->assertEquals(
                $snapshot,
                NormalizationSuggestion::query()->findOrFail($suggestionId)->getAttributes(),
            );
        }
    }

    public function test_manual_review_suggestions_are_not_applied(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'ACEITUNA RELL. AJO 200 GR',
        ]);
        $rule = $this->createRule([
            'detected_value' => 'RELL.',
            'replacement_value' => null,
            'rule_type' => 'manual_review',
            'is_automatic' => false,
            'requires_review' => true,
            'confidence_level' => 'blocked',
        ]);
        $suggestion = $this->createSuggestion($row, $rule, [
            'suggested_value' => null,
        ]);

        $preview = $this->composer()->compose($row);
        $row->refresh();

        $this->assertSame($row->nombre_sku_original, $preview['descripcion_catalogo']);
        $this->assertSame([], $preview['applied_suggestion_ids']);
        $this->assertSame([$suggestion->getKey()], $preview['manual_review_suggestion_ids']);
        $this->assertSame([], $preview['blocked_suggestion_ids']);
        $this->assertSame('requires_review', $row->status);
        $this->assertTrue($row->requires_review);
    }

    public function test_no_change_suggestions_are_recorded_without_changing_text(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'VINO CAB/MALBEC',
        ]);
        $rule = $this->createRule([
            'detected_value' => 'CAB/MALBEC',
            'replacement_value' => null,
            'rule_type' => 'no_change',
            'is_automatic' => false,
            'requires_preview' => false,
            'confidence_level' => 'confirmed_no_change',
        ]);
        $suggestion = $this->createSuggestion($row, $rule, [
            'suggested_value' => null,
        ]);

        $preview = $this->composer()->compose($row);
        $row->refresh();

        $this->assertSame($row->nombre_sku_original, $preview['descripcion_catalogo']);
        $this->assertSame([], $preview['applied_suggestion_ids']);
        $this->assertSame([$suggestion->getKey()], $preview['no_change_suggestion_ids']);
        $this->assertSame('suggested', $row->status);
        $this->assertFalse($row->requires_review);
    }

    public function test_rules_requiring_review_are_blocked(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'PRODUCTO C/ ALGO',
        ]);
        $rule = $this->createRule([
            'detected_value' => 'C/',
            'replacement_value' => 'con',
            'is_automatic' => true,
            'requires_review' => true,
            'confidence_level' => 'contextual',
        ]);
        $suggestion = $this->createSuggestion($row, $rule);

        $preview = $this->composer()->compose($row);
        $row->refresh();

        $this->assertSame($row->nombre_sku_original, $preview['descripcion_catalogo']);
        $this->assertSame([$suggestion->getKey()], $preview['blocked_suggestion_ids']);
        $this->assertSame([], $preview['applied_suggestion_ids']);
        $this->assertSame('requires_review', $row->status);
        $this->assertTrue($row->requires_review);
    }

    public function test_it_applies_a_measurement_while_preserving_a_wine_no_change_rule(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'VINO CAB/MALBEC 750 CC',
        ]);
        $wineRule = $this->createRule([
            'detected_value' => 'CAB/MALBEC',
            'replacement_value' => null,
            'rule_type' => 'no_change',
            'is_automatic' => false,
            'requires_preview' => false,
            'confidence_level' => 'confirmed_no_change',
            'priority' => 10,
        ]);
        $measurementRule = $this->createRule([
            'detected_value' => '750 CC',
            'replacement_value' => '750CC',
            'rule_type' => 'measurement',
            'priority' => 20,
        ]);
        $wineSuggestion = $this->createSuggestion($row, $wineRule, [
            'suggested_value' => null,
        ]);
        $measurementSuggestion = $this->createSuggestion($row, $measurementRule);

        $preview = $this->composer()->compose($row);

        $this->assertSame('VINO CAB/MALBEC 750CC', $preview['descripcion_catalogo']);
        $this->assertSame([$measurementSuggestion->getKey()], $preview['applied_suggestion_ids']);
        $this->assertSame([$wineSuggestion->getKey()], $preview['no_change_suggestion_ids']);
        $this->assertSame('previewed', $row->fresh()->status);
    }

    public function test_a_row_without_applicable_or_sensitive_suggestions_gets_a_consistent_preview(): void
    {
        $analyzedAt = Carbon::parse('2026-08-19 12:00:00');
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'PRODUCTO SIN CAMBIOS',
            'normalized_preview' => [
                'descripcion_catalogo' => 'PREVIEW ANTERIOR',
                'applied_suggestion_ids' => [999],
            ],
            'status' => 'suggested',
            'analyzed_at' => $analyzedAt,
        ]);

        $preview = $this->composer()->compose($row);
        $row->refresh();

        $this->assertSame('PRODUCTO SIN CAMBIOS', $preview['source_text']);
        $this->assertSame('PRODUCTO SIN CAMBIOS', $preview['descripcion_catalogo']);
        $this->assertSame([], $preview['applied_suggestion_ids']);
        $this->assertSame([], $preview['blocked_suggestion_ids']);
        $this->assertSame([], $preview['manual_review_suggestion_ids']);
        $this->assertSame([], $preview['no_change_suggestion_ids']);
        $this->assertSame('analyzed', $row->status);
        $this->assertTrue($row->analyzed_at->equalTo($analyzedAt));
    }

    public function test_an_empty_source_creates_a_safe_review_preview(): void
    {
        $analyzedAt = Carbon::parse('2026-08-19 12:00:00');
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => null,
            'status' => 'suggested',
            'requires_review' => false,
            'review_reason' => 'UXB pendiente',
            'analyzed_at' => $analyzedAt,
            'approved_at' => null,
            'approved_by_id' => null,
        ]);
        $rule = $this->createRule([
            'detected_value' => 'TOKEN',
            'replacement_value' => 'NORMALIZADO',
        ]);
        $suggestion = $this->createSuggestion($row, $rule);

        $preview = $this->composer()->compose($row);
        $row->refresh();

        $this->assertSame('', $preview['source_text']);
        $this->assertSame('', $preview['descripcion_catalogo']);
        $this->assertSame([], $preview['applied_suggestion_ids']);
        $this->assertSame([$suggestion->getKey()], $preview['blocked_suggestion_ids']);
        $this->assertSame('requires_review', $row->status);
        $this->assertTrue($row->requires_review);
        $this->assertStringContainsString('UXB pendiente', $row->review_reason);
        $this->assertStringContainsString('Nombre Sku original vacío', $row->review_reason);
        $this->assertTrue($row->analyzed_at->equalTo($analyzedAt));
        $this->assertNull($row->approved_at);
        $this->assertNull($row->approved_by_id);
    }

    public function test_composition_is_idempotent(): void
    {
        Carbon::setTestNow('2026-08-19 13:00:00');
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'CARAMELOS LIMON',
        ]);
        $rule = $this->createRule([
            'detected_value' => 'LIMON',
            'replacement_value' => 'LIMÓN',
        ]);
        $this->createSuggestion($row, $rule);

        $firstPreview = $this->composer()->compose($row);
        $firstUpdatedAt = $row->fresh()->getRawOriginal('updated_at');
        $suggestionCount = $row->suggestions()->count();
        $changeLogCount = ProductChangeLog::query()->count();
        Carbon::setTestNow('2026-08-20 13:00:00');

        $secondPreview = $this->composer()->compose($row);
        $row->refresh();

        $this->assertSame($firstPreview, $secondPreview);
        $this->assertSame($firstUpdatedAt, $row->getRawOriginal('updated_at'));
        $this->assertSame($suggestionCount, $row->suggestions()->count());
        $this->assertSame($changeLogCount, ProductChangeLog::query()->count());
    }

    public function test_priority_precedes_suggestion_id_when_applying_rules(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'AB',
        ]);
        $laterRule = $this->createRule([
            'detected_value' => 'B',
            'replacement_value' => 'C',
            'priority' => 20,
        ]);
        $earlierRule = $this->createRule([
            'detected_value' => 'A',
            'replacement_value' => 'AB',
            'priority' => 10,
        ]);
        $lowerIdSuggestion = $this->createSuggestion($row, $laterRule);
        $higherIdSuggestion = $this->createSuggestion($row, $earlierRule);

        $preview = $this->composer()->compose($row);

        $this->assertSame('ACC', $preview['descripcion_catalogo']);
        $this->assertSame(
            [$higherIdSuggestion->getKey(), $lowerIdSuggestion->getKey()],
            $preview['applied_suggestion_ids'],
        );
    }

    public function test_suggestion_id_breaks_ties_between_equal_priorities(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'AB',
        ]);
        $firstRule = $this->createRule([
            'detected_value' => 'A',
            'replacement_value' => 'AB',
            'priority' => 10,
        ]);
        $secondRule = $this->createRule([
            'detected_value' => 'B',
            'replacement_value' => 'C',
            'priority' => 10,
        ]);
        $firstSuggestion = $this->createSuggestion($row, $firstRule);
        $secondSuggestion = $this->createSuggestion($row, $secondRule);

        $preview = $this->composer()->compose($row);

        $this->assertSame('ACC', $preview['descripcion_catalogo']);
        $this->assertSame(
            [$firstSuggestion->getKey(), $secondSuggestion->getKey()],
            $preview['applied_suggestion_ids'],
        );
    }

    public function test_manual_and_no_change_types_remain_sensitive_by_type_alone(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'PRODUCTO MANUAL CONSERVAR',
        ]);
        $manualRule = $this->createRule([
            'detected_value' => 'MANUAL',
            'replacement_value' => 'AUTOMATICO',
            'rule_type' => 'manual_review',
            'is_automatic' => true,
            'requires_review' => false,
        ]);
        $noChangeRule = $this->createRule([
            'detected_value' => 'CONSERVAR',
            'replacement_value' => 'CAMBIADO',
            'rule_type' => 'no_change',
            'is_automatic' => true,
            'requires_review' => false,
        ]);
        $manualSuggestion = $this->createSuggestion($row, $manualRule);
        $noChangeSuggestion = $this->createSuggestion($row, $noChangeRule);

        $preview = $this->composer()->compose($row);

        $this->assertSame($row->nombre_sku_original, $preview['descripcion_catalogo']);
        $this->assertSame([$manualSuggestion->getKey()], $preview['manual_review_suggestion_ids']);
        $this->assertSame([$noChangeSuggestion->getKey()], $preview['no_change_suggestion_ids']);
        $this->assertSame([], $preview['applied_suggestion_ids']);
    }

    public function test_an_ordinary_rule_without_a_replacement_is_blocked(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'PRODUCTO TOKEN',
        ]);
        $rule = $this->createRule([
            'detected_value' => 'TOKEN',
            'replacement_value' => null,
            'rule_type' => 'abbreviation',
            'is_automatic' => true,
            'requires_review' => false,
        ]);
        $suggestion = $this->createSuggestion($row, $rule, [
            'suggested_value' => null,
        ]);

        $preview = $this->composer()->compose($row);

        $this->assertSame($row->nombre_sku_original, $preview['descripcion_catalogo']);
        $this->assertSame([$suggestion->getKey()], $preview['blocked_suggestion_ids']);
        $this->assertSame([], $preview['applied_suggestion_ids']);
    }

    public function test_non_automatic_rules_are_blocked_even_without_a_review_flag(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'PRODUCTO C/ ALGO',
        ]);
        $rule = $this->createRule([
            'detected_value' => 'C/',
            'replacement_value' => 'con',
            'is_automatic' => false,
            'requires_review' => false,
        ]);
        $suggestion = $this->createSuggestion($row, $rule);

        $preview = $this->composer()->compose($row);

        $this->assertSame($row->nombre_sku_original, $preview['descripcion_catalogo']);
        $this->assertSame([$suggestion->getKey()], $preview['blocked_suggestion_ids']);
        $this->assertSame('requires_review', $row->fresh()->status);
    }

    public function test_missing_and_inactive_rules_are_blocked_safely(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'PRODUCTO TOKEN',
        ]);
        $missingRuleSuggestion = $this->createSuggestion($row, null);
        $inactiveRule = $this->createRule([
            'detected_value' => 'TOKEN',
            'replacement_value' => 'NORMALIZADO',
            'active' => false,
        ]);
        $inactiveRuleSuggestion = $this->createSuggestion($row, $inactiveRule);
        $wrongTargetRule = $this->createRule([
            'detected_value' => 'TOKEN',
            'replacement_value' => 'OTRO CAMPO',
            'applies_to_field' => 'marca_homologada',
        ]);
        $wrongTargetSuggestion = $this->createSuggestion($row, $wrongTargetRule);

        $preview = $this->composer()->compose($row);

        $this->assertSame('PRODUCTO TOKEN', $preview['descripcion_catalogo']);
        $this->assertSame(
            [
                $inactiveRuleSuggestion->getKey(),
                $wrongTargetSuggestion->getKey(),
                $missingRuleSuggestion->getKey(),
            ],
            $preview['blocked_suggestion_ids'],
        );
        $this->assertSame([], $preview['applied_suggestion_ids']);
        $this->assertSame('requires_review', $row->fresh()->status);
    }

    public function test_terminal_rows_cannot_be_recomposed(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'PRODUCTO LIMON',
            'normalized_preview' => ['descripcion_catalogo' => 'PREVIEW APROBADO'],
            'status' => 'approved',
        ]);
        $rule = $this->createRule([
            'detected_value' => 'LIMON',
            'replacement_value' => 'LIMÓN',
        ]);
        $this->createSuggestion($row, $rule);
        $rowSnapshot = $row->fresh()->getAttributes();

        try {
            $this->composer()->compose($row);
            $this->fail('Se esperaba impedir la recomposición de una fila terminal.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'No se puede recomponer una fila de staging aprobada o terminal.',
                $exception->getMessage(),
            );
        }

        $this->assertEquals($rowSnapshot, $row->fresh()->getAttributes());
    }

    private function composer(): ProductStagingPreviewComposer
    {
        return app(ProductStagingPreviewComposer::class);
    }

    /**
     * @param  array<string, bool|int|string|null>  $attributes
     */
    private function createRule(array $attributes): NormalizationRule
    {
        return NormalizationRule::factory()->create(array_merge([
            'rule_name' => 'Regla para prueba de composición',
            'detected_value' => 'TOKEN',
            'replacement_value' => 'NORMALIZADO',
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

    /**
     * @param  array<string, int|string|null>  $attributes
     */
    private function createSuggestion(
        ProductStagingRow $row,
        ?NormalizationRule $rule,
        array $attributes = [],
    ): NormalizationSuggestion {
        return NormalizationSuggestion::factory()->create(array_merge([
            'product_staging_row_id' => $row->getKey(),
            'normalization_rule_id' => $rule?->getKey(),
            'field_name' => 'descripcion_catalogo',
            'original_value' => $row->nombre_sku_original,
            'suggested_value' => $row->nombre_sku_original,
            'status' => 'pending',
        ], $attributes));
    }
}
