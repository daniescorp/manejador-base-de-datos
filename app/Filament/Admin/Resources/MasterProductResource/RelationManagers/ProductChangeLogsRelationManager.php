<?php

namespace App\Filament\Admin\Resources\MasterProductResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductChangeLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'changeLogs';

    protected static ?string $title = 'Historial de cambios';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime()->sortable(),
                TextColumn::make('source')->label('Origen')->badge()->searchable(),
                TextColumn::make('field_name')->label('Campo')->badge()->searchable()->sortable(),
                TextColumn::make('old_value')->label('Valor anterior')->wrap()->placeholder('—')->searchable(),
                TextColumn::make('new_value')->label('Valor nuevo')->wrap()->placeholder('—')->searchable(),
                TextColumn::make('change_reason')->label('Motivo')->wrap()->placeholder('—')->searchable(),
                TextColumn::make('changedBy.name')->label('Modificado por')->placeholder('—'),
                TextColumn::make('changed_by_id')->label('ID usuario')->placeholder('—')->toggleable(),
                TextColumn::make('rule.rule_name')->label('Regla')->placeholder('—')->toggleable(),
                TextColumn::make('normalization_rule_id')->label('ID regla')->placeholder('—')->toggleable(),
                TextColumn::make('import_batch_id')->label('Lote')->placeholder('—')->toggleable(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('created_at')
                ->orderByDesc('id'));
    }
}
