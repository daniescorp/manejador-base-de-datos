<?php

namespace App\Filament\Admin\Resources\ExportJobResource\Pages;

use App\Filament\Admin\Resources\ExportJobResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewExportJob extends ViewRecord
{
    protected static string $resource = ExportJobResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
