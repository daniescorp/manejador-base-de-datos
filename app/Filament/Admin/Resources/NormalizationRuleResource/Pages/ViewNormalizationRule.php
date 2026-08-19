<?php

namespace App\Filament\Admin\Resources\NormalizationRuleResource\Pages;

use App\Filament\Admin\Resources\NormalizationRuleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewNormalizationRule extends ViewRecord
{
    protected static string $resource = NormalizationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
