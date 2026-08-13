<?php

namespace App\Filament\Admin\Resources\ExportJobResource\Pages;

use App\Filament\Admin\Resources\ExportJobResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExportJobs extends ListRecords
{
    protected static string $resource = ExportJobResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
