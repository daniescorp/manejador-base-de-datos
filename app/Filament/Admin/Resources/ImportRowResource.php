<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ImportRowResource\Pages;
use App\Models\ImportRow;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class ImportRowResource extends Resource
{
    protected static ?string $model = ImportRow::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Manejador de Datos';

    protected static ?string $navigationLabel = 'Filas Importadas';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'fila importada';

    protected static ?string $pluralModelLabel = 'filas importadas';

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
                Select::make('import_file_id')
                    ->label('Archivo')
                    ->relationship('file', 'original_name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('row_number')
                    ->label('Número de fila')
                    ->integer()
                    ->minValue(0)
                    ->unique(
                        table: 'import_rows',
                        column: 'row_number',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                            ->where('import_file_id', $get('import_file_id')),
                    )
                    ->required(),
                Select::make('status')
                    ->label('Estado')
                    ->options([
                        'pending' => 'Pendiente',
                        'valid' => 'Válida',
                        'invalid' => 'Inválida',
                        'processed' => 'Procesada',
                    ])
                    ->default('pending')
                    ->required(),
                TextInput::make('row_hash')->label('Hash de fila')->maxLength(255),
                KeyValue::make('raw_data')->label('Datos crudos')->required()->columnSpanFull(),
                KeyValue::make('normalized_data')->label('Datos normalizados')->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('batch.name')->label('Lote')->searchable(),
                TextColumn::make('file.original_name')->label('Archivo')->searchable(),
                TextColumn::make('row_number')->label('Fila')->numeric()->sortable(),
                TextColumn::make('status')->label('Estado')->badge()->searchable()->sortable(),
                TextColumn::make('row_hash')->label('Hash')->searchable()->limit(20),
                TextColumn::make('created_at')->label('Creada')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'pending' => 'Pendiente',
                        'valid' => 'Válida',
                        'invalid' => 'Inválida',
                        'processed' => 'Procesada',
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
            'index' => Pages\ListImportRows::route('/'),
            'create' => Pages\CreateImportRow::route('/create'),
            'view' => Pages\ViewImportRow::route('/{record}'),
            'edit' => Pages\EditImportRow::route('/{record}/edit'),
        ];
    }
}
