<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\DiagnosticoArchivosExternos;
use App\Filament\Admin\Pages\DiagnosticoPromociones;
use App\Models\User;
use App\Services\ExternalFiles\ExternalExportDiagnosisService;
use Dotenv\Dotenv;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ExternalDiagnosisPageTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql'];

    protected function setUpTraits(): array
    {
        $environmentFile = base_path('.env');

        if (! is_file($environmentFile)) {
            throw new RuntimeException('A MySQL database configuration is required to run the UI tests.');
        }

        $environment = Dotenv::parse(file_get_contents($environmentFile));
        $database = $environment['DB_DATABASE'] ?? null;

        if (blank($database) || ($database === ':memory:')) {
            throw new RuntimeException('A persistent MySQL database name is required to run the UI tests.');
        }

        config()->set([
            'app.env' => 'local',
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

        $this->actingAs(User::factory()->create());
    }

    public function test_the_catalog_page_accepts_supported_files_without_a_workflow_selector(): void
    {
        Livewire::test(DiagnosticoArchivosExternos::class)
            ->assertSuccessful()
            ->assertFormFieldExists('file', function (FileUpload $field): bool {
                return $field->shouldStoreFiles() === false
                    && $field->getAcceptedFileTypes() === [
                        'text/plain',
                        'text/tab-separated-values',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ];
            })
            ->assertFormFieldDoesNotExist('workflow')
            ->assertSee('Catálogo cuerpo general')
            ->assertSee('Subí un TXT tabulado o Excel comercial de catálogo.')
            ->assertSee('Diagnosticar catálogo')
            ->assertDontSee('Workflow');
    }

    public function test_the_promotions_page_accepts_supported_files_without_a_workflow_selector(): void
    {
        Livewire::test(DiagnosticoPromociones::class)
            ->assertSuccessful()
            ->assertFormFieldExists('file')
            ->assertFormFieldDoesNotExist('workflow')
            ->assertSee('Promociones / TAPA AMBA')
            ->assertSee('Subí un TXT o Excel de promociones / TAPA AMBA.')
            ->assertSee('Diagnosticar promoción')
            ->assertDontSee('Workflow');
    }

    public function test_the_catalog_diagnosis_always_uses_the_catalog_workflow(): void
    {
        $capturedPath = null;

        $this->mock(ExternalExportDiagnosisService::class, function (MockInterface $mock) use (&$capturedPath): void {
            $mock->shouldReceive('diagnose')
                ->once()
                ->withArgs(function (string $path, string $workflow) use (&$capturedPath): bool {
                    $capturedPath = $path;

                    return $workflow === 'catalog_body' && is_readable($path);
                })
                ->andReturn($this->diagnosisFor('catalog_body'));
        });

        $upload = UploadedFile::fake()->createWithContent(
            'catalogo-sintetico.txt',
            "CODIGO\tPRECIOOFERTA\n10001\t529",
        );

        Livewire::test(DiagnosticoArchivosExternos::class)
            ->fillForm(['file' => $upload])
            ->call('diagnose')
            ->assertHasNoFormErrors()
            ->assertSet('diagnosis.workflow_type', 'catalog_body')
            ->assertSee('Exportar archivo');

        $this->assertNotNull($capturedPath);
        $this->assertFileDoesNotExist($capturedPath);
    }

    public function test_a_blocked_promotions_diagnosis_uses_the_promotions_workflow_and_never_exports(): void
    {
        $capturedPath = null;
        $exportsBefore = File::glob(storage_path('app/exports/*.txt')) ?: [];

        $this->mock(ExternalExportDiagnosisService::class, function (MockInterface $mock) use (&$capturedPath): void {
            $mock->shouldReceive('diagnose')
                ->once()
                ->withArgs(function (string $path, string $workflow) use (&$capturedPath): bool {
                    $capturedPath = $path;

                    return $workflow === 'promo_tapa' && is_readable($path);
                })
                ->andReturn($this->blockedDiagnosis());
        });

        $upload = UploadedFile::fake()->createWithContent(
            'promo-sintetica.txt',
            "CODIGO\tPRECIOOFERTA\n60157 -\t529",
        );

        Livewire::test(DiagnosticoPromociones::class)
            ->fillForm(['file' => $upload])
            ->call('diagnose')
            ->assertHasNoFormErrors()
            ->assertSet('diagnosis.status', 'blocked')
            ->assertSet('diagnosis.rows_count', 1)
            ->assertSet('diagnosis.blocked_count', 1)
            ->assertSee('La exportación está bloqueada. Corrija los errores críticos antes de exportar.')
            ->assertSee('60157 -')
            ->assertSee('Exportar archivo');

        $this->assertNotNull($capturedPath);
        $this->assertFileDoesNotExist($capturedPath);
        $this->assertSame($exportsBefore, File::glob(storage_path('app/exports/*.txt')) ?: []);
    }

    /** @return array<string, mixed> */
    private function diagnosisFor(string $workflow): array
    {
        return [
            ...$this->blockedDiagnosis(),
            'status' => 'ok',
            'workflow_type' => $workflow,
            'price_map_count' => 1,
            'warning_count' => 0,
            'blocked_count' => 0,
            'can_export_automatically' => true,
            'price_map' => ['10001' => ['precio_oferta' => '529']],
            'warnings' => [],
            'preview_rows' => [[
                'row_number' => 2,
                'code' => '10001',
                'brand' => '',
                'description' => '',
                'price_list' => '',
                'price_offer' => '529',
                'price_strikethrough' => '',
                'status' => 'ok',
            ]],
            'summary' => ['product_count' => 1],
        ];
    }

    /** @return array<string, mixed> */
    private function blockedDiagnosis(): array
    {
        return [
            'status' => 'blocked',
            'workflow_type' => 'promo_tapa',
            'source_file' => 'temporary.txt',
            'format' => 'txt',
            'delimiter' => 'tab',
            'encoding' => 'UTF-8',
            'column_count' => 2,
            'rows_count' => 1,
            'price_map_count' => 0,
            'warning_count' => 1,
            'review_count' => 0,
            'blocked_count' => 1,
            'can_export_automatically' => false,
            'price_map' => [],
            'warnings' => [[
                'issue' => 'incomplete_composite_code',
                'severity' => 'blocked',
                'code' => '60157 -',
                'row_number' => 1,
                'original_value' => '60157 -',
                'recommendation' => 'correct_code_manually',
            ]],
            'preview_rows' => [[
                'row_number' => 2,
                'code' => '60157 -',
                'brand' => '',
                'description' => '',
                'price_list' => '',
                'price_offer' => '529',
                'price_strikethrough' => '',
                'status' => 'blocked',
            ]],
            'summary' => [
                'product_count' => 0,
            ],
        ];
    }
}
