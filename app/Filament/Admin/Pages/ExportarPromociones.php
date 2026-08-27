<?php

namespace App\Filament\Admin\Pages;

class ExportarPromociones extends PendingProcessPage
{
    protected static ?string $navigationLabel = 'Exportar TXT para Promociones';

    protected static ?string $title = 'Exportar TXT para Promociones';

    protected static ?string $slug = 'exportar-txt-promociones';

    protected static string | \UnitEnum | null $navigationGroup = 'Procesos de Promociones';

    protected static ?int $navigationSort = 30;

    protected static string $processDescription = 'Preparación de salidas TXT para TAPA AMBA y promociones con sus validaciones.';
}
