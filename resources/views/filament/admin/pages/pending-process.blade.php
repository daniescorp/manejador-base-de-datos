<x-filament-panels::page>
    <section class="pending-process-card">
        <span class="process-status process-status-review">Revisión</span>

        <div class="pending-process-icon" aria-hidden="true">
            <x-filament::icon icon="heroicon-o-wrench-screwdriver" />
        </div>

        <div>
            <h2>Proceso pendiente de implementación visual</h2>
            <p class="pending-process-lead">El motor backend ya existe.</p>
            <p>{{ $this->getProcessDescription() }}</p>
        </div>

        <x-filament::button :href="\App\Filament\Admin\Pages\Dashboard::getUrl()" tag="a" color="primary">
            Volver al Escritorio
        </x-filament::button>
    </section>
</x-filament-panels::page>
