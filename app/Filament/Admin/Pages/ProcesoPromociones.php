<?php

namespace App\Filament\Admin\Pages;

class ProcesoPromociones extends PendingProcessPage
{
    protected static ?string $navigationLabel = 'Importar / Previsualizar / Exportar Promociones';

    protected static ?string $title = 'Proceso de Promociones';

    protected static ?string $slug = 'proceso-promociones';

    protected static string | \UnitEnum | null $navigationGroup = 'Procesos de Promociones';

    protected static ?int $navigationSort = 20;

    protected static string $processDescription = 'Flujo visual integrado para importar, previsualizar y exportar promociones.';
}
