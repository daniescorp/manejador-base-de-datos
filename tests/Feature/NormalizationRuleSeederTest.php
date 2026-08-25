<?php

namespace Tests\Feature;

use App\Models\NormalizationRule;
use Database\Seeders\NormalizationRuleSeeder;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class NormalizationRuleSeederTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NormalizationRuleSeeder::class);
    }

    protected function setUpTraits(): array
    {
        $environmentFile = base_path('.env');

        if (! is_file($environmentFile)) {
            throw new RuntimeException('A MySQL database configuration is required to run the seeder tests.');
        }

        $environment = Dotenv::parse(file_get_contents($environmentFile));
        $database = $environment['DB_DATABASE'] ?? null;

        if (blank($database) || ($database === ':memory:')) {
            throw new RuntimeException('A persistent MySQL database name is required to run the seeder tests.');
        }

        config()->set([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => $database,
        ]);

        return parent::setUpTraits();
    }

    public function test_the_seeder_creates_the_initial_rule_catalog_without_failing(): void
    {
        $this->assertGreaterThanOrEqual(
            NormalizationRuleSeeder::RULE_COUNT,
            NormalizationRule::query()->count(),
        );
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $countAfterFirstRun = NormalizationRule::query()->count();

        $this->seed(NormalizationRuleSeeder::class);
        $countAfterSecondRun = NormalizationRule::query()->count();

        $this->seed(NormalizationRuleSeeder::class);
        $countAfterThirdRun = NormalizationRule::query()->count();

        $this->assertSame($countAfterFirstRun, $countAfterSecondRun);
        $this->assertSame($countAfterSecondRun, $countAfterThirdRun);
    }

    public function test_confirmed_slash_abbreviations_are_available(): void
    {
        $expected = [
            'D/P' => 'DOYPACK',
            'DES/AMBIENTE' => 'Desodorante de ambiente',
            'L/F' => 'Largo fino',
            'T/B' => 'Tetrabrik',
            'S/E' => 'Sin ensobrar',
            'S/DEO' => 'Sin Deo',
            'C/DEO' => 'Con Deo',
            'L/PROFUNDA' => 'Limpieza profunda',
            'S/CAROZO' => 'sin carozo',
            'S/SAL' => 'sin sal',
            'C/SAL' => 'con sal',
            'C/LECHE' => 'con leche',
            'P/HORNO' => 'para horno',
            'P/DILUIR' => 'para diluir',
            'C/CHIPS' => 'con chips',
            'C/ALAS' => 'con alas',
            'C/MIEL' => 'con miel',
            'C/HIERBAS' => 'con hierbas',
            'DCE/LECHE' => 'dulce de leche',
            'C/DDL' => 'con dulce de leche',
            'C/LIMON' => 'con limón',
            'C/NARANJA' => 'con naranja',
            'S/AZUCAR' => 'sin azúcar',
            'S/TACC' => 'sin TACC',
            'S/OLOR' => 'sin olor',
            'C/STEVIA' => 'con stevia',
            'P/FREEZER' => 'para freezer',
            'CAFE/COGN' => 'café al cognac',
        ];

        foreach ($expected as $detectedValue => $replacementValue) {
            $rule = $this->rule($detectedValue, 'slash_abbreviation');

            $this->assertSame($replacementValue, $rule->replacement_value);
            $this->assertTrue($rule->is_automatic);
            $this->assertTrue($rule->requires_preview);
            $this->assertFalse($rule->requires_review);
            $this->assertSame('high', $rule->confidence_level);
        }
    }

    public function test_generic_slash_abbreviations_require_contextual_review(): void
    {
        foreach (['C/' => 'con', 'S/' => 'sin', 'P/' => 'para'] as $detectedValue => $replacementValue) {
            $rule = $this->rule($detectedValue, 'slash_abbreviation');

            $this->assertSame($replacementValue, $rule->replacement_value);
            $this->assertFalse($rule->is_automatic);
            $this->assertTrue($rule->requires_preview);
            $this->assertTrue($rule->requires_review);
            $this->assertSame('contextual', $rule->confidence_level);
            $this->assertStringContainsString('con, sin o para', $rule->notes);
        }
    }

    public function test_confirmed_flavor_variants_are_available(): void
    {
        $expected = [
            'CAFE/RON' => 'café al ron',
            'VAIN/DDL' => 'vainilla / dulce de leche',
            'CHOC/CARAM' => 'chocolate / caramelo',
            'CREM/CEB' => 'crema y cebolla',
            'FR/BOSQUE' => 'frutos del bosque',
            'FRT/ROJ' => 'frutos rojos',
            'NAR/DURAZ' => 'naranja/durazno',
            'NJA/LIMA' => 'naranja/lima',
            'AVE/CHIPS' => 'avena/chips',
            'PESC/ARROZ' => 'pescado/arroz',
            'CAR/PESC/ARR' => 'carne/pescado/arroz',
        ];

        foreach ($expected as $detectedValue => $replacementValue) {
            $rule = $this->rule($detectedValue, 'flavor_variant');

            $this->assertSame($replacementValue, $rule->replacement_value);
            $this->assertTrue($rule->is_automatic);
            $this->assertTrue($rule->requires_preview);
            $this->assertFalse($rule->requires_review);
            $this->assertSame('high', $rule->confidence_level);
        }
    }

    public function test_slash_ranges_require_manual_review_without_replacement(): void
    {
        $ranges = [
            '900/800',
            '50/40',
            '300/250',
            '200/225',
            '700/750',
            '100/90',
            '180/170',
            '90/100',
            '125/130',
            '250/260',
        ];

        foreach ($ranges as $range) {
            $rule = $this->rule($range, 'manual_review');

            $this->assertNull($rule->replacement_value);
            $this->assertFalse($rule->is_automatic);
            $this->assertTrue($rule->requires_preview);
            $this->assertTrue($rule->requires_review);
            $this->assertSame('blocked', $rule->confidence_level);
            $this->assertStringContainsString('no modificar automáticamente', $rule->notes);
        }
    }

    public function test_confirmed_dotted_abbreviations_are_available(): void
    {
        $expected = [
            'FID.' => 'Fideos',
            'LIQ.' => 'Líquido',
            'MERM.' => 'Mermelada',
            'ACOND.' => 'Acondicionador',
            'ALIM.GATO' => 'Alimento gato',
            'ALIM.PERRO' => 'Alimento perro',
            'T.FEMENINA' => 'Toalla femenina',
            'T.HUMEDAS' => 'Toallas húmedas',
            'BIZC.' => 'Bizcochuelo',
            'GELAT.' => 'Gelatina',
            'P.FRITAS' => 'Papas fritas',
            'P.HIGIENICO' => 'Papel Higiénico',
            'M.COCIDO' => 'Mate cocido',
            'PROT.SOLAR' => 'Protector solar',
            'DESINF.' => 'Desinfectante',
            'INSECT.' => 'Insecticida',
            'RVA.' => 'Reserva',
            'BCO.DULCE' => 'Blanco dulce',
            'DCE.LECHE' => 'dulce de leche',
            'DESM.' => 'Descremada',
            'PREM.' => 'Premium',
            'PVO.' => 'Polvo',
            'POM.ROSADO' => 'Pomelo rosado',
            'ANTIBACT.' => 'Antibacterial',
            'LIMP.PISOS' => 'Limpiador pisos',
            'JAB.POLVO' => 'Jabón en polvo',
            'JABON.LIQ' => 'Jabón líquido',
        ];

        foreach ($expected as $detectedValue => $replacementValue) {
            $rule = $this->rule($detectedValue, 'dotted_abbreviation');

            $this->assertSame($replacementValue, $rule->replacement_value);
            $this->assertTrue($rule->is_automatic);
            $this->assertTrue($rule->requires_preview);
            $this->assertFalse($rule->requires_review);
            $this->assertSame('high', $rule->confidence_level);
        }
    }

    public function test_champagne_terms_point_to_espumantes(): void
    {
        foreach (['CHAMP.', 'Champagne', 'Champaña'] as $detectedValue) {
            $rule = $this->rule($detectedValue, 'category_word_replacement', 'bebidas / espumantes');

            $this->assertSame('Espumantes', $rule->replacement_value);
            $this->assertTrue($rule->is_automatic);
            $this->assertTrue($rule->requires_preview);
            $this->assertFalse($rule->requires_review);
            $this->assertSame('high', $rule->confidence_level);
            $this->assertStringContainsString('criterio comercial', mb_strtolower($rule->notes));
        }
    }

    public function test_confirmed_accent_rules_are_available(): void
    {
        foreach (['LIMON' => 'LIMÓN', 'CAFE' => 'CAFÉ', 'MAIZ' => 'MAÍZ', 'HIGIENICO' => 'HIGIÉNICO'] as $detectedValue => $replacementValue) {
            $rule = $this->rule($detectedValue, 'accent');

            $this->assertSame($replacementValue, $rule->replacement_value);
            $this->assertTrue($rule->is_automatic);
            $this->assertTrue($rule->requires_preview);
            $this->assertFalse($rule->requires_review);
            $this->assertSame('high', $rule->confidence_level);
        }
    }

    public function test_compact_measurement_rules_are_available_for_indesign(): void
    {
        $expected = [
            '750 CC' => '750CC',
            '500 GR' => '500GR',
            '1 LT' => '1LT',
            '1 KG' => '1KG',
            '30 MT' => '30MT',
            '4 x 30 MT' => '4x30MT',
            '12 x 50 GR' => '12x50GR',
            '3 x 1 LT' => '3x1LT',
        ];

        foreach ($expected as $detectedValue => $replacementValue) {
            $rule = $this->rule($detectedValue, 'measurement', 'indesign_catalog');

            $this->assertSame($replacementValue, $rule->replacement_value);
            $this->assertTrue($rule->is_automatic);
            $this->assertTrue($rule->requires_preview);
            $this->assertFalse($rule->requires_review);
            $this->assertSame('high', $rule->confidence_level);
            $this->assertStringContainsString('InDesign', $rule->notes);
        }
    }

    public function test_ceboll_is_seeded_as_an_automatic_exact_word_correction(): void
    {
        $rule = $this->rule('CEBOLL', 'abbreviation');

        $this->assertSame('CEBOLLA', $rule->replacement_value);
        $this->assertTrue($rule->is_automatic);
        $this->assertTrue($rule->requires_preview);
        $this->assertFalse($rule->requires_review);
        $this->assertSame('high', $rule->confidence_level);
        $this->assertStringContainsString('palabra incompleta', $rule->notes);
    }

    public function test_mx_is_seeded_as_a_contextual_review_rule(): void
    {
        $rule = $this->rule('MX', 'abbreviation');

        $this->assertSame('MAX', $rule->replacement_value);
        $this->assertFalse($rule->is_automatic);
        $this->assertTrue($rule->requires_preview);
        $this->assertTrue($rule->requires_review);
        $this->assertSame('contextual', $rule->confidence_level);
        $this->assertStringContainsString('80MX4UN', $rule->notes);
    }

    public function test_brand_accent_rules_are_seeded_for_manual_review(): void
    {
        foreach (['ARLISTAN' => 'Arlistán', 'MANON' => 'Manón'] as $detectedValue => $replacementValue) {
            $rule = NormalizationRule::query()
                ->where('detected_value', $detectedValue)
                ->where('rule_type', 'brand_normalization')
                ->where('applies_to_field', 'marca_homologada')
                ->firstOrFail();

            $this->assertSame($replacementValue, $rule->replacement_value);
            $this->assertFalse($rule->is_automatic);
            $this->assertTrue($rule->requires_preview);
            $this->assertTrue($rule->requires_review);
            $this->assertSame('contextual', $rule->confidence_level);
            $this->assertTrue($rule->active);
        }
    }

    public function test_taragui_variants_are_seeded_as_distinct_manual_review_brand_rules(): void
    {
        foreach (['TARAGUI', 'TARAGÜI'] as $detectedValue) {
            $rule = NormalizationRule::query()
                ->whereRaw('BINARY detected_value = ?', [$detectedValue])
                ->where('rule_type', 'brand_normalization')
                ->where('applies_to_field', 'marca_homologada')
                ->firstOrFail();

            $this->assertSame('Taragüi', $rule->replacement_value);
            $this->assertFalse($rule->is_automatic);
            $this->assertTrue($rule->requires_preview);
            $this->assertTrue($rule->requires_review);
            $this->assertSame('contextual', $rule->confidence_level);
            $this->assertTrue($rule->active);
        }
    }

    public function test_elegido_is_seeded_as_an_idempotent_norton_contextual_brand_rule(): void
    {
        $identity = [
            'detected_value' => 'ELEGIDO',
            'rule_type' => 'brand_normalization',
            'applies_to_field' => 'marca_homologada',
            'context' => 'nombre_sku_contains:NORTON',
        ];
        $rule = NormalizationRule::query()->where($identity)->sole();

        $this->assertSame('NORTON', $rule->replacement_value);
        $this->assertTrue($rule->is_automatic);
        $this->assertTrue($rule->requires_preview);
        $this->assertFalse($rule->requires_review);
        $this->assertSame('high', $rule->confidence_level);
        $this->assertTrue($rule->active);
        $this->assertStringContainsString('línea comercial', $rule->notes);

        $this->seed(NormalizationRuleSeeder::class);

        $this->assertSame(1, NormalizationRule::query()->where($identity)->count());
    }

    public function test_envelope_counts_are_seeded_as_an_automatic_contextual_rule(): void
    {
        $rule = $this->rule(
            'CANTIDAD+S',
            'contextual_abbreviation',
            'te_infusiones_ensobrados',
        );

        $this->assertSame('sobres', $rule->replacement_value);
        $this->assertTrue($rule->is_automatic);
        $this->assertTrue($rule->requires_preview);
        $this->assertFalse($rule->requires_review);
        $this->assertSame('high', $rule->confidence_level);
        $this->assertStringContainsString('25s, 50s o 100s', $rule->notes);
    }

    public function test_paper_abbreviations_are_contextual(): void
    {
        foreach (['HS' => 'Hoja Simple', 'DH' => 'Doble Hoja', 'TH' => 'Triple Hoja'] as $detectedValue => $replacementValue) {
            $rule = $this->rule($detectedValue, 'contextual_abbreviation', 'papel_higienico');

            $this->assertSame($replacementValue, $rule->replacement_value);
            $this->assertFalse($rule->is_automatic);
            $this->assertTrue($rule->requires_preview);
            $this->assertTrue($rule->requires_review);
            $this->assertSame('contextual', $rule->confidence_level);
            $this->assertStringContainsString('papel higiénico', $rule->notes);
        }
    }

    public function test_rell_requires_individual_manual_review(): void
    {
        $rule = $this->rule('RELL.', 'manual_review');

        $this->assertNull($rule->replacement_value);
        $this->assertFalse($rule->is_automatic);
        $this->assertTrue($rule->requires_preview);
        $this->assertTrue($rule->requires_review);
        $this->assertSame('blocked', $rule->confidence_level);
        $this->assertStringContainsString('revisión individual', $rule->notes);
    }

    public function test_wine_varietals_are_preserved_without_changes(): void
    {
        foreach (['CAB/MALBEC', 'CAB/SAUV', 'MALB/SYRAH'] as $detectedValue) {
            $rule = $this->rule($detectedValue, 'no_change', 'vinos');

            $this->assertNull($rule->replacement_value);
            $this->assertFalse($rule->is_automatic);
            $this->assertFalse($rule->requires_preview);
            $this->assertFalse($rule->requires_review);
            $this->assertSame('confirmed_no_change', $rule->confidence_level);
            $this->assertStringContainsString('conservar como están', $rule->notes);
        }
    }

    private function rule(string $detectedValue, string $ruleType, ?string $context = null): NormalizationRule
    {
        $query = NormalizationRule::query()
            ->where('detected_value', $detectedValue)
            ->where('rule_type', $ruleType)
            ->where('applies_to_field', 'descripcion_catalogo');

        if ($context === null) {
            $query->whereNull('context');
        } else {
            $query->where('context', $context);
        }

        $rule = $query->firstOrFail();

        $this->assertNotSame('', $rule->rule_name);
        $this->assertSame(100, (int) $rule->priority);
        $this->assertTrue($rule->active);

        return $rule;
    }
}
