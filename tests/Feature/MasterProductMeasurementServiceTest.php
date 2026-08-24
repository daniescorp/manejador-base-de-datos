<?php

namespace Tests\Feature;

use App\Models\MasterProduct;
use App\Models\ProductChangeLog;
use App\Models\User;
use App\Services\Products\MasterProductMeasurementService;
use DomainException;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class MasterProductMeasurementServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql'];

    protected function setUpTraits(): array
    {
        $environmentFile = base_path('.env');

        if (! is_file($environmentFile)) {
            throw new RuntimeException('A MySQL database configuration is required to run the measurement tests.');
        }

        $environment = Dotenv::parse(file_get_contents($environmentFile));
        $database = $environment['DB_DATABASE'] ?? null;

        if (blank($database) || $database === ':memory:') {
            throw new RuntimeException('A persistent MySQL database name is required to run the measurement tests.');
        }

        config()->set([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => $database,
        ]);

        return parent::setUpTraits();
    }

    public function test_it_completes_measurement_updates_commercial_texts_and_logs_only_changes(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $protected = $product->only([
            'codigo_producto',
            'marca_original',
            'marca_homologada',
            'ean_original',
            'uxb_original',
            'data',
        ]);

        $updated = $this->service()->completeMeasurement(
            $product,
            $user,
            240,
            'grs.',
            'Presentación manual controlada',
        );

        $this->assertSame('240.000', $updated->contenido_valor);
        $this->assertSame('GR', $updated->unidad_original);
        $this->assertSame('GR', $updated->unidad_normalizada);
        $this->assertSame('240.000', $updated->medida_valor);
        $this->assertSame('240GR', $updated->medida_catalogo);
        $this->assertSame('240GR', $updated->medida_original);
        $this->assertFalse($updated->medida_requiere_revision);
        $this->assertSame('Arroz curry 240GR', $updated->descripcion_catalogo);
        $this->assertSame('Arroz curry 240GR', $updated->nombre_sin_marca);
        $this->assertSame('Arroz curry 240GR', $updated->nombre_homologado);
        $this->assertSame('Arroz curry 240GR', $updated->name);
        $this->assertEquals($protected, $updated->only(array_keys($protected)));

        foreach ([
            'contenido_valor',
            'unidad_original',
            'unidad_normalizada',
            'medida_valor',
            'medida_catalogo',
            'medida_original',
            'medida_requiere_revision',
            'descripcion_catalogo',
            'nombre_sin_marca',
            'nombre_homologado',
            'name',
        ] as $field) {
            $this->assertDatabaseHas('product_change_logs', [
                'master_product_id' => $product->getKey(),
                'changed_by_id' => $user->getKey(),
                'source' => 'manual',
                'field_name' => $field,
                'change_reason' => 'Presentación manual controlada',
            ]);
        }
    }

    public function test_it_normalizes_supported_unit_aliases(): void
    {
        $user = User::factory()->create();
        $cases = [
            'gr' => '2GR',
            'g' => '2GR',
            'grs' => '2GR',
            'kg' => '2KG',
            'kgs' => '2KG',
            'k' => '2KG',
            'lt' => '2LT',
            'lts' => '2LT',
            'l' => '2LT',
            'cm3' => '2CC',
            'ml' => '2ML',
            'mts' => '2MT',
            'un' => '2 unidades',
            'sobre' => '2 sobres',
        ];

        foreach ($cases as $unit => $expectedMeasure) {
            $updated = $this->service()->completeMeasurement($this->product(), $user, 2, $unit);

            $this->assertSame($expectedMeasure, $updated->medida_catalogo);
        }
    }

    public function test_it_replaces_an_old_measure_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $product = $this->product([
            'medida_catalogo' => '200GR',
            'medida_original' => '200GR',
            'contenido_valor' => 200,
            'medida_valor' => 200,
            'unidad_original' => 'GR',
            'unidad_normalizada' => 'GR',
            'medida_requiere_revision' => false,
            'descripcion_catalogo' => 'Arroz curry 200GR',
            'nombre_sin_marca' => 'Arroz curry 200GR',
            'nombre_homologado' => 'Arroz curry 200GR',
            'name' => 'Arroz curry 200GR',
        ]);

        $updated = $this->service()->completeMeasurement($product, $user, 240, 'GR');
        $logCount = ProductChangeLog::query()->count();
        $secondUpdate = $this->service()->completeMeasurement($updated, $user, 240, 'GR');

        $this->assertSame('Arroz curry 240GR', $updated->descripcion_catalogo);
        $this->assertStringNotContainsString('200GR', $updated->descripcion_catalogo);
        $this->assertSame(1, substr_count($secondUpdate->descripcion_catalogo, '240GR'));
        $this->assertSame($logCount, ProductChangeLog::query()->count());
    }

    public function test_it_blocks_invalid_values_units_and_unpersisted_models_without_writes(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $productSnapshot = $product->fresh()->getAttributes();
        $logCount = ProductChangeLog::query()->count();
        $cases = [
            ['', 'GR'],
            ['abc', 'GR'],
            [0, 'GR'],
            [-1, 'GR'],
            [240, 'INVALIDA'],
        ];

        foreach ($cases as [$value, $unit]) {
            try {
                $this->service()->completeMeasurement($product, $user, $value, $unit);
                $this->fail('Expected invalid measurement input to be rejected.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach ([[new MasterProduct, $user], [$product, new User]] as [$invalidProduct, $invalidUser]) {
            try {
                $this->service()->completeMeasurement($invalidProduct, $invalidUser, 240, 'GR');
                $this->fail('Expected unpersisted models to be rejected.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($productSnapshot, $product->fresh()->getAttributes());
        $this->assertSame($logCount, ProductChangeLog::query()->count());
    }

    public function test_it_rolls_back_the_product_if_change_log_creation_fails(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $snapshot = $product->fresh()->getAttributes();
        $eventName = 'eloquent.creating: '.ProductChangeLog::class;

        Event::listen($eventName, function (): never {
            throw new RuntimeException('Forced measurement log failure.');
        });

        try {
            $this->service()->completeMeasurement($product, $user, 240, 'GR');
            $this->fail('Expected the log failure to roll back the measurement.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced measurement log failure.', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame($snapshot, $product->fresh()->getAttributes());
    }

    public function test_it_marks_measurement_not_applicable_with_traceability_and_preserves_data(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $protected = $product->only([
            'codigo_producto',
            'marca_original',
            'marca_homologada',
            'ean_original',
            'uxb_original',
        ]);

        $updated = $this->service()->markMeasurementNotApplicable(
            $product,
            $user,
            'La presentación no aplica a este producto',
        );

        $this->assertNull($updated->medida_catalogo);
        $this->assertFalse($updated->medida_requiere_revision);
        $this->assertSame('preserved', data_get($updated->data, 'marker'));
        $this->assertTrue(data_get($updated->data, 'measurement.not_applicable'));
        $this->assertSame(
            'La presentación no aplica a este producto',
            data_get($updated->data, 'measurement.not_applicable_reason'),
        );
        $this->assertSame($user->getKey(), data_get($updated->data, 'measurement.not_applicable_by_id'));
        $this->assertNotNull(data_get($updated->data, 'measurement.not_applicable_at'));
        $this->assertSame($protected, $updated->only(array_keys($protected)));
        $this->assertDatabaseHas('product_change_logs', [
            'master_product_id' => $product->getKey(),
            'field_name' => 'data',
            'source' => 'manual',
            'change_reason' => 'La presentación no aplica a este producto',
        ]);
    }

    public function test_it_requires_a_reason_for_measurement_exception(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $snapshot = $product->fresh()->getAttributes();

        try {
            $this->service()->markMeasurementNotApplicable($product, $user, '   ');
            $this->fail('Expected an empty exception reason to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('obligatorio', $exception->getMessage());
        }

        $this->assertSame($snapshot, $product->fresh()->getAttributes());
    }

    private function service(): MasterProductMeasurementService
    {
        return app(MasterProductMeasurementService::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes = []): MasterProduct
    {
        $token = (string) Str::uuid();

        return MasterProduct::factory()->create(array_merge([
            'codigo_producto' => "MEASURE-{$token}",
            'marca_original' => 'GALLO',
            'marca_homologada' => 'GALLO',
            'ean_original' => '7790070431554',
            'uxb_original' => '10',
            'descripcion_catalogo' => 'Arroz curry',
            'nombre_sin_marca' => 'Arroz curry',
            'nombre_homologado' => 'Arroz curry',
            'name' => 'Arroz curry',
            'medida_catalogo' => null,
            'medida_requiere_revision' => true,
            'data' => ['marker' => 'preserved'],
        ], $attributes));
    }
}
