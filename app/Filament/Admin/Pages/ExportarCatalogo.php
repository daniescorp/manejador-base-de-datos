<?php

namespace App\Filament\Admin\Pages;

class ExportarCatalogo extends PendingProcessPage
{
    protected static ?string $navigationLabel = 'Exportar TXT para Catálogo';

    protected static ?string $title = 'Exportar TXT para Catálogo';

    protected static ?string $slug = 'exportar-txt-catalogo';

    protected static string | \UnitEnum | null $navigationGroup = 'Procesos de Catálogo';

    protected static ?int $navigationSort = 30;

    protected static string $processDescription = 'Preparación de salidas TXT para el cuerpo general del catálogo.';
}
