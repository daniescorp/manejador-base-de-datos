<?php

namespace App\Filament\Admin\Resources\ImportBatchResource\Pages;

use App\Filament\Admin\Resources\ImportBatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListImportBatches extends ListRecords
{
    protected static string $resource = ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
