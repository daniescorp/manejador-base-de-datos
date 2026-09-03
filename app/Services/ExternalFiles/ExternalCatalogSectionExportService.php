<?php

namespace App\Services\ExternalFiles;

use App\Services\Audits\ExternalFormatSamplesAuditService;
use DomainException;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class ExternalCatalogSectionExportService
{
    public const DELIMITER = "\t";

    public function __construct(
        private readonly ExternalExportDiagnosisService $diagnosisService,
        private readonly ExternalRowsReader $rowsReader,
        private readonly ExternalPriceFormatter $priceFormatter,
        private readonly ExternalDescriptionFormatter $descriptionFormatter,
        private readonly ExternalIndesignHeaderFormatter $headerFormatter,
    ) {}

    /** @return array<string, mixed> */
    public function export(string $filePath): array
    {
        $diagnosis = $this->diagnosisService->diagnose(
            $filePath,
            ExternalFormatSamplesAuditService::WORKFLOW_CATALOG_BODY,
        );

        if (($diagnosis['status'] ?? null) !== 'ok') {
            throw new DomainException('Solo se puede exportar el paquete cuando todas las categorías tienen diagnóstico OK.');
        }

        $readResult = $this->rowsReader->read(
            $filePath,
            ExternalFormatSamplesAuditService::WORKFLOW_CATALOG_BODY,
        );
        $sections = array_values($readResult['metadata']['catalog_sections'] ?? []);

        if ($sections === []) {
            throw new DomainException('No se detectaron categorías o secciones exportables.');
        }

        $artifacts = [];
        $usedNames = [];

        foreach ($sections as $index => $section) {
            $sectionRows = array_values(array_filter(
                $readResult['rows'] ?? [],
                static fn (array $row): bool => ($row['section_key'] ?? null) === ($section['key'] ?? null),
            ));

            if ($sectionRows === []) {
                continue;
            }

            $baseName = $this->fileBaseName((string) ($section['section'] ?? 'categoria-'.($index + 1)));
            $fileName = $this->uniqueFileName($baseName, $usedNames);
            $artifacts[] = [
                'section' => $section['section'] ?? $baseName,
                'file_name' => $fileName,
                'content' => $this->renderTxt(
                    $sectionRows,
                    array_values($section['safe_headers'] ?? []),
                    array_values($section['raw_headers'] ?? []),
                ),
                'rows' => count($sectionRows),
                'columns' => count($section['safe_headers'] ?? []),
            ];
        }

        if ($artifacts === []) {
            throw new DomainException('No se encontraron filas exportables por categoría.');
        }

        if (count($artifacts) === 1) {
            return [
                ...$artifacts[0],
                'format' => 'txt',
                'mime_type' => 'text/plain; charset=Windows-1252',
                'artifacts' => $artifacts,
                'diagnosis' => $diagnosis,
            ];
        }

        return [
            'content' => $this->zip($artifacts),
            'format' => 'zip',
            'mime_type' => 'application/zip',
            'rows' => array_sum(array_column($artifacts, 'rows')),
            'columns' => null,
            'artifacts' => $artifacts,
            'diagnosis' => $diagnosis,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function renderTxt(array $rows, array $safeHeaders, array $rawHeaders): string
    {
        $safeHeaders = $safeHeaders !== [] ? $safeHeaders : array_keys($rows[0]['data'] ?? []);
        $outputHeaders = $this->headerFormatter->format($safeHeaders, $rawHeaders);
        $outputRows = [];

        foreach ($rows as $envelope) {
            $data = $envelope['data'] ?? [];
            $values = [];

            foreach ($safeHeaders as $header) {
                $value = $data[$header] ?? '';

                if ($this->isHeader($header, 'precio(?:lista|oferta|tachado)')) {
                    $formatted = $this->priceFormatter->format($value);

                    if ($formatted['requires_review']) {
                        throw new DomainException('El archivo contiene precios que requieren revisión.');
                    }

                    $value = $formatted['formatted_value'];
                } elseif ($this->isHeader($header, 'descripcion')) {
                    $value = $this->descriptionFormatter->format((string) $value);
                }

                $values[] = $value;
            }

            $outputRows[] = $values;
        }

        $this->validateColumnCounts($outputHeaders, $outputRows);
        $lines = [implode(self::DELIMITER, array_map($this->txtValue(...), $outputHeaders))];

        foreach ($outputRows as $values) {
            $lines[] = implode(self::DELIMITER, array_map($this->txtValue(...), $values));
        }

        $content = implode(ExternalWorkflowExportService::LINE_ENDING, $lines);
        $this->validateIndesignText($content);

        return $this->encodeWindows1252($content);
    }

    /** @param list<array<string, mixed>> $artifacts */
    private function zip(array $artifacts): string
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog-sections-');

        if ($path === false) {
            throw new RuntimeException('No se pudo preparar el ZIP de categorías.');
        }

        $zip = new ZipArchive;
        $isOpen = false;

        try {
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo crear el ZIP de categorías.');
            }
            $isOpen = true;

            foreach ($artifacts as $artifact) {
                $zip->addFromString($artifact['file_name'], $artifact['content']);
            }

            $zip->close();
            $isOpen = false;
            $content = file_get_contents($path);

            if ($content === false) {
                throw new RuntimeException('No se pudo leer el ZIP de categorías generado.');
            }

            return $content;
        } finally {
            if ($isOpen) {
                $zip->close();
            }

            @unlink($path);
        }
    }

    private function isHeader(string $header, string $pattern): bool
    {
        $normalized = mb_strtolower(Str::ascii(trim($header)), 'UTF-8');
        $normalized = preg_replace('/[^a-z0-9]+/u', '', $normalized) ?? $normalized;

        return preg_match('/^(?:'.$pattern.')(?:\d+)?$/', $normalized) === 1;
    }

    private function txtValue(mixed $value): string
    {
        return (string) (preg_replace('/[\t\r\n]+/u', ' ', (string) $value) ?? $value);
    }

    /** @param list<string> $headers @param list<list<mixed>> $rows */
    private function validateColumnCounts(array $headers, array $rows): void
    {
        $expected = count($headers);

        foreach ($rows as $index => $row) {
            if (count($row) !== $expected) {
                throw new DomainException(
                    'La fila '.($index + 1).' no coincide con las '.$expected.' columnas del encabezado de InDesign.',
                );
            }
        }
    }

    private function validateIndesignText(string $content): void
    {
        $lines = explode(ExternalWorkflowExportService::LINE_ENDING, $content);
        $expectedHeader = implode(self::DELIMITER, ExternalIndesignHeaderFormatter::HEADERS);

        if (($lines[0] ?? null) !== $expectedHeader) {
            throw new DomainException('El encabezado generado no coincide con el modelo de InDesign.');
        }

        foreach ($lines as $index => $line) {
            if (substr_count($line, self::DELIMITER) !== ExternalIndesignHeaderFormatter::COLUMN_COUNT - 1) {
                throw new DomainException(
                    'La línea '.($index + 1).' no contiene las 15 columnas requeridas por InDesign.',
                );
            }
        }

        if (str_contains($lines[0], ';')
            || preg_match('/(?:@IMAGENES|Conca)_\d+/', $lines[0]) === 1) {
            throw new DomainException('El encabezado contiene campos incompatibles con InDesign.');
        }
    }

    private function encodeWindows1252(string $content): string
    {
        $encoded = @iconv('UTF-8', 'Windows-1252', $content);

        if ($encoded === false) {
            throw new DomainException(
                'El catálogo contiene caracteres que no pueden exportarse de forma segura en Windows-1252.',
            );
        }

        if (str_starts_with($encoded, "\xEF\xBB\xBF")) {
            throw new DomainException('La salida para InDesign no puede contener BOM UTF-8.');
        }

        return $encoded;
    }

    private function fileBaseName(string $section): string
    {
        $name = Str::slug($section, '-');

        return $name !== '' ? $name : 'categoria';
    }

    /** @param array<string, int> $usedNames */
    private function uniqueFileName(string $baseName, array &$usedNames): string
    {
        $usedNames[$baseName] = ($usedNames[$baseName] ?? 0) + 1;
        $suffix = $usedNames[$baseName] === 1 ? '' : '-'.$usedNames[$baseName];

        return $baseName.$suffix.'.txt';
    }
}
