<?php

namespace Tests\Feature;

use App\Models\MasterProduct;
use App\Models\NormalizationRule;
use App\Models\NormalizationSuggestion;
use App\Models\ProductChangeLog;
use App\Models\ProductStagingRow;
use App\Services\Normalization\ProductStagingAnalyzer;
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
        $this->assertSame($row->marca_original, $preview['source_brand']);
        $this->assertSame($row->marca_original, $preview['marca_homologada']);
        $this->assertEquals(
            [
                'source' => 'CARAMELOS LIMON',
                'preview' => 'CARAMELOS LIMÓN',
                'applied_suggestion_ids' => [$suggestion->getKey()],
                'pending_review_suggestion_ids' => [],
                'blocked_suggestion_ids' => [],
                'manual_review_suggestion_ids' => [],
                'no_change_suggestion_ids' => [],
            ],
            $preview['fields']['descripcion_catalogo'],
        );
        $this->assertEquals(
            [
                'source' => $row->marca_original,
                'preview' => $row->marca_original,
                'applied_suggestion_ids' => [],
                'pending_review_suggestion_ids' => [],
                'blocked_suggestion_ids' => [],
            ],
            $preview['fields']['marca_homologada'],
        );
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
            'field_name' => 'titulo_shopify',
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

    public function test_it_includes_an_automatic_brand_normalization_in_the_preview(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'CAFÉ MOLIDO',
            'marca_original' => 'ARLISTAN',
        ]);
        $rule = $this->createRule([
            'detected_value' => 'ARLISTAN',
            'replacement_value' => 'Arlistán',
            'rule_type' => 'brand_normalization',
            'applies_to_field' => 'marca_homologada',
        ]);
        $suggestion = $this->createSuggestion($row, $rule, [
            'field_name' => 'marca_homologada',
            'original_value' => 'ARLISTAN',
            'suggested_value' => 'Arlistán',
        ]);
        $suggestionSnapshot = $suggestion->getAttributes();

        $preview = $this->composer()->compose($row);
        $row->refresh();

        $this->assertSame('ARLISTAN', $preview['source_brand']);
        $this->assertSame('Arlistán', $preview['marca_homologada']);
        $this->assertSame(
            [$suggestion->getKey()],
            $preview['fields']['marca_homologada']['applied_suggestion_ids'],
        );
        $this->assertSame(
            [],
            $preview['fields']['marca_homologada']['pending_review_suggestion_ids'],
        );
        $this->assertSame([], $preview['fields']['marca_homologada']['blocked_suggestion_ids']);
        $this->assertSame([], $preview['applied_suggestion_ids']);
        $this->assertSame('ARLISTAN', $row->marca_original);
        $this->assertSame('previewed', $row->status);
        $this->assertFalse($row->requires_review);
        $this->assertNull($row->approved_at);
        $this->assertNull($row->approved_by_id);
        $this->assertEquals($suggestionSnapshot, $suggestion->fresh()->getAttributes());
    }

    public function test_a_sensitive_brand_is_previewed_as_pending_review_not_applied(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'CAFÉ MOLIDO',
            'marca_original' => 'ARLISTAN',
        ]);
        $rule = $this->createRule([
            'detected_value' => 'ARLISTAN',
            'replacement_value' => 'Arlistán',
            'rule_type' => 'brand_normalization',
            'applies_to_field' => 'marca_homologada',
            'is_automatic' => false,
            'requires_review' => true,
            'confidence_level' => 'contextual',
        ]);
        $suggestion = $this->createSuggestion($row, $rule, [
            'field_name' => 'marca_homologada',
            'original_value' => 'ARLISTAN',
            'suggested_value' => 'Arlistán',
        ]);

        $preview = $this->composer()->compose($row);
        $row->refresh();

        $brandPreview = $preview['fields']['marca_homologada'];

        $this->assertSame('Arlistán', $preview['marca_homologada']);
        $this->assertSame([], $brandPreview['applied_suggestion_ids']);
        $this->assertSame(
            [$suggestion->getKey()],
            $brandPreview['pending_review_suggestion_ids'],
        );
        $this->assertSame([], $brandPreview['blocked_suggestion_ids']);
        $this->assertSame('previewed', $row->status);
        $this->assertTrue($row->requires_review);
        $this->assertStringContainsString(
            'sugerencias de marca pendientes de revisión',
            $row->review_reason,
        );
        $this->assertSame('pending', $suggestion->fresh()->status);
    }

    public function test_it_composes_description_and_brand_without_touching_master_or_logs(): void
    {
        $masterProduct = MasterProduct::factory()->create([
            'name' => 'Producto maestro protegido',
            'marca_original' => 'Marca maestra original',
            'marca_homologada' => 'Marca maestra homologada',
        ]);
        $row = ProductStagingRow::factory()
            ->for($masterProduct, 'masterProduct')
            ->create([
                'nombre_sku_original' => 'CARAMELOS LIMON 50 GR',
                'marca_original' => 'MANON',
            ]);
        $descriptionRule = $this->createRule([
            'detected_value' => 'LIMON',
            'replacement_value' => 'LIMÓN',
            'rule_type' => 'accent',
            'priority' => 10,
        ]);
        $brandRule = $this->createRule([
            'detected_value' => 'MANON',
            'replacement_value' => 'Manón',
            'rule_type' => 'brand_normalization',
            'applies_to_field' => 'marca_homologada',
            'priority' => 20,
        ]);
        $descriptionSuggestion = $this->createSuggestion($row, $descriptionRule, [
            'suggested_value' => 'CARAMELOS LIMÓN 50 GR',
        ]);
        $brandSuggestion = $this->createSuggestion($row, $brandRule, [
            'field_name' => 'marca_homologada',
            'original_value' => 'MANON',
            'suggested_value' => 'Manón',
        ]);
        $masterSnapshot = $masterProduct->fresh()->getAttributes();
        $masterCount = MasterProduct::query()->count();
        $changeLogCount = ProductChangeLog::query()->count();

        $preview = $this->composer()->compose($row);

        $this->assertSame('CARAMELOS LIMÓN 50 GR', $preview['descripcion_catalogo']);
        $this->assertSame('Manón', $preview['marca_homologada']);
        $this->assertSame(
            [$descriptionSuggestion->getKey()],
            $preview['applied_suggestion_ids'],
        );
        $this->assertSame(
            [$brandSuggestion->getKey()],
            $preview['fields']['marca_homologada']['applied_suggestion_ids'],
        );
        $this->assertSame(
            $preview['applied_suggestion_ids'],
            $preview['fields']['descripcion_catalogo']['applied_suggestion_ids'],
        );
        $this->assertEquals($masterSnapshot, $masterProduct->fresh()->getAttributes());
        $this->assertSame($masterCount, MasterProduct::query()->count());
        $this->assertSame($changeLogCount, ProductChangeLog::query()->count());
        $this->assertSame('MANON', $row->fresh()->marca_original);
    }

    public function test_invalid_original_brands_create_a_safe_review_preview(): void
    {
        foreach ([null, '', '   ', '0', ' 0 '] as $brand) {
            $row = ProductStagingRow::factory()->create([
                'nombre_sku_original' => 'PRODUCTO SIN CAMBIOS',
                'marca_original' => $brand,
                'requires_review' => false,
                'review_reason' => null,
            ]);

            $preview = $this->composer()->compose($row);
            $row->refresh();

            $this->assertSame((string) $brand, $preview['source_brand']);
            $this->assertSame((string) $brand, $preview['marca_homologada']);
            $this->assertSame([], $preview['fields']['marca_homologada']['applied_suggestion_ids']);
            $this->assertSame(
                [],
                $preview['fields']['marca_homologada']['pending_review_suggestion_ids'],
            );
            $this->assertSame([], $preview['fields']['marca_homologada']['blocked_suggestion_ids']);
            $this->assertSame('requires_review', $row->status);
            $this->assertTrue($row->requires_review);
            $this->assertStringContainsString(
                'Marca original vacía o no válida',
                $row->review_reason,
            );
            $this->assertNull($row->approved_at);
            $this->assertNull($row->approved_by_id);
        }
    }

    public function test_brand_preview_composition_is_idempotent(): void
    {
        Carbon::setTestNow('2026-08-19 15:00:00');
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'CAFÉ MOLIDO',
            'marca_original' => 'ARLISTAN',
        ]);
        $rule = $this->createRule([
            'detected_value' => 'ARLISTAN',
            'replacement_value' => 'Arlistán',
            'rule_type' => 'brand_normalization',
            'applies_to_field' => 'marca_homologada',
            'is_automatic' => false,
            'requires_review' => true,
        ]);
        $suggestion = $this->createSuggestion($row, $rule, [
            'field_name' => 'marca_homologada',
            'original_value' => 'ARLISTAN',
            'suggested_value' => 'Arlistán',
        ]);

        $firstPreview = $this->composer()->compose($row);
        $firstUpdatedAt = $row->fresh()->getRawOriginal('updated_at');
        $suggestionSnapshot = $suggestion->fresh()->getAttributes();
        $changeLogCount = ProductChangeLog::query()->count();
        Carbon::setTestNow('2026-08-20 15:00:00');

        $secondPreview = $this->composer()->compose($row);

        $this->assertSame($firstPreview, $secondPreview);
        $this->assertSame($firstUpdatedAt, $row->fresh()->getRawOriginal('updated_at'));
        $this->assertEquals($suggestionSnapshot, $suggestion->fresh()->getAttributes());
        $this->assertSame($changeLogCount, ProductChangeLog::query()->count());
    }

    public function test_a_brand_no_change_rule_cannot_apply_a_replacement(): void
    {
        $row = ProductStagingRow::factory()->create([
            'nombre_sku_original' => 'VINO TINTO',
            'marca_original' => 'MARCA CONFIRMADA',
        ]);
        $rule = $this->createRule([
            'detected_value' => 'MARCA CONFIRMADA',
            'replacement_value' => 'MARCA ALTERADA',
            'rule_type' => 'no_change',
            'applies_to_field' => 'marca_homologada',
            'is_automatic' => true,
            'requires_review' => false,
        ]);
        $suggestion = $this->createSuggestion($row, $rule, [
            'field_name' => 'marca_homologada',
            'original_value' => 'MARCA CONFIRMADA',
            'suggested_value' => 'MARCA ALTERADA',
        ]);

        $preview = $this->composer()->compose($row);

        $this->assertSame('MARCA CONFIRMADA', $preview['marca_homologada']);
        $this->assertSame(
            [],
            $preview['fields']['marca_homologada']['applied_suggestion_ids'],
        );
        $this->assertSame(
            [$suggestion->getKey()],
            $preview['fields']['marca_homologada']['blocked_suggestion_ids'],
        );
        $this->assertSame('requires_review', $row->fresh()->status);
    }

    public function test_analyzer_and_composer_integrate_a_sensitive_brand_without_side_effects(): void
    {
        NormalizationRule::query()->update(['active' => false]);

        $masterProduct = MasterProduct::factory()->create([
            'name' => 'Producto maestro intacto en integración de marca',
            'marca_original' => 'Marca maestra original',
            'marca_homologada' => 'Marca maestra homologada',
        ]);
        $row = ProductStagingRow::factory()
            ->for($masterProduct, 'masterProduct')
            ->create([
                'nombre_sku_original' => 'CAFÉ MOLIDO',
                'marca_original' => 'ARLISTAN',
            ]);
        $rule = $this->createRule([
            'detected_value' => 'ARLISTAN',
            'replacement_value' => 'Arlistán',
            'rule_type' => 'brand_normalization',
            'applies_to_field' => 'marca_homologada',
            'is_automatic' => false,
            'requires_review' => true,
            'confidence_level' => 'contextual',
        ]);
        $masterSnapshot = $masterProduct->fresh()->getAttributes();
        $changeLogCount = ProductChangeLog::query()->count();

        app(ProductStagingAnalyzer::class)->analyze($row);
        $suggestion = NormalizationSuggestion::query()->where([
            'product_staging_row_id' => $row->getKey(),
            'normalization_rule_id' => $rule->getKey(),
            'field_name' => 'marca_homologada',
        ])->sole();
        $preview = $this->composer()->compose($row);
        $row->refresh();

        $this->assertSame('Arlistán', $preview['marca_homologada']);
        $this->assertSame(
            [],
            $preview['fields']['marca_homologada']['applied_suggestion_ids'],
        );
        $this->assertSame(
            [$suggestion->getKey()],
            $preview['fields']['marca_homologada']['pending_review_suggestion_ids'],
        );
        $this->assertSame('pending', $suggestion->fresh()->status);
        $this->assertSame('ARLISTAN', $row->marca_original);
        $this->assertSame('previewed', $row->status);
        $this->assertTrue($row->requires_review);
        $this->assertNull($row->approved_at);
        $this->assertNull($row->approved_by_id);
        $this->assertEquals($masterSnapshot, $masterProduct->fresh()->getAttributes());
        $this->assertSame($changeLogCount, ProductChangeLog::query()->count());
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
