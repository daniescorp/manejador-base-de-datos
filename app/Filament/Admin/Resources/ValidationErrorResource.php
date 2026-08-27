<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ValidationErrorResource\Pages;
use App\Models\ValidationError;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ValidationErrorResource extends Resource
{
    protected static ?string $model = ValidationError::class;

    protected static string | \UnitEnum | null $navigationGroup = 'Base de Datos';

    protected static ?string $navigationLabel = 'Errores de Validación';

    protected static ?int $navigationSort = 60;

    protected static ?string $modelLabel = 'error de validación';

    protected static ?string $pluralModelLabel = 'errores de validación';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('import_batch_id')->label('Lote')->relationship('batch', 'name')->searchable()->preload(),
                Select::make('import_file_id')->label('Archivo')->relationship('file', 'original_name')->searchable()->preload(),
                Select::make('import_row_id')->label('Fila importada')->relationship('row', 'id')->searchable()->preload(),
                Select::make('master_product_id')->label('Producto maestro')->relationship('product', 'name')->searchable()->preload(),
                Select::make('severity')
                    ->label('Severidad')
                    ->options([
                        'info' => 'Información',
                        'warning' => 'Advertencia',
                        'error' => 'Error',
                        'critical' => 'Crítico',
                    ])
                    ->default('warning')
                    ->required(),
                TextInput::make('field_name')->label('Campo')->maxLength(255),
                TextInput::make('error_code')->label('Código de error')->maxLength(255),
                DateTimePicker::make('resolved_at')->label('Resuelto el'),
                Textarea::make('message')->label('Mensaje')->required()->columnSpanFull(),
                KeyValue::make('context')->label('Contexto')->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('severity')->label('Severidad')->badge()->sortable(),
                TextColumn::make('error_code')->label('Código')->searchable()->sortable(),
                TextColumn::make('field_name')->label('Campo')->searchable(),
                TextColumn::make('message')->label('Mensaje')->searchable()->limit(50),
                TextColumn::make('batch.name')->label('Lote')->searchable(),
                TextColumn::make('resolved_at')->label('Resuelto')->dateTime()->sortable(),
                TextColumn::make('created_at')->label('Creado')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('severity')
                    ->label('Severidad')
                    ->options([
                        'info' => 'Información',
                        'warning' => 'Advertencia',
                        'error' => 'Error',
                        'critical' => 'Crítico',
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
            'index' => Pages\ListValidationErrors::route('/'),
            'create' => Pages\CreateValidationError::route('/create'),
            'view' => Pages\ViewValidationError::route('/{record}'),
            'edit' => Pages\EditValidationError::route('/{record}/edit'),
        ];
    }
}
