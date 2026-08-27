<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ImportBatchResource\Pages;
use App\Models\ImportBatch;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ImportBatchResource extends Resource
{
    protected static ?string $model = ImportBatch::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Base de Datos';

    protected static ?string $navigationLabel = 'Lotes de Importación';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'lote de importación';

    protected static ?string $pluralModelLabel = 'lotes de importación';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('Nombre')->maxLength(255),
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
                TextInput::make('process_type')->label('Tipo de proceso')->maxLength(255),
                TextInput::make('source_type')->label('Tipo de origen')->maxLength(255),
                Select::make('uploaded_by_id')
                    ->label('Cargado por')
                    ->relationship('uploadedBy', 'name')
                    ->searchable()
                    ->preload(),
                DateTimePicker::make('started_at')->label('Inicio'),
                DateTimePicker::make('finished_at')->label('Finalización')->afterOrEqual('started_at'),
                Textarea::make('notes')->label('Notas')->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('process_type')->label('Proceso')->searchable(),
                TextColumn::make('source_type')->label('Origen')->searchable(),
                TextColumn::make('status')->label('Estado')->badge()->searchable()->sortable(),
                TextColumn::make('uploadedBy.name')->label('Cargado por')->searchable(),
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
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImportBatches::route('/'),
            'create' => Pages\CreateImportBatch::route('/create'),
            'view' => Pages\ViewImportBatch::route('/{record}'),
            'edit' => Pages\EditImportBatch::route('/{record}/edit'),
        ];
    }
}
