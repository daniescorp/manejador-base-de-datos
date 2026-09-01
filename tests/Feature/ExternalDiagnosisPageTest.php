<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\DiagnosticoArchivosExternos;
use App\Filament\Admin\Pages\DiagnosticoPromociones;
use App\Models\User;
use App\Services\ExternalFiles\ExternalExportDiagnosisService;
use App\Services\ExternalFiles\ExternalWorkflowExportService;
use Dotenv\Dotenv;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
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
                    && $field->isPreviewable()
                    && $field->getPlaceholder() === 'Arrastrá y soltá tu archivo o hacé clic para seleccionarlo'
                    && $field->getAcceptedFileTypes() === [
                        'text/plain',
                        'text/tab-separated-values',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ];
            })
            ->assertFormFieldDoesNotExist('workflow')
            ->assertSeeHtml('class="fi-fo-file-upload')
            ->assertSeeHtml('x-load-src=')
            ->assertSeeHtml('wire:submit="diagnose"')
            ->assertSeeHtml('type="submit"')
            ->assertSee('Catálogo cuerpo general')
            ->assertSee('Subí un TXT tabulado o Excel comercial de catálogo.')
            ->assertSee('Arrastrá y soltá tu archivo o hacé clic para seleccionarlo')
            ->assertSee('Diagnosticar catálogo')
            ->assertDontSee('Workflow');
    }

    public function test_diagnosis_requires_an_uploaded_file(): void
    {
        Livewire::test(DiagnosticoArchivosExternos::class)
            ->call('diagnose')
            ->assertHasFormErrors(['file' => 'required'])
            ->assertSee('Debe subir un archivo antes de diagnosticar.');
    }

    public function test_diagnosis_reports_when_the_temporary_upload_is_no_longer_available(): void
    {
        $component = Livewire::test(DiagnosticoArchivosExternos::class)
            ->fillForm(['file' => UploadedFile::fake()->createWithContent(
                'catalogo-temporal.txt',
                "CODIGO\tPRECIOOFERTA\n10001\t529",
            )]);

        $temporaryFile = Arr::first($component->get('data')['file']);

        $this->assertInstanceOf(TemporaryUploadedFile::class, $temporaryFile);
        $temporaryFile->delete();
        $component->instance()->fileWasUploaded = true;
        $component->instance()->diagnose(app(ExternalExportDiagnosisService::class));

        $this->assertSame(
            'El archivo temporal no está disponible. Vuelva a subirlo.',
            $component->instance()->diagnosisError,
        );
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

        $component = Livewire::test(DiagnosticoArchivosExternos::class)
            ->fillForm(['file' => $upload])
            ->call('diagnose')
            ->assertHasNoFormErrors()
            ->assertSet('diagnosis.workflow_type', 'catalog_body')
            ->assertSee('Exportar TXT')
            ->assertSee('El diagnóstico está OK.');

        $this->assertNotNull($capturedPath);
        $this->assertFileExists($capturedPath);

        $component->call('clearDiagnosis');

        $this->assertFileDoesNotExist($capturedPath);
    }

    public function test_a_service_failure_is_shown_and_logged_without_leaving_the_temporary_source(): void
    {
        $capturedPath = null;

        $this->mock(ExternalExportDiagnosisService::class, function (MockInterface $mock) use (&$capturedPath): void {
            $mock->shouldReceive('diagnose')
                ->once()
                ->withArgs(function (string $path, string $workflow) use (&$capturedPath): bool {
                    $capturedPath = $path;

                    return $workflow === 'catalog_body' && is_file($path);
                })
                ->andThrow(new RuntimeException('Fallo sintético del diagnóstico.'));
        });
        Log::shouldReceive('warning')
            ->once()
            ->with('External file diagnosis failed.', \Mockery::on(
                fn (array $context): bool => $context['workflow'] === 'catalog_body'
                    && $context['exception'] === RuntimeException::class
                    && $context['message'] === 'Fallo sintético del diagnóstico.',
            ));

        Livewire::test(DiagnosticoArchivosExternos::class)
            ->fillForm(['file' => UploadedFile::fake()->createWithContent(
                'catalogo-con-error.txt',
                "CODIGO\tPRECIOOFERTA\n10001\t529",
            )])
            ->call('diagnose')
            ->assertSet('diagnosis', null)
            ->assertSet('diagnosisError', 'Fallo sintético del diagnóstico.')
            ->assertSee('Fallo sintético del diagnóstico.');

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
            ->assertSee('Exportar TXT')
            ->call('exportTxt')
            ->assertNoFileDownloaded();

        $this->assertNotNull($capturedPath);
        $this->assertFileDoesNotExist($capturedPath);
        $this->assertSame($exportsBefore, File::glob(storage_path('app/exports/*.txt')) ?: []);
    }

    public function test_review_required_keeps_export_disabled(): void
    {
        $this->mock(ExternalExportDiagnosisService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('diagnose')->once()->andReturn([
                ...$this->diagnosisFor('catalog_body'),
                'status' => 'review_required',
                'warning_count' => 1,
                'review_count' => 1,
                'can_export_automatically' => false,
                'warnings' => [[
                    'issue' => 'price_requires_review',
                    'severity' => 'review',
                    'code' => '10001',
                    'row_number' => 1,
                    'original_value' => '699,50',
                    'recommendation' => 'review_price_manually',
                ]],
            ]);
        });
        $this->mock(ExternalWorkflowExportService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('export');
        });

        Livewire::test(DiagnosticoArchivosExternos::class)
            ->fillForm(['file' => UploadedFile::fake()->createWithContent(
                'revision-sintetica.txt',
                "CODIGO\tPRECIOOFERTA\n10001\t699,50",
            )])
            ->call('diagnose')
            ->assertSet('diagnosis.status', 'review_required')
            ->assertSee('Hay advertencias que deben revisarse antes de exportar.')
            ->call('exportTxt')
            ->assertNoFileDownloaded();
    }

    public function test_an_ok_diagnosis_downloads_the_generated_catalog_txt(): void
    {
        $this->travelTo(now()->setDate(2026, 8, 31)->setTime(14, 30, 45));
        $this->mock(ExternalExportDiagnosisService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('diagnose')->once()->andReturn($this->diagnosisFor('catalog_body'));
        });
        $this->mock(ExternalWorkflowExportService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('export')
                ->once()
                ->withArgs(fn (string $path, string $workflow): bool => is_file($path) && $workflow === 'catalog_body')
                ->andReturn([
                    'content' => "CODIGO\tPRECIOOFERTA\r\n10001\t$ 529",
                    'rows' => 1,
                    'columns' => 2,
                    'diagnosis' => $this->diagnosisFor('catalog_body'),
                ]);
        });

        $component = Livewire::test(DiagnosticoArchivosExternos::class)
            ->fillForm(['file' => UploadedFile::fake()->createWithContent(
                'catalogo-sintetico.txt',
                "CODIGO\tPRECIOOFERTA\n10001\t529",
            )])
            ->call('diagnose')
            ->call('exportTxt')
            ->assertFileDownloaded('catalogo-exportado-20260831-143045.txt');

        $component->call('clearDiagnosis');
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
