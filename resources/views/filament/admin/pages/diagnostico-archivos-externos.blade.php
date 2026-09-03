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

        {{ $this->uploadForm }}

        @if ($uploadedFileInfo)
            <div class="diagnosis-empty-workflow" aria-live="polite">
                <x-filament::icon icon="heroicon-o-document-check" aria-hidden="true" />
                <div>
                    <h2>Archivo listo para diagnosticar</h2>
                    <p>
                        <strong>{{ $uploadedFileInfo['name'] }}</strong>
                        · {{ $uploadedFileInfo['extension'] === 'xlsx' ? 'Excel/XLSX' : mb_strtoupper($uploadedFileInfo['extension'], 'UTF-8') }}
                        · {{ $this->displayFileSize($uploadedFileInfo['size']) }}
                        · Workflow: {{ $this->workflowType() }}
                    </p>
                </div>
            </div>
        @endif

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
                    <p>
                        {{ $diagnosis['source_file'] }}
                        · {{ ($diagnosis['format'] ?? null) === 'xlsx' ? 'Excel/XLSX' : mb_strtoupper($diagnosis['format'] ?? '', 'UTF-8') }}
                        @if (isset($diagnosis['source_size']))
                            · {{ $this->displayFileSize($diagnosis['source_size']) }}
                        @endif
                        · Workflow: {{ $diagnosis['workflow_type'] }}
                    </p>
                    @if (($diagnosis['sheets_read'] ?? []) !== [])
                        <p>
                            {{ ($diagnosis['sheet_count'] ?? 1) === 1 ? 'Hoja detectada' : 'Hojas detectadas' }}:
                            {{ implode(', ', $diagnosis['sheets_read']) }}.
                            @if (($diagnosis['ignored_secondary_block_count'] ?? 0) > 0)
                                {{ $diagnosis['ignored_secondary_block_count'] }} bloque(s) secundario(s) ignorado(s).
                            @endif
                        </p>
                    @endif
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

        @if ($this->workflowType() === 'catalog_body' && ($diagnosis['category_summary'] ?? []) !== [])
            <section class="diagnosis-panel" aria-labelledby="diagnosis-categories-heading">
                <div class="diagnosis-section-heading">
                    <div>
                        <h2 id="diagnosis-categories-heading">Resumen por categoría / solapa</h2>
                        <p>Cada categoría se conserva como una salida independiente.</p>
                    </div>
                </div>
                <div class="diagnosis-table-wrap">
                    <table class="diagnosis-table">
                        <thead><tr>
                            <th>Categoría / solapa</th><th>Hoja origen</th><th>Filas</th><th>Estado</th>
                            <th>Cucardas</th><th>Warnings</th><th>Bloqueos</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($diagnosis['category_summary'] as $category)
                                <tr>
                                    <td><strong>{{ $this->displayDiagnosticValue($category['name'] ?? null) }}</strong></td>
                                    <td>{{ $this->displayDiagnosticValue($category['sheet'] ?? null) }}</td>
                                    <td>{{ $category['rows'] ?? 0 }}</td>
                                    <td><span @class([
                                        'process-status',
                                        'process-status-ok' => ($category['status'] ?? null) === 'ok',
                                        'process-status-review' => ($category['status'] ?? null) === 'review_required',
                                        'process-status-blocked' => ($category['status'] ?? null) === 'blocked',
                                    ])>{{ match ($category['status'] ?? null) {
                                        'ok' => 'OK', 'review_required' => 'Revisión', 'blocked' => 'Bloqueado', default => '—',
                                    } }}</span></td>
                                    <td>{{ $category['badge_count'] ?? 0 }}</td>
                                    <td>{{ $category['warning_count'] ?? 0 }}</td>
                                    <td>{{ $category['blocked_count'] ?? 0 }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
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
                            <th>UXB</th>
                            <th>PRECIOLISTA</th>
                            <th>PRECIOOFERTA</th>
                            <th>PRECIOTACHADO</th>
                            <th>Cucarda</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($diagnosis['preview_rows'] ?? [] as $row)
                            <tr>
                                <td><strong>{{ $this->displayDiagnosticValue($row['code'] ?? null) }}</strong></td>
                                <td>{{ $this->displayDiagnosticValue($row['brand'] ?? null) }}</td>
                                <td>{{ $this->displayDiagnosticValue($row['description'] ?? null) }}</td>
                                <td>{{ $this->displayDiagnosticValue($row['units_per_box'] ?? null) }}</td>
                                <td>{{ $this->displayDiagnosticValue($row['price_list'] ?? null) }}</td>
                                <td>{{ $this->displayDiagnosticValue($row['price_offer'] ?? null) }}</td>
                                <td>{{ $this->displayDiagnosticValue($row['price_strikethrough'] ?? null) }}</td>
                                <td>
                                    @if ($row['has_badge'] ?? false)
                                        <strong>Sí</strong>
                                        <div>{{ $this->displayDiagnosticValue($row['badge'] ?? null) }}</div>
                                    @else
                                        No
                                    @endif
                                </td>
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
                                    @if (($row['issues'] ?? []) !== [])
                                        <div>{{ implode(', ', array_map(fn (string $issue): string => $this->displayDiagnosticText($issue), $row['issues'])) }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9">No hay filas para previsualizar.</td>
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
                    <p>{{ $this->workflowType() === 'catalog_body'
                        ? 'Existen categorías con bloqueos. Resuelva los bloqueos antes de exportar el paquete completo.'
                        : 'La exportación está bloqueada. Corrija los errores críticos antes de exportar.' }}</p>
                @elseif ($status === 'review_required')
                    <p>Hay advertencias que deben revisarse antes de exportar.</p>
                @else
                    <p>{{ $this->workflowType() === 'catalog_body'
                        ? 'El diagnóstico está OK. Se generará un TXT por categoría; si hay varias, se descargará un ZIP.'
                        : 'El diagnóstico está OK. El TXT se generará sin modificar el archivo original ni guardar datos en la base.' }}</p>
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
                {{ $this->workflowType() === 'catalog_body' ? 'Exportar TXT por categorías' : 'Exportar TXT' }}
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
