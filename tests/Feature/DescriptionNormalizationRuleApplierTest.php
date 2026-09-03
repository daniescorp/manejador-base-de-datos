<?php

namespace Tests\Feature;

use App\Models\NormalizationRule;
use App\Services\Normalization\DescriptionNormalizationRuleApplier;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class DescriptionNormalizationRuleApplierTest extends TestCase
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

        if (blank($database) || $database === ':memory:') {
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

    public function test_it_removes_lowercase_khune_only_for_the_matching_homologated_brand(): void
    {
        $rule = $this->rule();
        $applier = app(DescriptionNormalizationRuleApplier::class);

        $matching = $applier->apply('Salsa   khune   yoghurt 250 ml.', 'KUHNE');
        $otherBrand = $applier->apply('Salsa khune yoghurt 250 ml.', 'OTRA MARCA');

        $this->assertSame('Salsa   khune   yoghurt 250 ml.', $matching['original']);
        $this->assertSame('Salsa yoghurt 250 ml.', $matching['normalized']);
        $this->assertTrue($matching['changed']);
        $this->assertSame([$rule->getKey()], array_column($matching['applied_rules'], 'id'));
        $this->assertSame([], $matching['pending_suggestions']);
        $this->assertSame('Salsa khune yoghurt 250 ml.', $otherBrand['normalized']);
        $this->assertFalse($otherBrand['changed']);
    }

    public function test_review_required_and_inactive_rules_do_not_change_the_description(): void
    {
        $reviewRule = $this->rule([
            'rule_name' => 'KHUNE pendiente de revisión',
            'requires_review' => true,
        ]);
        $this->rule([
            'rule_name' => 'KHUNE inactiva',
            'active' => false,
        ]);

        $result = app(DescriptionNormalizationRuleApplier::class)
            ->apply('Salsa khune yoghurt 250 ml.', 'KUHNE');

        $this->assertSame($result['original'], $result['normalized']);
        $this->assertFalse($result['changed']);
        $this->assertSame([], $result['applied_rules']);
        $this->assertSame(
            [$reviewRule->getKey()],
            array_column($result['pending_suggestions'], 'id'),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function rule(array $attributes = []): NormalizationRule
    {
        return NormalizationRule::factory()->create(array_merge([
            'rule_name' => 'Quitar KHUNE de descripción - KUHNE',
            'detected_value' => 'KHUNE',
            'replacement_value' => null,
            'rule_type' => 'description_normalization',
            'applies_to_field' => 'descripcion_catalogo',
            'context' => 'marca_homologada=KUHNE',
            'priority' => 100,
            'is_automatic' => true,
            'requires_preview' => true,
            'requires_review' => false,
            'confidence_level' => 'high',
            'active' => true,
        ], $attributes));
    }
}
