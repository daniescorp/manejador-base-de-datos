<?php

namespace App\Filament\Admin\Resources\MasterProductResource\Pages;

use App\Filament\Admin\Resources\MasterProductResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewMasterProduct extends ViewRecord
{
    protected static string $resource = MasterProductResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
