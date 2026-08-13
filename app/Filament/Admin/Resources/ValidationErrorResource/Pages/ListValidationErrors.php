<?php

namespace App\Filament\Admin\Resources\ValidationErrorResource\Pages;

use App\Filament\Admin\Resources\ValidationErrorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListValidationErrors extends ListRecords
{
    protected static string $resource = ValidationErrorResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
