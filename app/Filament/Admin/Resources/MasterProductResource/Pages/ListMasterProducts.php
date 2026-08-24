<?php

namespace App\Filament\Admin\Resources\MasterProductResource\Pages;

use App\Filament\Admin\Resources\MasterProductResource;
use Filament\Resources\Pages\ListRecords;

class ListMasterProducts extends ListRecords
{
    protected static string $resource = MasterProductResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
