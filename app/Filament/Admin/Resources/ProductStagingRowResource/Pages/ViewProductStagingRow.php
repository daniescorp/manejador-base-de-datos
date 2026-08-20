<?php

namespace App\Filament\Admin\Resources\ProductStagingRowResource\Pages;

use App\Filament\Admin\Resources\ProductStagingRowResource;
use Filament\Resources\Pages\ViewRecord;

class ViewProductStagingRow extends ViewRecord
{
    protected static string $resource = ProductStagingRowResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
