<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ProductStagingRowResource\Pages;
use App\Filament\Admin\Resources\ProductStagingRowResource\RelationManagers\NormalizationSuggestionsRelationManager;
use App\Models\ProductStagingRow;
use App\Services\Products\ProductImageLocator;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductStagingRowResource extends Resource
{
    private const DIRECT_SEARCH_COLUMNS = [
        'codigo_producto_original',
        'nombre_sku_original',
        'marca_original',
        'categoria_original',
        'grupo_original',
        'familia_original',
        'ean_original',
        'uxb_original',
        'review_reason',
    ];

    private const PREVIEW_SEARCH_COLUMNS = [
        'normalized_preview->descripcion_catalogo',
        'normalized_preview->marca_homologada',
        'normalized_preview->source_text',
        'normalized_preview->source_brand',
        'normalized_preview->fields->descripcion_catalogo->value',
        'normalized_preview->fields->marca_homologada->value',
        'normalized_preview->fields->descripcion_catalogo->preview',
        'normalized_preview->fields->marca_homologada->preview',
    ];

    private const SUGGESTION_SEARCH_COLUMNS = [
        'field_name',
        'original_value',
        'suggested_value',
        'suggestion_reason',
        'confidence_level',
        'status',
    ];

    private const RULE_SEARCH_COLUMNS = [
        'rule_name',
        'detected_value',
        'replacement_value',
        'rule_type',
        'context',
        'notes',
    ];

    public const STATUS_OPTIONS = [
        'pending' => 'Pendiente',
        'analyzed' => 'Analizado',
        'suggested' => 'Con sugerencias',
        'previewed' => 'Previsualizado',
        'requires_review' => 'Requiere revisión',
        'approved' => 'Aprobado',
    ];

    protected static ?string $model = ProductStagingRow::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Base de Datos';

    protected static ?string $navigationLabel = 'Revisión de Productos Importados';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Producto Importado';

    protected static ?string $pluralModelLabel = 'Revisión de Productos Importados';

    protected static ?string $recordTitleAttribute = 'codigo_producto_original';

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Datos originales')
                    ->schema([
                        TextEntry::make('codigo_producto_original')->label('Código'),
                        TextEntry::make('nombre_sku_original')->label('Nombre SKU')->columnSpanFull(),
                        TextEntry::make('uxb_original')->label('UXB'),
                        TextEntry::make('ean_original')->label('EAN'),
                        TextEntry::make('categoria_original')->label('Categoría'),
                        TextEntry::make('grupo_original')->label('Grupo'),
                        TextEntry::make('familia_original')->label('Familia'),
                        TextEntry::make('marca_original')->label('Marca original'),
                        TextEntry::make('raw_data_json')
                            ->label('Datos originales completos')
                            ->state(fn (ProductStagingRow $record): string => self::readableJson(
                                $record->raw_data,
                            ))
                            ->extraAttributes(['class' => 'whitespace-pre-wrap font-mono'])
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Estado de revisión')
                    ->schema([
                        TextEntry::make('status')->label('Estado')->badge(),
                        IconEntry::make('requires_review')->label('Requiere revisión')->boolean(),
                        TextEntry::make('review_reason')->label('Motivo de revisión')->columnSpanFull(),
                        TextEntry::make('analyzed_at')->label('Analizado')->dateTime(),
                        TextEntry::make('approved_at')->label('Aprobado')->dateTime()->placeholder('—'),
                        TextEntry::make('approved_by_id')->label('Aprobado por')->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Preview normalizado')
                    ->schema([
                        TextEntry::make('preview_description')
                            ->label('Descripción de catálogo')
                            ->state(fn (ProductStagingRow $record): ?string => self::previewValue(
                                $record,
                                'descripcion_catalogo',
                            ))
                            ->columnSpanFull(),
                        TextEntry::make('preview_brand')
                            ->label('Marca homologada')
                            ->state(fn (ProductStagingRow $record): ?string => self::previewValue(
                                $record,
                                'marca_homologada',
                            )),
                        TextEntry::make('preview_source_brand')
                            ->label('Marca fuente del preview')
                            ->state(fn (ProductStagingRow $record): ?string => data_get(
                                $record->normalized_preview,
                                'source_brand',
                            )),
                        TextEntry::make('normalized_preview_json')
                            ->label('Preview normalizado completo')
                            ->state(fn (ProductStagingRow $record): string => self::readableJson(
                                $record->normalized_preview,
                            ))
                            ->extraAttributes(['class' => 'whitespace-pre-wrap font-mono'])
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Imagen del producto')
                    ->schema([
                        TextEntry::make('product_image_code')
                            ->label('Código utilizado')
                            ->state(fn (ProductStagingRow $record): string => trim(
                                (string) $record->codigo_producto_original,
                            ))
                            ->placeholder('Código inválido'),
                        TextEntry::make('product_image_filename')
                            ->label('Archivo esperado')
                            ->state(fn (ProductStagingRow $record): ?string => self::productImageState(
                                $record,
                            )['filename']),
                        TextEntry::make('product_image_status')
                            ->label('Estado')
                            ->state(fn (ProductStagingRow $record): string => self::productImageState(
                                $record,
                            )['status'])
                            ->badge(),
                        ViewEntry::make('product_image_preview')
                            ->label('Miniatura')
                            ->state(fn (ProductStagingRow $record): array => self::productImageState($record))
                            ->view('filament.infolists.components.product-image')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make('Sugerencias asociadas')
                    ->schema([
                        TextEntry::make('suggestions_count')
                            ->label('Cantidad de sugerencias')
                            ->state(fn (ProductStagingRow $record): int => $record->suggestions()->count()),
                        TextEntry::make('suggestion_fields')
                            ->label('Campos sugeridos')
                            ->state(fn (ProductStagingRow $record): string => $record->suggestions()
                                ->distinct()
                                ->orderBy('field_name')
                                ->pluck('field_name')
                                ->implode(', '))
                            ->placeholder('Sin sugerencias'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ViewColumn::make('product_image')
                    ->label('Imagen')
                    ->state(fn (ProductStagingRow $record): array => self::productImageState($record))
                    ->view('filament.tables.columns.product-image'),
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('import_batch_id')->label('Lote')->sortable(),
                TextColumn::make('codigo_producto_original')->label('Código')->searchable()->sortable(),
                TextColumn::make('nombre_sku_original')->label('Nombre SKU')->searchable()->limit(45),
                TextColumn::make('marca_original')->label('Marca')->searchable()->sortable(),
                TextColumn::make('categoria_original')->label('Categoría')->sortable()->toggleable(),
                TextColumn::make('grupo_original')->label('Grupo')->sortable()->toggleable(),
                TextColumn::make('familia_original')->label('Familia')->sortable()->toggleable(),
                TextColumn::make('uxb_original')->label('UXB')->toggleable(),
                TextColumn::make('ean_original')->label('EAN')->searchable()->toggleable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'previewed' => 'success',
                        'requires_review' => 'danger',
                        'suggested' => 'warning',
                        'analyzed' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                IconColumn::make('requires_review')
                    ->label('Revisión')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('review_reason')
                    ->label('Motivo')
                    ->searchable()
                    ->limit(45)
                    ->toggleable(),
                TextColumn::make('analyzed_at')->label('Analizado')->dateTime()->sortable()->toggleable(),
                TextColumn::make('preview_description')
                    ->label('Preview descripción')
                    ->state(fn (ProductStagingRow $record): ?string => self::previewValue(
                        $record,
                        'descripcion_catalogo',
                    ))
                    ->limit(45),
                TextColumn::make('preview_brand')
                    ->label('Preview marca')
                    ->state(fn (ProductStagingRow $record): ?string => self::previewValue(
                        $record,
                        'marca_homologada',
                    ))
                    ->toggleable(),
                TextColumn::make('suggestions_count')
                    ->label('Sugerencias')
                    ->counts('suggestions')
                    ->sortable(),
            ])
            ->searchUsing(fn (Builder $query, string $search): Builder => self::applyTableSearch(
                $query,
                $search,
            ))
            ->filters([
                SelectFilter::make('import_batch_id')->label('Lote')->relationship('batch', 'name'),
                SelectFilter::make('status')->label('Estado')->options(self::STATUS_OPTIONS),
                TernaryFilter::make('requires_review')->label('Requiere revisión'),
                SelectFilter::make('marca_original')
                    ->label('Marca original')
                    ->options(fn (): array => self::distinctOptions('marca_original'))
                    ->searchable(),
                SelectFilter::make('categoria_original')
                    ->label('Categoría')
                    ->options(fn (): array => self::distinctOptions('categoria_original'))
                    ->searchable(),
                SelectFilter::make('grupo_original')
                    ->label('Grupo')
                    ->options(fn (): array => self::distinctOptions('grupo_original'))
                    ->searchable(),
                SelectFilter::make('familia_original')
                    ->label('Familia')
                    ->options(fn (): array => self::distinctOptions('familia_original'))
                    ->searchable(),
                Filter::make('has_preview')
                    ->label('Tiene preview normalizado')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('normalized_preview')),
                Filter::make('has_suggestions')
                    ->label('Tiene sugerencias')
                    ->query(fn (Builder $query): Builder => $query->whereHas('suggestions')),
                Filter::make('has_brand_suggestion')
                    ->label('Tiene marca homologada sugerida')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'suggestions',
                        fn (Builder $suggestions): Builder => $suggestions
                            ->where('field_name', 'marca_homologada'),
                    )),
                Filter::make('duplicate_sku')
                    ->label('Motivo contiene SKU duplicado')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('review_reason', 'like', '%SKU duplicado%')),
                Filter::make('ean_review')
                    ->label('Motivo contiene EAN')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('review_reason', 'like', '%EAN%')),
                Filter::make('brand_review')
                    ->label('Motivo contiene Marca')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('review_reason', 'like', '%Marca%')),
                Filter::make('mx_token')
                    ->label('Token MX independiente')
                    ->query(fn (Builder $query): Builder => $query->whereRaw(
                        "nombre_sku_original REGEXP '(^|[^[:alnum:]_])MX([^[:alnum:]_]|$)'",
                    )),
                Filter::make('arlistan')
                    ->label('Marca ARLISTAN')
                    ->query(fn (Builder $query): Builder => $query->where('marca_original', 'ARLISTAN')),
                Filter::make('manon')
                    ->label('Marca MANON')
                    ->query(fn (Builder $query): Builder => $query->where('marca_original', 'MANON')),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('requires_review')
                ->orderBy('status')
                ->orderBy('id'));
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

    public static function getRelations(): array
    {
        return [
            NormalizationSuggestionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductStagingRows::route('/'),
            'view' => Pages\ViewProductStagingRow::route('/{record}'),
        ];
    }

    private static function previewValue(ProductStagingRow $row, string $field): ?string
    {
        $preview = $row->normalized_preview;

        if (! is_array($preview)) {
            return null;
        }

        $value = data_get($preview, $field)
            ?? data_get($preview, "fields.{$field}.preview");

        return is_scalar($value) ? (string) $value : null;
    }

    private static function applyTableSearch(Builder $query, string $search): Builder
    {
        $pattern = "%{$search}%";

        return $query->where(function (Builder $searchQuery) use ($pattern): void {
            foreach (self::DIRECT_SEARCH_COLUMNS as $index => $column) {
                $searchQuery->{$index === 0 ? 'where' : 'orWhere'}($column, 'like', $pattern);
            }

            foreach (self::PREVIEW_SEARCH_COLUMNS as $column) {
                $searchQuery->orWhere($column, 'like', $pattern);
            }

            $searchQuery->orWhereHas('suggestions', function (Builder $suggestions) use ($pattern): void {
                $suggestions->where(function (Builder $suggestionSearch) use ($pattern): void {
                    foreach (self::SUGGESTION_SEARCH_COLUMNS as $index => $column) {
                        $suggestionSearch->{$index === 0 ? 'where' : 'orWhere'}($column, 'like', $pattern);
                    }

                    $suggestionSearch->orWhereHas('rule', function (Builder $rules) use ($pattern): void {
                        foreach (self::RULE_SEARCH_COLUMNS as $index => $column) {
                            $rules->{$index === 0 ? 'where' : 'orWhere'}($column, 'like', $pattern);
                        }
                    });
                });
            });
        });
    }

    private static function readableJson(?array $value): string
    {
        return json_encode(
            $value ?? [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) ?: '{}';
    }

    /**
     * @return array{status: string, label: string, code: string|null, filename: string|null, url: string|null}
     */
    private static function productImageState(ProductStagingRow $row): array
    {
        $image = app(ProductImageLocator::class)->findByCode($row->codigo_producto_original);

        return [
            'status' => $image['status'],
            'label' => match ($image['status']) {
                'found' => 'Imagen disponible',
                'missing' => 'Sin imagen',
                'not_configured' => 'No configurado',
                default => 'Código inválido',
            },
            'code' => $image['code'],
            'filename' => $image['filename'],
            'url' => $image['status'] === 'found' && $image['code'] !== null
                ? route('filament.admin.product-images.show', ['code' => $image['code']])
                : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function distinctOptions(string $column): array
    {
        return ProductStagingRow::query()
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->all();
    }
}
