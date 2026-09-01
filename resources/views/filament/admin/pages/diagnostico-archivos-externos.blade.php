<x-filament-panels::page>
    @php
        $status = $diagnosis['status'] ?? null;
        $statusLabel = match ($status) {
            'ok' => 'OK',
            'review_required' => 'Requiere revisión',
            'blocked' => 'Bloqueado',
            default => 'Pendiente',
        };
        $statusTone = match ($status) {
            'ok' => 'ok',
            'blocked' => 'blocked',
            default => 'review',
        };
    @endphp

    <ol class="diagnosis-flow" aria-label="Flujo de diagnóstico">
        @foreach ([
            ['label' => 'Importar archivo', 'icon' => 'heroicon-o-arrow-up-tray'],
            ['label' => 'Diagnosticar', 'icon' => 'heroicon-o-magnifying-glass'],
            ['label' => 'Previsualizar', 'icon' => 'heroicon-o-table-cells'],
            ['label' => 'Exportar', 'icon' => 'heroicon-o-arrow-down-tray'],
        ] as $index => $step)
            <li @class([
                'diagnosis-flow-step',
                'diagnosis-flow-step-active' => $index < 2 || $diagnosis !== null,
            ])>
                <span class="diagnosis-flow-number">{{ $index + 1 }}</span>
                <x-filament::icon :icon="$step['icon']" aria-hidden="true" />
                <span>{{ $step['label'] }}</span>
            </li>
        @endforeach
    </ol>

    <section class="diagnosis-panel" aria-labelledby="diagnosis-upload-heading">
        <div class="diagnosis-panel-heading">
            <div>
                <p class="process-home-eyebrow">Paso 1 y 2</p>
                <h2 id="diagnosis-upload-heading">{{ $this->workflowLabel() }}</h2>
                <p>{{ $this->workflowDescription() }}</p>
                <p>El archivo se procesa en almacenamiento temporal y se elimina después del análisis.</p>
            </div>

            <span class="process-status process-status-review">Sin persistencia</span>
        </div>

        <form wire:submit="diagnose" class="diagnosis-form">
            {{ $this->form }}

            <div class="diagnosis-form-actions">
                <x-filament::button type="submit" icon="heroicon-o-magnifying-glass" wire:loading.attr="disabled" wire:target="diagnose">
                    {{ $this->diagnoseButtonLabel() }}
                </x-filament::button>

                <x-filament::button type="button" color="gray" wire:click="clearDiagnosis" wire:loading.attr="disabled">
                    Limpiar diagnóstico
                </x-filament::button>
            </div>
        </form>

        @if ($diagnosisError)
            <div class="diagnosis-alert diagnosis-alert-blocked" role="alert">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" aria-hidden="true" />
                <div>
                    <strong>No se pudo completar el diagnóstico.</strong>
                    <p>{{ $diagnosisError }}</p>
                </div>
            </div>
        @endif
    </section>

    @if ($diagnosis)
        <section aria-labelledby="diagnosis-summary-heading">
            <div class="diagnosis-section-heading">
                <div>
                    <p class="process-home-eyebrow">Paso 3</p>
                    <h2 id="diagnosis-summary-heading">Resumen del diagnóstico</h2>
                    <p>{{ $diagnosis['source_file'] }} · {{ $diagnosis['workflow_type'] }}</p>
                </div>
            </div>

            <div class="diagnosis-summary-grid">
                @foreach ([
                    ['label' => 'Estado', 'value' => $statusLabel, 'tone' => $statusTone],
                    ['label' => 'Filas leídas', 'value' => $diagnosis['rows_count'], 'tone' => 'neutral'],
                    ['label' => 'Productos normales', 'value' => $diagnosis['summary']['product_count'] ?? 0, 'tone' => 'neutral'],
                    ['label' => 'Warnings', 'value' => $diagnosis['warning_count'], 'tone' => ($diagnosis['warning_count'] > 0 ? 'review' : 'ok')],
                    ['label' => 'Bloqueos', 'value' => $diagnosis['blocked_count'], 'tone' => ($diagnosis['blocked_count'] > 0 ? 'blocked' : 'ok')],
                    ['label' => 'Price map generado', 'value' => $diagnosis['price_map_count'], 'tone' => 'neutral'],
                    ['label' => 'Exportación automática', 'value' => $diagnosis['can_export_automatically'] ? 'Sí' : 'No', 'tone' => ($diagnosis['can_export_automatically'] ? 'ok' : 'blocked')],
                ] as $metric)
                    <article @class(['diagnosis-metric', 'diagnosis-metric-' . $metric['tone']])>
                        <span>{{ $metric['label'] }}</span>
                        <strong>{{ $metric['value'] }}</strong>
                    </article>
                @endforeach
            </div>
        </section>

        @if (($diagnosis['blocked_count'] ?? 0) > 0)
            <section class="diagnosis-alert diagnosis-alert-blocked" aria-labelledby="diagnosis-blocks-heading">
                <x-filament::icon icon="heroicon-o-shield-exclamation" aria-hidden="true" />
                <div>
                    <h2 id="diagnosis-blocks-heading">Bloqueos críticos detectados</h2>
                    <p>Estos registros deben corregirse antes de habilitar una exportación.</p>
                    <ul>
                        @foreach (array_filter($diagnosis['warnings'], fn (array $warning): bool => ($warning['severity'] ?? null) === 'blocked') as $warning)
                            <li>
                                {{ $this->displayDiagnosticText($warning['issue'] ?? null) }}:
                                <strong>{{ $this->displayDiagnosticValue($warning['code'] ?? $warning['original_value'] ?? null) }}</strong>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>
        @endif

        <section class="diagnosis-panel" aria-labelledby="diagnosis-warnings-heading">
            <div class="diagnosis-section-heading">
                <div>
                    <h2 id="diagnosis-warnings-heading">Warnings detectados</h2>
                    <p>Advertencias y bloqueos informados por el servicio de diagnóstico.</p>
                </div>
            </div>

            @if ($diagnosis['warnings'] === [])
                <div class="diagnosis-empty-state">
                    <x-filament::icon icon="heroicon-o-check-circle" aria-hidden="true" />
                    <p>No se detectaron warnings.</p>
                </div>
            @else
                <div class="diagnosis-table-wrap">
                    <table class="diagnosis-table">
                        <thead>
                            <tr>
                                <th>Tipo / issue</th>
                                <th>Severidad</th>
                                <th>Código</th>
                                <th>Fila</th>
                                <th>Valor original</th>
                                <th>Recomendación</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($diagnosis['warnings'] as $warning)
                                <tr>
                                    <td>{{ $this->displayDiagnosticText($warning['issue'] ?? null) }}</td>
                                    <td>
                                        <span @class([
                                            'process-status',
                                            'process-status-blocked' => ($warning['severity'] ?? null) === 'blocked',
                                            'process-status-review' => ($warning['severity'] ?? null) !== 'blocked',
                                        ])>
                                            {{ $this->displayDiagnosticText($warning['severity'] ?? null) }}
                                        </span>
                                    </td>
                                    <td>{{ $this->displayDiagnosticValue($warning['code'] ?? null) }}</td>
                                    <td>{{ $this->displayDiagnosticValue($warning['row_number'] ?? null) }}</td>
                                    <td>{{ $this->displayDiagnosticValue($warning['original_value'] ?? null) }}</td>
                                    <td>{{ $this->displayDiagnosticText($warning['recommendation'] ?? null) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="diagnosis-panel" aria-labelledby="diagnosis-preview-heading">
            <div class="diagnosis-section-heading">
                <div>
                    <h2 id="diagnosis-preview-heading">Previsualización</h2>
                    <p>Primeras {{ count($diagnosis['preview_rows'] ?? []) }} filas procesadas. El archivo original no fue modificado.</p>
                </div>
            </div>

            <div class="diagnosis-table-wrap">
                <table class="diagnosis-table diagnosis-preview-table">
                    <thead>
                        <tr>
                            <th>CÓDIGO</th>
                            <th>MARCA</th>
                            <th>DESCRIPCIÓN</th>
                            <th>PRECIOLISTA</th>
                            <th>PRECIOOFERTA</th>
                            <th>PRECIOTACHADO</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($diagnosis['preview_rows'] ?? [] as $row)
                            <tr>
                                <td><strong>{{ $this->displayDiagnosticValue($row['code'] ?? null) }}</strong></td>
                                <td>{{ $this->displayDiagnosticValue($row['brand'] ?? null) }}</td>
                                <td>{{ $this->displayDiagnosticValue($row['description'] ?? null) }}</td>
                                <td>{{ $this->displayDiagnosticValue($row['price_list'] ?? null) }}</td>
                                <td>{{ $this->displayDiagnosticValue($row['price_offer'] ?? null) }}</td>
                                <td>{{ $this->displayDiagnosticValue($row['price_strikethrough'] ?? null) }}</td>
                                <td>
                                    <span @class([
                                        'process-status',
                                        'process-status-ok' => ($row['status'] ?? null) === 'ok',
                                        'process-status-review' => ($row['status'] ?? null) === 'review_required',
                                        'process-status-blocked' => ($row['status'] ?? null) === 'blocked',
                                    ])>
                                        {{ match ($row['status'] ?? null) {
                                            'ok' => 'OK',
                                            'review_required' => 'Revisión',
                                            'blocked' => 'Bloqueado',
                                            default => '—',
                                        } }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">No hay filas para previsualizar.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="diagnosis-export-panel" aria-labelledby="diagnosis-export-heading">
            <div>
                <p class="process-home-eyebrow">Paso 4</p>
                <h2 id="diagnosis-export-heading">Exportar</h2>

                @if ($status === 'blocked')
                    <p>La exportación está bloqueada. Corrija los errores críticos antes de exportar.</p>
                @elseif ($status === 'review_required')
                    <p>Hay advertencias que deben revisarse antes de exportar.</p>
                @else
                    <p>El diagnóstico está OK. El TXT se generará sin modificar el archivo original ni guardar datos en la base.</p>
                @endif
            </div>

            <x-filament::button
                type="button"
                icon="heroicon-o-arrow-down-tray"
                wire:click="exportTxt"
                wire:loading.attr="disabled"
                wire:target="exportTxt"
                :disabled="$status !== 'ok'"
            >
                Exportar TXT
            </x-filament::button>
        </section>
    @else
        <section class="diagnosis-empty-workflow">
            <x-filament::icon icon="heroicon-o-document-magnifying-glass" aria-hidden="true" />
            <div>
                <h2>Esperando un archivo</h2>
                <p>Seleccioná un TXT o XLSX y ejecutá el diagnóstico para habilitar la previsualización.</p>
            </div>
        </section>
    @endif
</x-filament-panels::page>
