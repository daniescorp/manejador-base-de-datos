<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\ProductStagingRowResource\Pages\ListProductStagingRows;
use App\Filament\Admin\Resources\ProductStagingRowResource\Pages\ViewProductStagingRow;
use App\Models\ImportBatch;
use App\Models\MasterProduct;
use App\Models\ProductChangeLog;
use App\Models\ProductStagingRow;
use App\Models\User;
use Dotenv\Dotenv;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ProductStagingRowImageUiTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql'];

    private string $temporaryDirectory;

    protected function setUpTraits(): array
    {
        $environmentFile = base_path('.env');

        if (! is_file($environmentFile)) {
            throw new RuntimeException('A MySQL database configuration is required to run the image UI tests.');
        }

        $environment = Dotenv::parse(file_get_contents($environmentFile));
        $database = $environment['DB_DATABASE'] ?? null;

        if (blank($database) || $database === ':memory:') {
            throw new RuntimeException('A persistent MySQL database name is required to run the image UI tests.');
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

        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'product-images-'.Str::uuid();
        File::makeDirectory($this->temporaryDirectory);

        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_image_blades_enforce_inline_thumbnail_limits_without_exposing_the_source_path(): void
    {
        $listBlade = file_get_contents(resource_path('views/filament/tables/columns/product-image.blade.php'));
        $detailBlade = file_get_contents(resource_path('views/filament/infolists/components/product-image.blade.php'));

        $this->assertStringContainsString('width: 80px; height: 64px;', $listBlade);
        $this->assertStringContainsString('max-width: 80px; max-height: 64px;', $listBlade);
        $this->assertStringContainsString('width: 260px; height: 260px;', $detailBlade);
        $this->assertStringContainsString('max-width: 260px; max-height: 260px;', $detailBlade);

        foreach ([$listBlade, $detailBlade] as $blade) {
            $this->assertStringContainsString('object-fit: contain;', $blade);
            $this->assertStringContainsString('loading="lazy"', $blade);
            $this->assertStringNotContainsString('file://', $blade);
            $this->assertStringNotContainsString('BANCO DE IMAGENES CENTRAL', $blade);
        }
    }

    public function test_the_list_renders_a_row_without_an_image_or_configuration(): void
    {
        config()->set('product_images.base_path');
        $row = ProductStagingRow::factory()->create([
            'codigo_producto_original' => 'IMG-NOT-CONFIGURED-'.Str::uuid(),
        ]);

        Livewire::test(ListProductStagingRows::class)
            ->searchTable($row->codigo_producto_original)
            ->assertCanSeeTableRecords([$row])
            ->assertSee('No configurado');
    }

    public function test_the_list_and_detail_render_an_existing_product_image(): void
    {
        config()->set('product_images.base_path', $this->temporaryDirectory);
        $code = 'IMG-220600';
        $this->createPng($code);
        $row = ProductStagingRow::factory()->create([
            'codigo_producto_original' => $code,
        ]);

        Livewire::test(ListProductStagingRows::class)
            ->searchTable($code)
            ->assertCanSeeTableRecords([$row])
            ->assertSee("Imagen del producto {$code}")
            ->assertSeeHtml('class="h-full w-full rounded object-contain"')
            ->assertSeeHtml('loading="lazy"')
            ->assertSee(route('filament.admin.product-images.show', ['code' => $code]));

        Livewire::test(ViewProductStagingRow::class, ['record' => $row->getRouteKey()])
            ->assertSuccessful()
            ->assertSee($code)
            ->assertSee("{$code}.png")
            ->assertSee('found')
            ->assertSee("Imagen del producto {$code}")
            ->assertSeeHtml('class="max-h-full max-w-full object-contain"')
            ->assertSeeHtml('target="_blank"')
            ->assertSee('Ver imagen completa');
    }

    public function test_the_list_and_detail_render_a_missing_image_state(): void
    {
        config()->set('product_images.base_path', $this->temporaryDirectory);
        $row = ProductStagingRow::factory()->create([
            'codigo_producto_original' => 'IMG-MISSING',
        ]);

        Livewire::test(ListProductStagingRows::class)
            ->searchTable($row->codigo_producto_original)
            ->assertCanSeeTableRecords([$row])
            ->assertSee('Sin imagen');

        Livewire::test(ViewProductStagingRow::class, ['record' => $row->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('missing')
            ->assertSee('Sin imagen')
            ->assertDontSee('Ver imagen completa');
    }

    public function test_image_rendering_does_not_modify_protected_data(): void
    {
        config()->set('product_images.base_path', $this->temporaryDirectory);
        $code = 'IMG-PROTECTED';
        $this->createPng($code);
        $masterProduct = MasterProduct::factory()->create();
        $row = ProductStagingRow::factory()->create([
            'master_product_id' => $masterProduct->getKey(),
            'codigo_producto_original' => $code,
        ]);
        $rowSnapshot = $row->fresh()->getAttributes();
        $masterSnapshot = $masterProduct->fresh()->getAttributes();
        $changeLogCount = ProductChangeLog::query()->count();

        Livewire::test(ViewProductStagingRow::class, ['record' => $row->getRouteKey()])
            ->assertSuccessful();

        $this->assertSame($rowSnapshot, $row->fresh()->getAttributes());
        $this->assertSame($masterSnapshot, $masterProduct->fresh()->getAttributes());
        $this->assertSame($changeLogCount, ProductChangeLog::query()->count());
    }

    public function test_the_audit_command_reports_json_without_writing_to_the_database(): void
    {
        config()->set('product_images.base_path', $this->temporaryDirectory);
        $batch = ImportBatch::factory()->create();
        $this->createPng('FOUND-IMAGE');
        ProductStagingRow::factory()->create([
            'import_batch_id' => $batch->getKey(),
            'codigo_producto_original' => 'FOUND-IMAGE',
        ]);
        ProductStagingRow::factory()->create([
            'import_batch_id' => $batch->getKey(),
            'codigo_producto_original' => 'MISSING-IMAGE',
        ]);
        ProductStagingRow::factory()->create([
            'import_batch_id' => $batch->getKey(),
            'codigo_producto_original' => '../INVALID',
        ]);
        $rowsBefore = ProductStagingRow::query()
            ->where('import_batch_id', $batch->getKey())
            ->orderBy('id')
            ->get()
            ->map->getAttributes();
        $masterCount = MasterProduct::query()->count();
        $changeLogCount = ProductChangeLog::query()->count();

        $exitCode = Artisan::call('app:audit-product-images', [
            '--batch-id' => $batch->getKey(),
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(3, $report['checked_rows']);
        $this->assertSame(1, $report['found_images']);
        $this->assertSame(1, $report['missing_images']);
        $this->assertSame(0, $report['not_configured_count']);
        $this->assertSame(1, $report['invalid_code_count']);
        $this->assertCount(1, $report['examples_found']);
        $this->assertCount(1, $report['examples_missing']);
        $this->assertEquals(
            $rowsBefore,
            ProductStagingRow::query()
                ->where('import_batch_id', $batch->getKey())
                ->orderBy('id')
                ->get()
                ->map->getAttributes(),
        );
        $this->assertSame($masterCount, MasterProduct::query()->count());
        $this->assertSame($changeLogCount, ProductChangeLog::query()->count());
    }

    private function createPng(string $code): void
    {
        File::put(
            $this->temporaryDirectory.DIRECTORY_SEPARATOR."{$code}.png",
            base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                true,
            ),
        );
    }
}
