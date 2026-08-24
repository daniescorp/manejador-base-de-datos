<?php

namespace App\Filament\Admin\Resources\ProductStagingRowResource\Pages;

use App\Filament\Admin\Resources\ProductStagingRowResource;
use App\Models\User;
use App\Services\Products\ProductStagingApprovalService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ViewProductStagingRow extends ViewRecord
{
    protected static string $resource = ProductStagingRowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approveProduct')
                ->label('Aprobar producto')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Aprobar producto importado')
                ->modalDescription(
                    'Se creará o actualizará un producto maestro y se registrará cada cambio.',
                )
                ->visible(fn (): bool => app(ProductStagingApprovalService::class)
                    ->canApprove($this->getRecord()))
                ->action(function (ProductStagingApprovalService $service): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        Notification::make()
                            ->title('No se pudo aprobar el producto')
                            ->body('Se requiere un usuario válido para aprobar el producto.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $service->approve($this->getRecord(), $user);
                        $this->getRecord()->refresh();
                        $this->refreshFormData([
                            'master_product_id',
                            'status',
                            'requires_review',
                            'approved_at',
                            'approved_by_id',
                        ]);

                        Notification::make()
                            ->title('Producto aprobado y enviado a Productos Maestros.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('No se pudo aprobar el producto')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
