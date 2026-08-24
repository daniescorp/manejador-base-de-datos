<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\MasterProductResource\Pages;
use App\Filament\Admin\Resources\MasterProductResource\RelationManagers\ProductChangeLogsRelationManager;
use App\Models\MasterProduct;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MasterProductResource extends Resource
{
    private const SEARCH_COLUMNS = [
        'codigo_producto',
        'codigo_original',
        'sku_original',
        'ean_original',
        'nombre_original',
        'descripcion_catalogo',
        'marca_original',
        'marca_homologada',
        'categoria_original',
        'grupo_original',
        'familia_original',
        'estado_homologacion',
    ];

    protected static ?string $model = MasterProduct::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Manejador de Datos';

    protected static ?string $navigationLabel = 'Productos Maestros';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'producto maestro';

    protected static ?string $pluralModelLabel = 'productos maestros';

    protected static ?string $recordTitleAttribute = 'codigo_producto';

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificación')
                    ->schema([
                        TextEntry::make('codigo_producto')->label('Código de producto'),
                        TextEntry::make('codigo_original')->label('Código original')->placeholder('—'),
                        TextEntry::make('sku_original')->label('SKU original')->placeholder('—'),
                        TextEntry::make('ean_original')->label('EAN original')->placeholder('—'),
                        TextEntry::make('ean_validado')->label('EAN validado')->placeholder('—'),
                        TextEntry::make('status')->label('Estado operativo')->badge(),
                    ])
                    ->columns(3),
                Section::make('Descripciones')
                    ->schema([
                        TextEntry::make('nombre_original')->label('Nombre original')->columnSpanFull(),
                        TextEntry::make('nombre_sin_marca')->label('Nombre sin marca')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('nombre_homologado')->label('Nombre homologado')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('descripcion_catalogo')->label('Descripción de catálogo')->columnSpanFull(),
                        TextEntry::make('titulo_shopify')->label('Título Shopify')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('descripcion_app')->label('Descripción app')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('descripcion_interna')->label('Descripción interna')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Marca')
                    ->schema([
                        TextEntry::make('marca_original')->label('Marca original')->placeholder('—'),
                        TextEntry::make('marca_homologada')->label('Marca homologada')->placeholder('—'),
                        IconEntry::make('marca_detectada_en_nombre')->label('Detectada en nombre')->boolean(),
                        TextEntry::make('marca_inferida')->label('Marca inferida')->placeholder('—'),
                        IconEntry::make('requiere_revision_marca')->label('Requiere revisión de marca')->boolean(),
                        TextEntry::make('nivel_confianza_marca')->label('Confianza de marca')->placeholder('—')->badge(),
                    ])
                    ->columns(3),
                Section::make('Clasificación')
                    ->schema([
                        TextEntry::make('categoria_original')->label('Categoría original')->placeholder('—'),
                        TextEntry::make('categoria_homologada')->label('Categoría homologada')->placeholder('—'),
                        TextEntry::make('grupo_original')->label('Grupo original')->placeholder('—'),
                        TextEntry::make('grupo_homologado')->label('Grupo homologado')->placeholder('—'),
                        TextEntry::make('familia_original')->label('Familia original')->placeholder('—'),
                        TextEntry::make('familia_homologada')->label('Familia homologada')->placeholder('—'),
                    ])
                    ->columns(3),
                Section::make('Medidas y UXB')
                    ->schema([
                        TextEntry::make('medida_original')->label('Medida original')->placeholder('—'),
                        TextEntry::make('contenido_valor')->label('Contenido')->placeholder('—'),
                        TextEntry::make('unidad_original')->label('Unidad original')->placeholder('—'),
                        TextEntry::make('unidad_normalizada')->label('Unidad normalizada')->placeholder('—'),
                        TextEntry::make('cantidad_unidades')->label('Cantidad de unidades')->placeholder('—'),
                        TextEntry::make('medida_valor')->label('Valor de medida')->placeholder('—'),
                        TextEntry::make('medida_catalogo')->label('Medida de catálogo')->placeholder('—'),
                        IconEntry::make('medida_requiere_revision')->label('Revisión de medida')->boolean(),
                        TextEntry::make('uxb_original')->label('UXB original')->placeholder('—'),
                        TextEntry::make('uxb_validado')->label('UXB validado')->placeholder('—'),
                        IconEntry::make('uxb_requiere_revision')->label('Revisión de UXB')->boolean(),
                    ])
                    ->columns(3),
                Section::make('Control')
                    ->schema([
                        TextEntry::make('estado_homologacion')->label('Estado de homologación')->badge()->placeholder('—'),
                        IconEntry::make('requiere_revision')->label('Requiere revisión')->boolean(),
                        TextEntry::make('observaciones')->label('Observaciones')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('approvedBy.name')->label('Aprobado por')->placeholder('—'),
                        TextEntry::make('approved_by_id')->label('ID del aprobador')->placeholder('—'),
                        TextEntry::make('approved_at')->label('Aprobado')->dateTime()->placeholder('—'),
                        TextEntry::make('last_import_batch_id')->label('Último lote')->placeholder('—'),
                        TextEntry::make('created_at')->label('Creado')->dateTime(),
                        TextEntry::make('updated_at')->label('Actualizado')->dateTime(),
                    ])
                    ->columns(3),
                Section::make('Data JSON')
                    ->schema([
                        TextEntry::make('data_json')
                            ->label('Datos flexibles')
                            ->state(fn (MasterProduct $record): string => self::readableJson($record->data))
                            ->extraAttributes(['class' => 'whitespace-pre-wrap font-mono'])
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('codigo_producto')
                    ->label('Código')
                    ->searchable(self::SEARCH_COLUMNS)
                    ->sortable(),
                TextColumn::make('descripcion_catalogo')->label('Descripción de catálogo')->limit(45)->wrap(),
                TextColumn::make('marca_homologada')->label('Marca homologada')->sortable(),
                TextColumn::make('marca_original')->label('Marca original')->toggleable(),
                TextColumn::make('categoria_original')->label('Categoría')->toggleable(),
                TextColumn::make('grupo_original')->label('Grupo')->toggleable(),
                TextColumn::make('familia_original')->label('Familia')->toggleable(),
                TextColumn::make('ean_original')->label('EAN')->toggleable(),
                TextColumn::make('uxb_original')->label('UXB')->toggleable(),
                TextColumn::make('estado_homologacion')->label('Homologación')->badge()->sortable(),
                IconColumn::make('requiere_revision')->label('Revisión')->boolean()->sortable(),
                TextColumn::make('approved_at')->label('Aprobado')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('approvedBy.name')->label('Aprobado por')->placeholder('—')->toggleable(),
                TextColumn::make('approved_by_id')->label('ID aprobador')->placeholder('—')->toggleable(),
                TextColumn::make('updated_at')->label('Actualizado')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('estado_homologacion')
                    ->label('Estado de homologación')
                    ->options(fn (): array => self::distinctOptions('estado_homologacion')),
                TernaryFilter::make('requiere_revision')->label('Requiere revisión'),
                SelectFilter::make('marca_homologada')
                    ->label('Marca homologada')
                    ->options(fn (): array => self::distinctOptions('marca_homologada'))
                    ->searchable(),
                SelectFilter::make('categoria_original')
                    ->label('Categoría original')
                    ->options(fn (): array => self::distinctOptions('categoria_original'))
                    ->searchable(),
                Filter::make('has_approval')
                    ->label('Tiene aprobación')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('approved_at')),
                Filter::make('missing_ean')
                    ->label('Sin EAN')
                    ->query(fn (Builder $query): Builder => $query->where(
                        fn (Builder $missing): Builder => $missing
                            ->whereNull('ean_original')
                            ->orWhere('ean_original', ''),
                    )),
                Filter::make('missing_homologated_brand')
                    ->label('Sin marca homologada')
                    ->query(fn (Builder $query): Builder => $query->where(
                        fn (Builder $missing): Builder => $missing
                            ->whereNull('marca_homologada')
                            ->orWhere('marca_homologada', ''),
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [
            ProductChangeLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMasterProducts::route('/'),
            'view' => Pages\ViewMasterProduct::route('/{record}'),
        ];
    }

    private static function readableJson(?array $value): string
    {
        return json_encode(
            $value ?? [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) ?: '{}';
    }

    /**
     * @return array<string, string>
     */
    private static function distinctOptions(string $column): array
    {
        return MasterProduct::query()
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->all();
    }
}
