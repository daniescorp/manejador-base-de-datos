<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\MasterProductResource\Pages;
use App\Models\MasterProduct;
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

class MasterProductResource extends Resource
{
    protected static ?string $model = MasterProduct::class;

    protected static string | \UnitEnum | null $navigationGroup = 'Manejador de Datos';

    protected static ?string $navigationLabel = 'Productos Maestros';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'producto maestro';

    protected static ?string $pluralModelLabel = 'productos maestros';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('sku')->label('SKU')->maxLength(255)->unique(ignoreRecord: true),
                TextInput::make('barcode')->label('Código de barras')->maxLength(255),
                TextInput::make('name')->label('Nombre')->maxLength(255),
                TextInput::make('brand')->label('Marca')->maxLength(255),
                TextInput::make('category')->label('Categoría')->maxLength(255),
                Select::make('status')
                    ->label('Estado')
                    ->options([
                        'active' => 'Activo',
                        'inactive' => 'Inactivo',
                        'pending' => 'Pendiente',
                    ])
                    ->default('active')
                    ->required(),
                TextInput::make('source_reference')->label('Referencia de origen')->maxLength(255),
                Select::make('last_import_batch_id')
                    ->label('Último lote de importación')
                    ->relationship('lastImportBatch', 'name')
                    ->searchable()
                    ->preload(),
                KeyValue::make('data')->label('Datos flexibles')->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('sku')->label('SKU')->searchable()->sortable(),
                TextColumn::make('barcode')->label('Código de barras')->searchable(),
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('brand')->label('Marca')->searchable(),
                TextColumn::make('category')->label('Categoría')->searchable(),
                TextColumn::make('status')->label('Estado')->badge()->sortable(),
                TextColumn::make('updated_at')->label('Actualizado')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'active' => 'Activo',
                        'inactive' => 'Inactivo',
                        'pending' => 'Pendiente',
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
            'index' => Pages\ListMasterProducts::route('/'),
            'create' => Pages\CreateMasterProduct::route('/create'),
            'view' => Pages\ViewMasterProduct::route('/{record}'),
            'edit' => Pages\EditMasterProduct::route('/{record}/edit'),
        ];
    }
}
