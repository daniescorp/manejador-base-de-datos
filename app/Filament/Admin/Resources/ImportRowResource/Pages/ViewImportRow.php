<?php

namespace App\Filament\Admin\Resources\ImportRowResource\Pages;

use App\Filament\Admin\Resources\ImportRowResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewImportRow extends ViewRecord
{
    protected static string $resource = ImportRowResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
