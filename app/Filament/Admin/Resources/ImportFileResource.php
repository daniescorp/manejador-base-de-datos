<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ImportFileResource\Pages;
use App\Models\ImportFile;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ImportFileResource extends Resource
{
    protected static ?string $model = ImportFile::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Base de Datos';

    protected static ?string $navigationLabel = 'Archivos Importados';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'archivo importado';

    protected static ?string $pluralModelLabel = 'archivos importados';

    protected static ?string $recordTitleAttribute = 'original_name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('import_batch_id')
                    ->label('Lote')
                    ->relationship('batch', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('original_name')->label('Nombre original')->required()->maxLength(255),
                TextInput::make('stored_path')->label('Ruta almacenada')->maxLength(255),
                TextInput::make('file_type')->label('Tipo de archivo')->maxLength(255),
                TextInput::make('delimiter')->label('Delimitador')->maxLength(255),
                TextInput::make('encoding')->label('Codificación')->maxLength(255),
                TextInput::make('total_rows')->label('Filas totales')->integer()->minValue(0)->default(0)->required(),
                TextInput::make('valid_rows')->label('Filas válidas')->integer()->minValue(0)->default(0)->required(),
                TextInput::make('error_rows')->label('Filas con error')->integer()->minValue(0)->default(0)->required(),
                KeyValue::make('meta')->label('Metadatos')->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('batch.name')->label('Lote')->searchable()->sortable(),
                TextColumn::make('original_name')->label('Archivo')->searchable()->sortable(),
                TextColumn::make('file_type')->label('Tipo')->searchable(),
                TextColumn::make('total_rows')->label('Total')->numeric()->sortable(),
                TextColumn::make('valid_rows')->label('Válidas')->numeric()->sortable(),
                TextColumn::make('error_rows')->label('Errores')->numeric()->sortable(),
                TextColumn::make('created_at')->label('Creado')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('file_type')
                    ->label('Tipo de archivo')
                    ->options([
                        'excel' => 'Excel',
                        'csv' => 'CSV',
                        'txt' => 'TXT',
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
            'index' => Pages\ListImportFiles::route('/'),
            'create' => Pages\CreateImportFile::route('/create'),
            'view' => Pages\ViewImportFile::route('/{record}'),
            'edit' => Pages\EditImportFile::route('/{record}/edit'),
        ];
    }
}
