<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\ImportBatchResource;
use App\Filament\Admin\Resources\MasterProductResource;
use App\Filament\Admin\Resources\NormalizationRuleResource;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static bool $isDiscovered = false;

    protected static ?string $navigationLabel = 'Escritorio';

    protected static ?string $title = 'Home / Escritorio';

    protected string $view = 'filament.admin.pages.dashboard';

    /**
     * @return array<int, array{
     *     title: string,
     *     description: string,
     *     status: string,
     *     tone: string,
     *     icon: string,
     *     url: string,
     * }>
     */
    public function getQuickLinks(): array
    {
        return [
            [
                'title' => 'Base de Datos',
                'description' => 'Gestionar productos maestros, staging, lotes, filas importadas y validaciones.',
                'status' => 'OK',
                'tone' => 'ok',
                'icon' => 'heroicon-o-circle-stack',
                'url' => MasterProductResource::getUrl(),
            ],
            [
                'title' => 'Productos Maestros',
                'description' => 'Catálogo limpio y aprobado para exportaciones.',
                'status' => 'OK',
                'tone' => 'ok',
                'icon' => 'heroicon-o-rectangle-stack',
                'url' => MasterProductResource::getUrl(),
            ],
            [
                'title' => 'Importación',
                'description' => 'Procesar archivos base y revisar productos importados.',
                'status' => 'Revisión',
                'tone' => 'review',
                'icon' => 'heroicon-o-arrow-down-tray',
                'url' => ImportBatchResource::getUrl(),
            ],
            [
                'title' => 'Proceso de Catálogo',
                'description' => 'Importar, previsualizar y exportar el catálogo como un flujo integrado.',
                'status' => 'Revisión',
                'tone' => 'review',
                'icon' => 'heroicon-o-arrows-right-left',
                'url' => ProcesoCatalogo::getUrl(),
            ],
            [
                'title' => 'Exportar TXT para Catálogo',
                'description' => 'Preparar salidas InDesign para catálogo cuerpo general.',
                'status' => 'Revisión',
                'tone' => 'review',
                'icon' => 'heroicon-o-document-text',
                'url' => ExportarCatalogo::getUrl(),
            ],
            [
                'title' => 'Exportar para Promociones',
                'description' => 'Preparar TAPA AMBA y promociones con validaciones.',
                'status' => 'Bloqueado',
                'tone' => 'blocked',
                'icon' => 'heroicon-o-megaphone',
                'url' => ExportarPromociones::getUrl(),
            ],
            [
                'title' => 'Diccionario',
                'description' => 'Administrar reglas de normalización, marcas, abreviaturas y excepciones.',
                'status' => 'OK',
                'tone' => 'ok',
                'icon' => 'heroicon-o-language',
                'url' => NormalizationRuleResource::getUrl(),
            ],
            [
                'title' => 'Diagnóstico de Archivos Externos',
                'description' => 'Leer XLSX/TXT, detectar warnings, bloqueos y previsualizar antes de exportar.',
                'status' => 'Revisión',
                'tone' => 'review',
                'icon' => 'heroicon-o-document-magnifying-glass',
                'url' => DiagnosticoArchivosExternos::getUrl(),
            ],
        ];
    }
}
