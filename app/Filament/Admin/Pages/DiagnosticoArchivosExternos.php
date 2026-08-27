<?php

namespace App\Filament\Admin\Pages;

use App\Services\ExternalFiles\ExternalExportDiagnosisService;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/** @property-read Schema $form */
class DiagnosticoArchivosExternos extends Page
{
    protected static ?string $navigationLabel = 'Diagnóstico de Archivos Externos';

    protected static ?string $title = 'Diagnóstico de Archivos Externos';

    protected static ?string $slug = 'diagnostico-archivos-externos';

    protected static string | \UnitEnum | null $navigationGroup = 'Procesos de Catálogo';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.admin.pages.diagnostico-archivos-externos';

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    /** @var array<string, mixed> | null */
    public ?array $diagnosis = null;

    public ?string $diagnosisError = null;

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
                    ->acceptedFileTypes([
                        'text/plain',
                        'text/tab-separated-values',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->storeFiles(false)
                    ->previewable(false)
                    ->maxSize(25 * 1024)
                    ->required()
                    ->columnSpanFull(),
            ])
            ->statePath('data')
            ->columns(1);
    }

    public function diagnose(ExternalExportDiagnosisService $diagnosisService): void
    {
        $this->diagnosis = null;
        $this->diagnosisError = null;
        $state = $this->form->getState();
        $uploadedFile = $state['file'] ?? null;

        if (! $uploadedFile instanceof TemporaryUploadedFile) {
            $this->addError('data.file', 'Seleccioná un archivo TXT o XLSX válido.');

            return;
        }

        $workflow = $this->workflow();

        try {
            $this->diagnosis = $diagnosisService->diagnose($uploadedFile->getRealPath(), $workflow);
            $this->diagnosis['source_file'] = $uploadedFile->getClientOriginalName();

            Notification::make()
                ->title('Diagnóstico completado')
                ->body('El archivo fue analizado sin importar productos ni generar una exportación.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            $this->diagnosisError = $exception->getMessage();

            Notification::make()
                ->title('No se pudo diagnosticar el archivo')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $uploadedFile->delete();
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

        $this->diagnosis = null;
        $this->diagnosisError = null;
        $this->resetValidation();
        $this->form->fill();
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
