<?php

namespace App\Filament\Admin\Pages;

class DiagnosticoPromociones extends DiagnosticoArchivosExternos
{
    protected static ?string $navigationLabel = 'Diagnóstico de Promociones';

    protected static ?string $title = 'Diagnóstico de Promociones';

    protected static ?string $slug = 'diagnostico-promociones';

    protected static string|\UnitEnum|null $navigationGroup = 'Procesos de Promociones';

    protected function workflow(): string
    {
        return 'promo_tapa';
    }

    public function workflowLabel(): string
    {
        return 'Promociones / TAPA AMBA';
    }

    public function workflowDescription(): string
    {
        return 'Detecta VARIOS, códigos compuestos, códigos incompletos y bloqueos.';
    }

    public function uploadHelperText(): string
    {
        return 'Subí un TXT o Excel de promociones / TAPA AMBA.';
    }

    public function diagnoseButtonLabel(): string
    {
        return 'Diagnosticar promoción';
    }

    protected function exportFilePrefix(): string
    {
        return 'promociones';
    }
}
