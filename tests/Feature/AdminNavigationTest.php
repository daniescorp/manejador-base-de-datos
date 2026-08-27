<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\DiagnosticoArchivosExternos;
use App\Filament\Admin\Pages\DiagnosticoPromociones;
use App\Models\User;
use Dotenv\Dotenv;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class AdminNavigationTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql'];

    protected function setUpTraits(): array
    {
        $environmentFile = base_path('.env');

        if (! is_file($environmentFile)) {
            throw new RuntimeException('A MySQL database configuration is required to run the UI tests.');
        }

        $environment = Dotenv::parse(file_get_contents($environmentFile));
        $database = $environment['DB_DATABASE'] ?? null;

        if (blank($database) || ($database === ':memory:')) {
            throw new RuntimeException('A persistent MySQL database name is required to run the UI tests.');
        }

        config()->set([
            'app.env' => 'local',
            'database.default' => 'mysql',
            'database.connections.mysql.database' => $database,
        ]);

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();

        $this->actingAs(User::factory()->create());
    }

    public function test_the_admin_home_shows_the_process_shortcuts(): void
    {
        $this->get('/admin')
            ->assertSuccessful()
            ->assertSee('Elegí el proceso que querés gestionar')
            ->assertSee('Base de Datos')
            ->assertSee('Productos Maestros')
            ->assertSee('Importación')
            ->assertSee('Exportar TXT para Catálogo')
            ->assertSee('Exportar para Promociones')
            ->assertSee('Diccionario')
            ->assertSee('Diagnóstico de Archivos Externos');
    }

    public function test_the_admin_navigation_is_grouped_by_process(): void
    {
        $itemsByGroup = collect(Filament::getNavigation())
            ->mapWithKeys(fn (NavigationGroup $group): array => [
                $group->getLabel() ?? 'Home' => collect($group->getItems())
                    ->map(fn (NavigationItem $item): string => $item->getLabel())
                    ->values()
                    ->all(),
            ]);

        $this->assertSame(['Escritorio'], $itemsByGroup->get('Home'));
        $this->assertSame([
            'Productos Maestros',
            'Revisión de Productos Importados',
            'Lotes de Importación',
            'Archivos Importados',
            'Filas Importadas',
            'Errores de Validación',
        ], $itemsByGroup->get('Base de Datos'));
        $this->assertSame([
            'Diagnóstico de Archivos Externos',
            'Importar / Previsualizar / Exportar Catálogo',
            'Exportar TXT para Catálogo',
        ], $itemsByGroup->get('Procesos de Catálogo'));
        $this->assertSame([
            'Diagnóstico de Promociones',
            'Importar / Previsualizar / Exportar Promociones',
            'Exportar TXT para Promociones',
        ], $itemsByGroup->get('Procesos de Promociones'));
        $this->assertSame([
            'Reglas de Normalización',
            'Crear Regla',
        ], $itemsByGroup->get('Diccionario'));
        $this->assertFalse($itemsByGroup->has('Exportaciones'));
    }

    public function test_catalog_and_promotions_have_separate_diagnosis_pages(): void
    {
        $this->get(DiagnosticoArchivosExternos::getUrl())
            ->assertSuccessful()
            ->assertSee('Catálogo cuerpo general')
            ->assertSee('Diagnosticar catálogo')
            ->assertDontSee('Workflow')
            ->assertSee('Importar archivo')
            ->assertSee('Diagnosticar')
            ->assertSee('Previsualizar')
            ->assertSee('Exportar')
            ->assertSee('Esperando un archivo');

        $this->get(DiagnosticoPromociones::getUrl())
            ->assertSuccessful()
            ->assertSee('Promociones / TAPA AMBA')
            ->assertSee('Diagnosticar promoción')
            ->assertDontSee('Workflow')
            ->assertSee('Importar archivo')
            ->assertSee('Diagnosticar')
            ->assertSee('Previsualizar')
            ->assertSee('Exportar')
            ->assertSee('Esperando un archivo');
    }
}
