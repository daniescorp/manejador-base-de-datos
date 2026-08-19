<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\NormalizationRuleResource;
use App\Filament\Admin\Resources\NormalizationRuleResource\Pages\CreateNormalizationRule;
use App\Filament\Admin\Resources\NormalizationRuleResource\Pages\EditNormalizationRule;
use App\Filament\Admin\Resources\NormalizationRuleResource\Pages\ListNormalizationRules;
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

class NormalizationRuleResourceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql'];

    private User $user;

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

        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_an_authenticated_admin_user_can_access_the_normalization_dictionary(): void
    {
        $rule = NormalizationRule::factory()->create([
            'rule_name' => 'Regla visible '.Str::uuid(),
            'detected_value' => 'VISIBLE',
            'applies_to_field' => 'descripcion_catalogo',
            'priority' => 0,
        ]);

        Livewire::test(ListNormalizationRules::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$rule]);

        $this->assertSame('Diccionario de Normalización', NormalizationRuleResource::getNavigationLabel());
        $this->assertSame('Manejador de Datos', NormalizationRuleResource::getNavigationGroup());
    }

    public function test_it_creates_a_description_normalization_rule(): void
    {
        $ruleName = 'MX a MAX '.Str::uuid();

        Livewire::test(CreateNormalizationRule::class)
            ->fillForm([
                'rule_name' => $ruleName,
                'detected_value' => 'MX',
                'replacement_value' => 'MAX',
                'rule_type' => 'abbreviation',
                'applies_to_field' => 'descripcion_catalogo',
                'context' => null,
                'priority' => 100,
                'is_automatic' => false,
                'requires_preview' => true,
                'requires_review' => true,
                'confidence_level' => 'contextual',
                'active' => true,
                'notes' => 'Aplicar solo cuando MX funcione como abreviatura de MAX en descripción.',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $rule = NormalizationRule::query()->where('rule_name', $ruleName)->firstOrFail();

        $this->assertSame('MX', $rule->detected_value);
        $this->assertSame('MAX', $rule->replacement_value);
        $this->assertSame('abbreviation', $rule->rule_type);
        $this->assertSame('descripcion_catalogo', $rule->applies_to_field);
        $this->assertFalse($rule->is_automatic);
        $this->assertTrue($rule->requires_preview);
        $this->assertTrue($rule->requires_review);
        $this->assertSame('contextual', $rule->confidence_level);
        $this->assertTrue($rule->active);
        $this->assertSame(
            'Aplicar solo cuando MX funcione como abreviatura de MAX en descripción.',
            $rule->notes,
        );
        $this->assertSame($this->user->getKey(), $rule->created_by_id);
        $this->assertSame($this->user->getKey(), $rule->updated_by_id);
    }

    public function test_it_creates_brand_normalization_rules(): void
    {
        foreach ([
            ['ARLISTAN', 'Arlistán', 'marca con escritura oficial y tilde; revisar antes de aplicar masivamente.'],
            ['MANON', 'Manón', 'marca con posible tilde; validar escritura oficial antes de aplicar masivamente.'],
        ] as [$detectedValue, $replacementValue, $notes]) {
            $ruleName = "Marca {$detectedValue} ".Str::uuid();

            Livewire::test(CreateNormalizationRule::class)
                ->fillForm([
                    'rule_name' => $ruleName,
                    'detected_value' => $detectedValue,
                    'replacement_value' => $replacementValue,
                    'rule_type' => 'brand_normalization',
                    'applies_to_field' => 'marca_homologada',
                    'priority' => 100,
                    'is_automatic' => false,
                    'requires_preview' => true,
                    'requires_review' => true,
                    'confidence_level' => 'contextual',
                    'active' => true,
                    'notes' => $notes,
                ])
                ->call('create')
                ->assertHasNoFormErrors();

            $this->assertDatabaseHas('normalization_rules', [
                'rule_name' => $ruleName,
                'detected_value' => $detectedValue,
                'replacement_value' => $replacementValue,
                'rule_type' => 'brand_normalization',
                'applies_to_field' => 'marca_homologada',
                'is_automatic' => false,
                'requires_preview' => true,
                'requires_review' => true,
                'confidence_level' => 'contextual',
                'active' => true,
                'notes' => $notes,
                'created_by_id' => $this->user->getKey(),
                'updated_by_id' => $this->user->getKey(),
            ]);
        }
    }

    public function test_it_edits_an_existing_rule_and_records_the_updater(): void
    {
        $creator = User::factory()->create();
        $rule = NormalizationRule::factory()->for($creator, 'createdBy')->create([
            'rule_name' => 'Regla editable '.Str::uuid(),
            'detected_value' => 'LIMON',
            'replacement_value' => 'LIMÓN',
            'rule_type' => 'accent',
            'applies_to_field' => 'descripcion_catalogo',
        ]);

        Livewire::test(EditNormalizationRule::class, ['record' => $rule->getRouteKey()])
            ->fillForm([
                'replacement_value' => 'Limón',
                'priority' => 50,
                'notes' => 'Revisada desde el diccionario.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $rule->refresh();

        $this->assertSame('Limón', $rule->replacement_value);
        $this->assertSame(50, $rule->priority);
        $this->assertSame('Revisada desde el diccionario.', $rule->notes);
        $this->assertSame($creator->getKey(), $rule->created_by_id);
        $this->assertSame($this->user->getKey(), $rule->updated_by_id);
    }

    public function test_it_deactivates_a_rule_without_deleting_it(): void
    {
        $rule = NormalizationRule::factory()->create([
            'rule_name' => 'Regla desactivable '.Str::uuid(),
            'applies_to_field' => 'descripcion_catalogo',
            'active' => true,
        ]);

        Livewire::test(EditNormalizationRule::class, ['record' => $rule->getRouteKey()])
            ->fillForm(['active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $rule->refresh();

        $this->assertFalse($rule->active);
        $this->assertSame($this->user->getKey(), $rule->updated_by_id);
        $this->assertDatabaseHas('normalization_rules', ['id' => $rule->getKey()]);
    }

    public function test_destructive_actions_are_not_available(): void
    {
        $rule = NormalizationRule::factory()->create([
            'applies_to_field' => 'descripcion_catalogo',
        ]);

        $this->assertFalse(NormalizationRuleResource::canDelete($rule));
        $this->assertFalse(NormalizationRuleResource::canDeleteAny());
        $this->assertFalse(NormalizationRuleResource::canForceDelete($rule));
        $this->assertFalse(NormalizationRuleResource::canForceDeleteAny());

        Livewire::test(ListNormalizationRules::class)
            ->assertTableActionDoesNotExist('delete')
            ->assertTableActionDoesNotExist('forceDelete')
            ->assertTableBulkActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('forceDelete');

        Livewire::test(EditNormalizationRule::class, ['record' => $rule->getRouteKey()])
            ->assertActionDoesNotExist('delete')
            ->assertActionDoesNotExist('forceDelete');
    }

    public function test_resource_changes_do_not_modify_master_products_or_create_change_logs(): void
    {
        $masterProduct = MasterProduct::factory()->create();
        $masterProductSnapshot = $masterProduct->fresh()->getAttributes();
        $masterProductCount = MasterProduct::query()->count();
        $changeLogCount = ProductChangeLog::query()->count();
        $ruleName = 'Regla aislada '.Str::uuid();

        Livewire::test(CreateNormalizationRule::class)
            ->fillForm([
                'rule_name' => $ruleName,
                'detected_value' => 'AISLADA',
                'replacement_value' => 'Aislada',
                'rule_type' => 'brand_normalization',
                'applies_to_field' => 'marca_homologada',
                'priority' => 100,
                'is_automatic' => false,
                'requires_preview' => true,
                'requires_review' => true,
                'confidence_level' => 'contextual',
                'active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $rule = NormalizationRule::query()->where('rule_name', $ruleName)->firstOrFail();

        Livewire::test(EditNormalizationRule::class, ['record' => $rule->getRouteKey()])
            ->fillForm(['active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($masterProductCount, MasterProduct::query()->count());
        $this->assertSame($changeLogCount, ProductChangeLog::query()->count());
        $this->assertSame($masterProductSnapshot, $masterProduct->fresh()->getAttributes());
    }

    public function test_form_options_and_minimum_validation_are_enforced(): void
    {
        $this->assertArrayHasKey('descripcion_catalogo', NormalizationRuleResource::APPLIES_TO_FIELD_OPTIONS);
        $this->assertArrayHasKey('marca_homologada', NormalizationRuleResource::APPLIES_TO_FIELD_OPTIONS);
        $this->assertArrayHasKey('brand_normalization', NormalizationRuleResource::RULE_TYPE_OPTIONS);
        $this->assertArrayHasKey('slash_abbreviation', NormalizationRuleResource::RULE_TYPE_OPTIONS);

        Livewire::test(CreateNormalizationRule::class)
            ->fillForm([
                'rule_name' => null,
                'detected_value' => null,
                'rule_type' => null,
                'priority' => -1,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'rule_name' => 'required',
                'detected_value' => 'required',
                'rule_type' => 'required',
                'priority' => 'min',
            ]);
    }
}
