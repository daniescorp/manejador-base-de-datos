<?php

namespace App\Filament\Admin\Resources\ImportRowResource\Pages;

use App\Filament\Admin\Resources\ImportRowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListImportRows extends ListRecords
{
    protected static string $resource = ImportRowResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
