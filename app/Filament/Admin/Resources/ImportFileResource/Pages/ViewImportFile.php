<?php

namespace App\Filament\Admin\Resources\ImportFileResource\Pages;

use App\Filament\Admin\Resources\ImportFileResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewImportFile extends ViewRecord
{
    protected static string $resource = ImportFileResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
