<?php

namespace App\Filament\Admin\Pages;

class ExportarIndesignTxt extends PendingProcessPage
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationLabel = 'Exportar InDesign TXT';

    protected static ?string $title = 'Exportar InDesign TXT';

    protected static ?string $slug = 'exportar-indesign-txt';

    protected static string $processDescription = 'Interfaz pendiente para el motor existente de exportación InDesign TXT.';
}
