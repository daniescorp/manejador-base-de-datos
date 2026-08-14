<?php

namespace App\Filament\Admin\Resources\ValidationErrorResource\Pages;

use App\Filament\Admin\Resources\ValidationErrorResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewValidationError extends ViewRecord
{
    protected static string $resource = ValidationErrorResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
