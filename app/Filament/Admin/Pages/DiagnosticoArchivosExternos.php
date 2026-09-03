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
use Illuminate\Support\Facades\Log;
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

    /** @var array{name: string, extension: string, size: int|null} | null */
    #[Locked]
    public ?array $uploadedFileInfo = null;

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
                        'application/vnd.ms-excel',
                    ])
                    ->storeFiles(false)
                    ->afterStateUpdated(function (mixed $state): void {
                        $uploadedFile = Arr::first(Arr::wrap($state));
                        $this->fileWasUploaded = $uploadedFile instanceof TemporaryUploadedFile;
                        $this->uploadedFileInfo = $uploadedFile instanceof TemporaryUploadedFile
                            ? [
                                'name' => $uploadedFile->getClientOriginalName(),
                                'extension' => mb_strtolower($uploadedFile->getClientOriginalExtension(), 'UTF-8'),
                                'size' => $uploadedFile->getSize() ?: null,
                            ]
                            : null;
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

            if ($extension === 'xls') {
                throw new \InvalidArgumentException(
                    'Por ahora se admiten archivos .xlsx y .txt. Convertí el archivo .xls a .xlsx antes de cargarlo.',
                );
            }

            if (! in_array($extension, ['txt', 'xlsx'], true)) {
                throw new \InvalidArgumentException('El archivo debe tener extensión TXT o XLSX.');
            }

            File::ensureDirectoryExists($this->temporarySourceDirectory());
            $this->pruneExpiredTemporarySources();
            $sourceBaseName = Str::slug(pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME));
            $sourceBaseName = $sourceBaseName !== '' ? $sourceBaseName : 'archivo';
            $this->sourceToken = Str::uuid().'-'.$sourceBaseName.'.'.$extension;
            $sourcePath = $this->temporarySourceDirectory().DIRECTORY_SEPARATOR.$this->sourceToken;

            if (! File::copy($uploadedPath, $sourcePath)) {
                throw new \RuntimeException('No se pudo conservar el archivo temporal para exportarlo.');
            }

            try {
                $this->diagnosis = $diagnosisService->diagnose($sourcePath, $workflow);
            } catch (Throwable $exception) {
                if ($extension === 'xlsx') {
                    throw new \InvalidArgumentException(
                        'No se pudo reconocer la estructura del Excel. Verifique encabezados y formato.',
                        previous: $exception,
                    );
                }

                throw $exception;
            }

            $this->diagnosis['source_file'] = $uploadedFile->getClientOriginalName();
            $this->diagnosis['source_extension'] = $extension;
            $this->diagnosis['source_size'] = $uploadedFile->getSize() ?: null;

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

            Log::warning('External file diagnosis failed.', [
                'workflow' => $workflow,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            Notification::make()
                ->title('No se pudo diagnosticar el archivo')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $uploadedFile->delete();
            $this->fileWasUploaded = false;
            $this->uploadedFileInfo = null;
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
        $this->uploadedFileInfo = null;
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
            $isCatalogPackage = $this->workflow() === 'catalog_body';
            $fileName = ($export['format'] ?? 'txt') === 'zip'
                ? 'catalogo-txt-categorias-'.now()->format('Ymd-His').'.zip'
                : ($isCatalogPackage
                    ? (string) ($export['file_name'] ?? 'catalogo-exportado-'.now()->format('Ymd-His').'.txt')
                    : $this->exportFilePrefix().'-exportado-'.now()->format('Ymd-His').'.txt');

            Notification::make()
                ->title(($export['format'] ?? 'txt') === 'zip' ? 'ZIP generado correctamente' : 'TXT generado correctamente')
                ->body($isCatalogPackage
                    ? 'La descarga conserva cada categoría por separado, con sus filas y columnas originales.'
                    : 'La descarga conserva las filas y columnas del archivo diagnosticado.')
                ->success()
                ->send();

            return response()->streamDownload(
                static function () use ($export): void {
                    echo $export['content'];
                },
                $fileName,
                ['Content-Type' => $export['mime_type'] ?? 'text/plain; charset=UTF-8'],
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

    public function workflowType(): string
    {
        return $this->workflow();
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
            || preg_match('/\A[0-9a-f-]{36}-[a-z0-9-]+\.(?:txt|xlsx)\z/', $this->sourceToken) !== 1) {
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
        $this->uploadedFileInfo = null;
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

    public function displayFileSize(?int $bytes): string
    {
        if ($bytes === null) {
            return 'Tamaño no disponible';
        }

        return $bytes >= 1024 * 1024
            ? number_format($bytes / (1024 * 1024), 2, ',', '.').' MB'
            : number_format(max(1, $bytes / 1024), 1, ',', '.').' KB';
    }
}
