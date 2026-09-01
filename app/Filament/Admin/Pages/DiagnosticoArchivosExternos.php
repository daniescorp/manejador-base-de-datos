<?php

namespace App\Filament\Admin\Pages;

use App\Services\ExternalFiles\ExternalExportDiagnosisService;
use App\Services\ExternalFiles\ExternalWorkflowExportService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * @property-read Schema $form
 * @property-read Schema $uploadForm
 */
class DiagnosticoArchivosExternos extends Page
{
    protected static ?string $navigationLabel = 'Diagnóstico de Archivos Externos';

    protected static ?string $title = 'Diagnóstico de Archivos Externos';

    protected static ?string $slug = 'diagnostico-archivos-externos';

    protected static string|\UnitEnum|null $navigationGroup = 'Procesos de Catálogo';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.admin.pages.diagnostico-archivos-externos';

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    /** @var array<string, mixed> | null */
    public ?array $diagnosis = null;

    public ?string $diagnosisError = null;

    #[Locked]
    public ?string $sourceToken = null;

    public bool $fileWasUploaded = false;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('file')
                    ->label('Archivo externo')
                    ->helperText($this->uploadHelperText())
                    ->placeholder('Arrastrá y soltá tu archivo o hacé clic para seleccionarlo')
                    ->acceptedFileTypes([
                        'text/plain',
                        'text/tab-separated-values',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->storeFiles(false)
                    ->afterStateUpdated(function (mixed $state): void {
                        $this->fileWasUploaded = filled(Arr::wrap($state));
                    })
                    ->maxSize(25 * 1024)
                    ->required()
                    ->validationMessages([
                        'required' => 'Debe subir un archivo antes de diagnosticar.',
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data')
            ->columns(1);
    }

    public function uploadForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    EmbeddedSchema::make('form'),
                ])
                    ->id('external-diagnosis-form')
                    ->livewireSubmitHandler('diagnose')
                    ->extraAttributes(['class' => 'diagnosis-form'])
                    ->footer([
                        Actions::make([
                            Action::make('diagnose')
                                ->label($this->diagnoseButtonLabel())
                                ->icon('heroicon-o-magnifying-glass')
                                ->submit('diagnose'),
                            Action::make('clearDiagnosis')
                                ->label('Limpiar diagnóstico')
                                ->color('gray')
                                ->action(function (): void {
                                    $this->clearDiagnosis();
                                }),
                        ])
                            ->extraAttributes(['class' => 'diagnosis-form-actions']),
                    ]),
            ]);
    }

    public function diagnose(ExternalExportDiagnosisService $diagnosisService): void
    {
        $this->diagnosis = null;
        $this->diagnosisError = null;
        $rawUploadedFile = Arr::first(Arr::wrap($this->data['file'] ?? []));

        if ($this->fileWasUploaded && ! $rawUploadedFile instanceof TemporaryUploadedFile) {
            $this->reportUnavailableTemporaryUpload();

            return;
        }

        if ($rawUploadedFile instanceof TemporaryUploadedFile) {
            $rawUploadedPath = $rawUploadedFile->getRealPath();

            if ($rawUploadedPath === false || ! is_file($rawUploadedPath)) {
                $this->reportUnavailableTemporaryUpload();

                return;
            }
        }

        $state = $this->form->getState();
        $uploadedFile = $state['file'] ?? null;

        if (! $uploadedFile instanceof TemporaryUploadedFile) {
            $this->addError('data.file', 'Debe subir un archivo antes de diagnosticar.');

            return;
        }

        $workflow = $this->workflow();

        try {
            $this->deleteTemporarySource();
            $uploadedPath = $uploadedFile->getRealPath();

            if ($uploadedPath === false || ! is_file($uploadedPath)) {
                throw new \RuntimeException('El archivo temporal no está disponible. Vuelva a subirlo.');
            }

            $extension = mb_strtolower($uploadedFile->getClientOriginalExtension(), 'UTF-8');

            if (! in_array($extension, ['txt', 'xlsx'], true)) {
                throw new \InvalidArgumentException('El archivo debe tener extensión TXT o XLSX.');
            }

            File::ensureDirectoryExists($this->temporarySourceDirectory());
            $this->pruneExpiredTemporarySources();
            $this->sourceToken = Str::uuid().'.'.$extension;
            $sourcePath = $this->temporarySourceDirectory().DIRECTORY_SEPARATOR.$this->sourceToken;

            if (! File::copy($uploadedPath, $sourcePath)) {
                throw new \RuntimeException('No se pudo conservar el archivo temporal para exportarlo.');
            }

            $this->diagnosis = $diagnosisService->diagnose($sourcePath, $workflow);
            $this->diagnosis['source_file'] = $uploadedFile->getClientOriginalName();

            if (($this->diagnosis['status'] ?? null) !== 'ok') {
                $this->deleteTemporarySource();
            }

            Notification::make()
                ->title('Diagnóstico completado')
                ->body('El archivo fue analizado sin importar productos ni generar una exportación.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            $this->deleteTemporarySource();
            $this->diagnosisError = $exception->getMessage();

            Notification::make()
                ->title('No se pudo diagnosticar el archivo')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $uploadedFile->delete();
            $this->fileWasUploaded = false;
            $this->form->fill();
        }
    }

    public function clearDiagnosis(): void
    {
        foreach (Arr::wrap($this->data['file'] ?? []) as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                $file->delete();
            }
        }

        $this->deleteTemporarySource();
        $this->diagnosis = null;
        $this->diagnosisError = null;
        $this->fileWasUploaded = false;
        $this->resetValidation();
        $this->form->fill();
    }

    public function exportTxt(ExternalWorkflowExportService $exportService): mixed
    {
        if (($this->diagnosis['status'] ?? null) !== 'ok') {
            Notification::make()
                ->title('La exportación no está habilitada')
                ->body('Solo se pueden exportar archivos con diagnóstico OK.')
                ->warning()
                ->send();

            return null;
        }

        $sourcePath = $this->temporarySourcePath();

        if ($sourcePath === null) {
            Notification::make()
                ->title('El archivo temporal ya no está disponible')
                ->body('Volvé a cargar y diagnosticar el archivo antes de exportar.')
                ->danger()
                ->send();

            return null;
        }

        try {
            $export = $exportService->export($sourcePath, $this->workflow());
            $fileName = $this->exportFilePrefix().'-exportado-'.now()->format('Ymd-His').'.txt';

            Notification::make()
                ->title('TXT generado correctamente')
                ->body('La descarga conserva las filas y columnas del archivo diagnosticado.')
                ->success()
                ->send();

            return response()->streamDownload(
                static function () use ($export): void {
                    echo $export['content'];
                },
                $fileName,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        } catch (Throwable $exception) {
            Notification::make()
                ->title('No se pudo exportar el archivo')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }
    }

    protected function workflow(): string
    {
        return 'catalog_body';
    }

    public function workflowLabel(): string
    {
        return 'Catálogo cuerpo general';
    }

    public function workflowDescription(): string
    {
        return 'Detecta secciones duplicadas, filas inválidas y problemas antes de exportar.';
    }

    public function uploadHelperText(): string
    {
        return 'Subí un TXT tabulado o Excel comercial de catálogo.';
    }

    public function diagnoseButtonLabel(): string
    {
        return 'Diagnosticar catálogo';
    }

    protected function exportFilePrefix(): string
    {
        return 'catalogo';
    }

    private function temporarySourceDirectory(): string
    {
        return storage_path('app/private/external-workflow-inputs');
    }

    private function temporarySourcePath(): ?string
    {
        if ($this->sourceToken === null
            || preg_match('/\A[0-9a-f-]{36}\.(?:txt|xlsx)\z/', $this->sourceToken) !== 1) {
            return null;
        }

        $directory = realpath($this->temporarySourceDirectory());
        $path = realpath($this->temporarySourceDirectory().DIRECTORY_SEPARATOR.$this->sourceToken);

        if ($directory === false || $path === false) {
            return null;
        }

        $directoryPrefix = mb_strtolower($directory.DIRECTORY_SEPARATOR, 'UTF-8');

        return str_starts_with(mb_strtolower($path, 'UTF-8'), $directoryPrefix) && is_file($path)
            ? $path
            : null;
    }

    private function deleteTemporarySource(): void
    {
        $path = $this->temporarySourcePath();

        if ($path !== null) {
            File::delete($path);
        }

        $this->sourceToken = null;
    }

    private function pruneExpiredTemporarySources(): void
    {
        foreach (File::files($this->temporarySourceDirectory()) as $file) {
            if ($file->getMTime() < now()->subDay()->getTimestamp()) {
                File::delete($file->getRealPath());
            }
        }
    }

    private function reportUnavailableTemporaryUpload(): void
    {
        $message = 'El archivo temporal no está disponible. Vuelva a subirlo.';
        $this->diagnosisError = $message;
        $this->fileWasUploaded = false;
        $this->addError('data.file', $message);

        Notification::make()
            ->title('No se pudo diagnosticar el archivo')
            ->body($message)
            ->danger()
            ->send();

        $this->form->fill();
    }

    public function displayDiagnosticValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—';
    }

    public function displayDiagnosticText(?string $value): string
    {
        if (blank($value)) {
            return '—';
        }

        return ucfirst(str_replace('_', ' ', $value));
    }
}
