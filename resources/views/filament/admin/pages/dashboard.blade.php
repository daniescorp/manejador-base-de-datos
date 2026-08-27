<x-filament-panels::page>
    <section class="process-home-intro" aria-labelledby="process-home-heading">
        <div>
            <p class="process-home-eyebrow">Gestor de Exportación para Marketing</p>
            <h2 id="process-home-heading">Elegí el proceso que querés gestionar</h2>
            <p>
                Accesos rápidos a la base de datos, catálogo, promociones, diccionario y exportaciones.
            </p>
        </div>

        <div class="process-home-legend" aria-label="Referencia de estados">
            <span><i class="process-status-dot process-status-dot-ok"></i>OK</span>
            <span><i class="process-status-dot process-status-dot-review"></i>Revisión</span>
            <span><i class="process-status-dot process-status-dot-blocked"></i>Bloqueado</span>
        </div>
    </section>

    <div class="process-home-grid">
        @foreach ($this->getQuickLinks() as $card)
            <a class="process-home-card" href="{{ $card['url'] }}">
                <div class="process-home-card-topline">
                    <span class="process-home-card-icon" aria-hidden="true">
                        <x-filament::icon :icon="$card['icon']" />
                    </span>

                    <span @class([
                        'process-status',
                        'process-status-ok' => $card['tone'] === 'ok',
                        'process-status-review' => $card['tone'] === 'review',
                        'process-status-blocked' => $card['tone'] === 'blocked',
                    ])>
                        {{ $card['status'] }}
                    </span>
                </div>

                <div>
                    <h3>{{ $card['title'] }}</h3>
                    <p>{{ $card['description'] }}</p>
                </div>

                <span class="process-home-card-link">
                    Abrir
                    <x-filament::icon icon="heroicon-m-arrow-right" aria-hidden="true" />
                </span>
            </a>
        @endforeach
    </div>

    <p class="process-home-note">
        Los estados son orientativos para esta primera organización visual. Esta pantalla no ejecuta procesos.
    </p>
</x-filament-panels::page>
