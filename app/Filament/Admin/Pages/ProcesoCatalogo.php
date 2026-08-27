<?php

namespace App\Filament\Admin\Pages;

class ProcesoCatalogo extends PendingProcessPage
{
    protected static ?string $navigationLabel = 'Importar / Previsualizar / Exportar Catálogo';

    protected static ?string $title = 'Proceso de Catálogo';

    protected static ?string $slug = 'proceso-catalogo';

    protected static string | \UnitEnum | null $navigationGroup = 'Procesos de Catálogo';

    protected static ?int $navigationSort = 20;

    protected static string $processDescription = 'Flujo visual integrado para importar, previsualizar y exportar el catálogo.';
}
