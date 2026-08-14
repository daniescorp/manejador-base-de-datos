<?php

namespace App\Filament\Admin\Resources\ImportFileResource\Pages;

use App\Filament\Admin\Resources\ImportFileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListImportFiles extends ListRecords
{
    protected static string $resource = ImportFileResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
