<?php

namespace App\Filament\Admin\Resources\ProductStagingRowResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NormalizationSuggestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'suggestions';

    protected static ?string $title = 'Sugerencias asociadas';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('field_name')->label('Campo')->badge()->sortable(),
                TextColumn::make('original_value')->label('Valor original')->wrap(),
                TextColumn::make('suggested_value')->label('Valor sugerido')->wrap()->placeholder('—'),
                TextColumn::make('status')->label('Estado')->badge()->sortable(),
                TextColumn::make('confidence_level')->label('Confianza')->badge(),
                TextColumn::make('suggestion_reason')->label('Motivo')->wrap()->toggleable(),
                TextColumn::make('reviewed_at')->label('Revisada')->dateTime()->placeholder('—')->toggleable(),
                TextColumn::make('applied_at')->label('Aplicada')->dateTime()->placeholder('—')->toggleable(),
                TextColumn::make('rule.detected_value')->label('Detectado')->placeholder('—'),
                TextColumn::make('rule.replacement_value')->label('Reemplazo')->placeholder('—'),
                TextColumn::make('rule.rule_type')->label('Tipo de regla')->badge()->toggleable(),
                TextColumn::make('rule.applies_to_field')->label('Aplica a')->toggleable(),
                IconColumn::make('rule.requires_review')->label('Revisión')->boolean()->toggleable(),
                IconColumn::make('rule.is_automatic')->label('Automática')->boolean()->toggleable(),
            ])
            ->defaultSort('id');
    }
}
