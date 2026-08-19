<?php

namespace App\Filament\Admin\Resources\NormalizationRuleResource\Pages;

use App\Filament\Admin\Resources\NormalizationRuleResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditNormalizationRule extends EditRecord
{
    protected static string $resource = NormalizationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($userId = auth()->id()) !== null) {
            $data['updated_by_id'] = $userId;
        }

        return $data;
    }
}
