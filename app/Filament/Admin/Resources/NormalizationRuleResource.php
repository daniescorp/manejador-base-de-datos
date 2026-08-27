<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\NormalizationRuleResource\Pages;
use App\Models\NormalizationRule;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class NormalizationRuleResource extends Resource
{
    public const APPLIES_TO_FIELD_OPTIONS = [
        'descripcion_catalogo' => 'Descripción de catálogo',
        'marca_homologada' => 'Marca homologada',
        'nombre_homologado' => 'Nombre homologado',
        'titulo_shopify' => 'Título de Shopify',
        'descripcion_app' => 'Descripción de aplicación',
    ];

    public const RULE_TYPE_OPTIONS = [
        'accent' => 'Acentuación',
        'abbreviation' => 'Abreviatura',
        'slash_abbreviation' => 'Abreviatura con barra',
        'contextual_abbreviation' => 'Abreviatura contextual',
        'dotted_abbreviation' => 'Abreviatura con punto',
        'flavor_variant' => 'Variante de sabor',
        'measurement' => 'Medida',
        'manual_review' => 'Revisión manual',
        'no_change' => 'Conservar sin cambios',
        'category_word_replacement' => 'Reemplazo de palabra de categoría',
        'brand_normalization' => 'Normalización de marca',
    ];

    public const CONFIDENCE_LEVEL_OPTIONS = [
        'high' => 'Alta',
        'medium' => 'Media',
        'low' => 'Baja',
        'contextual' => 'Contextual',
        'blocked' => 'Bloqueada',
        'confirmed_no_change' => 'Sin cambio confirmado',
    ];

    protected static ?string $model = NormalizationRule::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static string|\UnitEnum|null $navigationGroup = 'Diccionario';

    protected static ?string $navigationLabel = 'Reglas de Normalización';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Regla de Normalización';

    protected static ?string $pluralModelLabel = 'Diccionario de Normalización';

    protected static ?string $recordTitleAttribute = 'rule_name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('rule_name')
                    ->label('Nombre de la regla')
                    ->required()
                    ->maxLength(255),
                TextInput::make('detected_value')
                    ->label('Valor detectado')
                    ->required()
                    ->maxLength(255),
                TextInput::make('replacement_value')
                    ->label('Valor de reemplazo')
                    ->maxLength(255),
                Select::make('rule_type')
                    ->label('Tipo de regla')
                    ->options(self::RULE_TYPE_OPTIONS)
                    ->required()
                    ->searchable(),
                Select::make('applies_to_field')
                    ->label('Campo de aplicación')
                    ->options(self::APPLIES_TO_FIELD_OPTIONS)
                    ->searchable(),
                TextInput::make('context')
                    ->label('Contexto')
                    ->maxLength(255),
                TextInput::make('priority')
                    ->label('Prioridad')
                    ->integer()
                    ->minValue(0)
                    ->default(100)
                    ->required(),
                Select::make('confidence_level')
                    ->label('Nivel de confianza')
                    ->options(self::CONFIDENCE_LEVEL_OPTIONS),
                Toggle::make('is_automatic')
                    ->label('Aplicación automática')
                    ->default(false),
                Toggle::make('requires_preview')
                    ->label('Requiere previsualización')
                    ->default(true),
                Toggle::make('requires_review')
                    ->label('Requiere revisión')
                    ->default(false),
                Toggle::make('active')
                    ->label('Activa')
                    ->default(true),
                Textarea::make('notes')
                    ->label('Notas')
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('detected_value')
                    ->label('Valor detectado')
                    ->searchable(['detected_value', 'rule_name', 'context', 'notes'])
                    ->sortable(),
                TextColumn::make('replacement_value')
                    ->label('Reemplazo')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('applies_to_field')
                    ->label('Campo')
                    ->badge()
                    ->sortable(),
                TextColumn::make('rule_type')
                    ->label('Tipo')
                    ->badge()
                    ->sortable(),
                TextColumn::make('context')
                    ->label('Contexto')
                    ->searchable()
                    ->limit(30)
                    ->placeholder('—'),
                TextColumn::make('priority')
                    ->label('Prioridad')
                    ->sortable(),
                IconColumn::make('is_automatic')
                    ->label('Automática')
                    ->boolean(),
                IconColumn::make('requires_review')
                    ->label('Revisión')
                    ->boolean(),
                IconColumn::make('active')
                    ->label('Activa')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Actualizada')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('applies_to_field')
                    ->label('Campo de aplicación')
                    ->options(self::APPLIES_TO_FIELD_OPTIONS),
                SelectFilter::make('rule_type')
                    ->label('Tipo de regla')
                    ->options(self::RULE_TYPE_OPTIONS),
                TernaryFilter::make('active')
                    ->label('Estado')
                    ->trueLabel('Activas')
                    ->falseLabel('Inactivas'),
                TernaryFilter::make('requires_review')
                    ->label('Requiere revisión'),
                TernaryFilter::make('is_automatic')
                    ->label('Aplicación automática'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderBy('priority')
                ->orderBy('detected_value'));
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNormalizationRules::route('/'),
            'create' => Pages\CreateNormalizationRule::route('/create'),
            'view' => Pages\ViewNormalizationRule::route('/{record}'),
            'edit' => Pages\EditNormalizationRule::route('/{record}/edit'),
        ];
    }
}
