<?php

namespace App\Filament\Admin\Resources\ImportBatchResource\Pages;

use App\Filament\Admin\Resources\ImportBatchResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewImportBatch extends ViewRecord
{
    protected static string $resource = ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
