<?php

namespace App\Filament\Admin\Resources\MasterProductResource\Pages;

use App\Filament\Admin\Resources\MasterProductResource;
use App\Models\MasterProduct;
use App\Models\User;
use App\Services\Normalization\MasterProductDescriptionNormalizationService;
use App\Services\Products\MasterProductMeasurementService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ViewMasterProduct extends ViewRecord
{
    protected static string $resource = MasterProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reanalyzeDescription')
                ->label('Reanalizar descripción con Diccionario')
                ->icon(Heroicon::OutlinedLanguage)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Previsualizar normalización de descripción')
                ->modalDescription(fn (): string => $this->descriptionNormalizationSummary())
                ->modalSubmitActionLabel('Confirmar y actualizar descripción')
                ->visible(fn (): bool => filled($this->getRecord()->descripcion_catalogo)
                    && filled($this->getRecord()->marca_homologada))
                ->action(function (MasterProductDescriptionNormalizationService $service): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        Notification::make()
                            ->title('No se pudo normalizar la descripción')
                            ->body('Se requiere un usuario válido.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $result = $service->apply($this->getRecord(), $user);
                        $this->getRecord()->refresh();
                        $this->refreshFormData(['descripcion_catalogo']);

                        Notification::make()
                            ->title($result['changed']
                                ? 'Descripción actualizada con el Diccionario.'
                                : 'Sin cambios sugeridos')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('No se pudo normalizar la descripción')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('completeMeasurement')
                ->label('Completar medida')
                ->icon(Heroicon::OutlinedScale)
                ->color('primary')
                ->schema([
                    TextInput::make('value')
                        ->label('Valor')
                        ->numeric()
                        ->minValue(0.001)
                        ->required(),
                    Select::make('unit')
                        ->label('Unidad')
                        ->options([
                            'GR' => 'GR',
                            'KG' => 'KG',
                            'LT' => 'LT',
                            'CC' => 'CC',
                            'ML' => 'ML',
                            'UN' => 'UN / unidades',
                            'MT' => 'MT',
                            'SOBRES' => 'Sobres',
                            'UNIDADES' => 'Unidades',
                        ])
                        ->required(),
                    Textarea::make('reason')
                        ->label('Motivo')
                        ->maxLength(1000),
                ])
                ->visible(fn (): bool => $this->canManageMeasurement())
                ->action(function (array $data, MasterProductMeasurementService $service): void {
                    $this->runMeasurementAction(
                        fn (MasterProduct $record, User $user): MasterProduct => $service->completeMeasurement(
                            $record,
                            $user,
                            $data['value'],
                            $data['unit'],
                            $data['reason'] ?? null,
                        ),
                        'Medida actualizada correctamente.',
                    );
                }),
            Action::make('markMeasurementNotApplicable')
                ->label('Marcar medida no aplicable')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Confirmar excepción manual de medida')
                ->modalDescription(
                    'La medida se marcará como no aplicable y se auditará el motivo. Si existe una medida, se limpiará.',
                )
                ->modalSubmitActionLabel('Confirmar excepción')
                ->schema([
                    Textarea::make('reason')
                        ->label('Motivo obligatorio')
                        ->required()
                        ->maxLength(1000),
                ])
                ->visible(fn (): bool => $this->canManageMeasurement())
                ->action(function (array $data, MasterProductMeasurementService $service): void {
                    $this->runMeasurementAction(
                        fn (MasterProduct $record, User $user): MasterProduct => $service->markMeasurementNotApplicable(
                            $record,
                            $user,
                            $data['reason'],
                        ),
                        'Excepción de medida registrada correctamente.',
                    );
                }),
        ];
    }

    private function descriptionNormalizationSummary(): string
    {
        $result = app(MasterProductDescriptionNormalizationService::class)
            ->preview($this->getRecord());

        if (! $result['changed']) {
            $pending = array_column($result['pending_suggestions'], 'name');

            return $pending === []
                ? 'Sin cambios sugeridos.'
                : 'Sin cambios automáticos. Sugerencias pendientes de revisión: '.implode(', ', $pending).'.';
        }

        return implode("\n", [
            'Antes: '.$result['original'],
            'Después: '.$result['normalized'],
            'Reglas aplicadas: '.implode(', ', array_column($result['applied_rules'], 'name')),
        ]);
    }

    private function canManageMeasurement(): bool
    {
        $record = $this->getRecord();

        return $record->status === 'active'
            && $record->estado_homologacion === 'aprobado_catalogo'
            && ! $record->requiere_revision;
    }

    /**
     * @param  callable(MasterProduct, User): MasterProduct  $callback
     */
    private function runMeasurementAction(callable $callback, string $successMessage): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            Notification::make()
                ->title('No se pudo actualizar la medida')
                ->body('Se requiere un usuario válido.')
                ->danger()
                ->send();

            return;
        }

        try {
            $callback($this->getRecord(), $user);
            $this->getRecord()->refresh();
            $this->refreshFormData([
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
                'data',
            ]);

            Notification::make()
                ->title($successMessage)
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('No se pudo actualizar la medida')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
