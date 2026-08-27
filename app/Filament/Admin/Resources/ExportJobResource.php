<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ExportJobResource\Pages;
use App\Models\ExportJob;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ExportJobResource extends Resource
{
    protected static ?string $model = ExportJob::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $modelLabel = 'exportación';

    protected static ?string $pluralModelLabel = 'exportaciones';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('Nombre')->maxLength(255),
                Select::make('export_type')
                    ->label('Tipo de exportación')
                    ->options([
                        'excel' => 'Excel',
                        'csv' => 'CSV',
                        'txt' => 'TXT',
                        'shopify' => 'Shopify',
                        'other' => 'Otro',
                    ])
                    ->required(),
                Select::make('status')
                    ->label('Estado')
                    ->options([
                        'draft' => 'Borrador',
                        'pending' => 'Pendiente',
                        'processing' => 'Procesando',
                        'completed' => 'Completado',
                        'failed' => 'Fallido',
                    ])
                    ->default('draft')
                    ->required(),
                TextInput::make('file_path')->label('Ruta del archivo')->maxLength(255),
                TextInput::make('rows_count')->label('Cantidad de filas')->integer()->minValue(0)->default(0)->required(),
                Select::make('created_by_id')
                    ->label('Creado por')
                    ->relationship('createdBy', 'name')
                    ->searchable()
                    ->preload(),
                DateTimePicker::make('started_at')->label('Inicio'),
                DateTimePicker::make('finished_at')->label('Finalización')->afterOrEqual('started_at'),
                KeyValue::make('meta')->label('Metadatos')->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('export_type')->label('Tipo')->badge()->searchable()->sortable(),
                TextColumn::make('status')->label('Estado')->badge()->searchable()->sortable(),
                TextColumn::make('rows_count')->label('Filas')->numeric()->sortable(),
                TextColumn::make('createdBy.name')->label('Creado por')->searchable(),
                TextColumn::make('created_at')->label('Creado')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'draft' => 'Borrador',
                        'pending' => 'Pendiente',
                        'processing' => 'Procesando',
                        'completed' => 'Completado',
                        'failed' => 'Fallido',
                    ]),
                SelectFilter::make('export_type')
                    ->label('Tipo de exportación')
                    ->options([
                        'excel' => 'Excel',
                        'csv' => 'CSV',
                        'txt' => 'TXT',
                        'shopify' => 'Shopify',
                        'other' => 'Otro',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExportJobs::route('/'),
            'create' => Pages\CreateExportJob::route('/create'),
            'view' => Pages\ViewExportJob::route('/{record}'),
            'edit' => Pages\EditExportJob::route('/{record}/edit'),
        ];
    }
}
