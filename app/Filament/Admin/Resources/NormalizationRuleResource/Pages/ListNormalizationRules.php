<?php

namespace App\Filament\Admin\Resources\NormalizationRuleResource\Pages;

use App\Filament\Admin\Resources\NormalizationRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNormalizationRules extends ListRecords
{
    protected static string $resource = NormalizationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
