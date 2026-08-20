<?php

namespace App\Filament\Admin\Resources\ProductStagingRowResource\Pages;

use App\Filament\Admin\Resources\ProductStagingRowResource;
use Filament\Resources\Pages\ListRecords;

class ListProductStagingRows extends ListRecords
{
    protected static string $resource = ProductStagingRowResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
