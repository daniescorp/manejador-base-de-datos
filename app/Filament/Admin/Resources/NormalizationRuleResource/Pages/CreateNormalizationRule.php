<?php

namespace App\Filament\Admin\Resources\NormalizationRuleResource\Pages;

use App\Filament\Admin\Resources\NormalizationRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNormalizationRule extends CreateRecord
{
    protected static string $resource = NormalizationRuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($userId = auth()->id()) !== null) {
            $data['created_by_id'] = $userId;
            $data['updated_by_id'] = $userId;
        }

        return $data;
    }
}
