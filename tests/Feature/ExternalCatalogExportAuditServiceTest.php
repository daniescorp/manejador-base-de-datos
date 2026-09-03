<?php

namespace Tests\Feature;

use App\Services\ExternalFiles\ExternalCatalogExportAuditService;
use App\Services\ExternalFiles\ExternalWorkflowExportService;
use Illuminate\Support\Facades\Artisan;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

class ExternalCatalogExportAuditServiceTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'catalog_audit_forbidden');
        config()->set('database.connections.catalog_audit_forbidden', ['driver' => 'catalog_audit_forbidden']);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_matching_source_and_export_are_ok_without_modifying_either_file(): void
    {
        [$source, $exported] = $this->matchingFiles();
        $sourceHash = hash_file('sha256', $source);
        $exportedHash = hash_file('sha256', $exported);

        $report = app(ExternalCatalogExportAuditService::class)->audit($source, $exported, 'ALMACEN');

        $this->assertSame('ok', $report['status']);
        $this->assertSame(2, $report['source_rows']);
        $this->assertSame(2, $report['exported_rows']);
        $this->assertSame(2, $report['matched_codes']);
        $this->assertSame(0, $report['missing_codes']);
        $this->assertSame(0, $report['extra_codes']);
        $this->assertSame(0, $report['price_mismatches']);
        $this->assertSame(0, $report['structure_errors']);
        $this->assertSame(0, $report['encoding_errors']);
        $this->assertSame([], $report['details']);
        $this->assertSame($sourceHash, hash_file('sha256', $source));
        $this->assertSame($exportedHash, hash_file('sha256', $exported));
    }

    public function test_missing_extra_and_duplicate_exported_codes_are_blocked(): void
    {
        [$source, $exported] = $this->matchingFiles();
        $lines = $this->exportedLines($exported);

        $missing = $this->exportFile(implode("\r\n", [$lines[0], $lines[1]]));
        $missingReport = app(ExternalCatalogExportAuditService::class)->audit($source, $missing, 'ALMACEN');
        $this->assertSame('blocked', $missingReport['status']);
        $this->assertSame(1, $missingReport['missing_codes']);

        $extraLine = str_replace('10001', '99999', $lines[1]);
        $extra = $this->exportFile(implode("\r\n", [...$lines, $extraLine]));
        $extraReport = app(ExternalCatalogExportAuditService::class)->audit($source, $extra, 'ALMACEN');
        $this->assertSame(1, $extraReport['extra_codes']);
        $this->assertSame('blocked', $extraReport['status']);

        $duplicate = $this->exportFile(implode("\r\n", [...$lines, $lines[1]]));
        $duplicateReport = app(ExternalCatalogExportAuditService::class)->audit($source, $duplicate, 'ALMACEN');
        $this->assertSame(1, $duplicateReport['duplicate_exported_codes']);
        $this->assertSame('blocked', $duplicateReport['status']);
    }

    public function test_all_business_field_mismatches_are_reported_by_code(): void
    {
        [$source, $exported] = $this->matchingFiles();
        $lines = $this->exportedLines($exported);
        $values = explode("\t", $lines[1]);
        $changes = [
            0 => 'OTRA',
            1 => 'OTRO GRUPO',
            3 => 'OTRA MARCA',
            4 => 'OTRA DESCRIPCION',
            5 => '99',
            7 => '$ 9.999',
            9 => '$ 8.888',
            10 => '$ 7.777',
            12 => '.\\otra-cucarda.png',
        ];

        foreach ($changes as $index => $value) {
            $values[$index] = $value;
        }

        $lines[1] = implode("\t", $values);
        $changed = $this->exportFile(implode("\r\n", $lines));
        $report = app(ExternalCatalogExportAuditService::class)->audit($source, $changed, 'ALMACEN');

        $this->assertSame('blocked', $report['status']);
        $this->assertSame(3, $report['price_mismatches']);
        $this->assertSame(1, $report['uxb_mismatches']);
        $this->assertSame(1, $report['brand_mismatches']);
        $this->assertSame(1, $report['description_mismatches']);
        $this->assertSame(1, $report['category_mismatches']);
        $this->assertSame(1, $report['group_mismatches']);
        $this->assertSame(1, $report['auxiliary_mismatches']);
        $this->assertContains('price_list_mismatch', array_column($report['details'], 'type'));
    }

    public function test_changed_order_and_duplicate_source_code_are_warnings(): void
    {
        [$source, $exported] = $this->matchingFiles();
        $lines = $this->exportedLines($exported);
        $reordered = $this->exportFile($this->windows1252(implode("\r\n", [$lines[0], $lines[2], $lines[1]])));
        $orderReport = app(ExternalCatalogExportAuditService::class)->audit($source, $reordered, 'ALMACEN');

        $this->assertSame('warning', $orderReport['status']);
        $this->assertContains('code_order_mismatch', array_column($orderReport['details'], 'type'));

        $duplicateSource = $this->sourceFile([$this->row('10001'), $this->row('10001')]);
        $singleExport = $this->exportFile($this->windows1252(implode("\r\n", [$lines[0], $lines[1]])));
        $duplicateReport = app(ExternalCatalogExportAuditService::class)->audit($duplicateSource, $singleExport, 'ALMACEN');
        $this->assertSame('warning', $duplicateReport['status'], json_encode($duplicateReport));
        $this->assertSame(1, $duplicateReport['duplicate_source_codes']);
    }

    public function test_invalid_delimiter_columns_bom_encoding_and_line_endings_are_blocked(): void
    {
        [$source, $exported] = $this->matchingFiles();
        $content = file_get_contents($exported);
        $this->assertIsString($content);

        $cases = [
            'structure_wrong_delimiter' => str_replace("\t", ';', $content),
            'structure_column_count' => preg_replace('/\t[^\t\r\n]+(?=\r\n)/', '', $content, 1) ?? $content,
            'encoding_utf8_bom' => "\xEF\xBB\xBF".$content,
            'encoding_utf8' => mb_convert_encoding($content, 'UTF-8', 'Windows-1252'),
            'structure_line_endings' => str_replace("\r\n", "\n", $content),
        ];

        foreach ($cases as $expectedIssue => $invalidContent) {
            $report = app(ExternalCatalogExportAuditService::class)->audit(
                $source,
                $this->exportFile($invalidContent),
                'ALMACEN',
            );
            $this->assertSame('blocked', $report['status']);
            $this->assertContains($expectedIssue, array_column($report['details'], 'type'));
        }
    }

    public function test_zip_with_multiple_category_txt_files_is_audited(): void
    {
        $source = $this->workbook([
            'ALMACEN' => [$this->headers(), $this->row('10001', 'ALMACEN')],
            'BEBIDAS' => [$this->headers(), $this->row('20001', 'BEBIDAS')],
        ]);
        $export = app(ExternalWorkflowExportService::class)->export($source, 'catalog_body');
        $zipPath = $this->temporaryPath('zip');
        file_put_contents($zipPath, $export['content']);

        $report = app(ExternalCatalogExportAuditService::class)->audit($source, $zipPath);

        $this->assertSame('ok', $report['status']);
        $this->assertSame(2, $report['source_rows']);
        $this->assertSame(2, $report['exported_rows']);
        $this->assertSame(2, $report['matched_codes']);
    }

    public function test_artisan_command_supports_json_and_requires_both_paths(): void
    {
        $this->assertArrayHasKey('app:audit-catalog-export', Artisan::all());
        $exitCode = Artisan::call('app:audit-catalog-export', ['--json' => true]);
        $error = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertSame('error', $error['status']);

        [$source, $exported] = $this->matchingFiles();
        $exitCode = Artisan::call('app:audit-catalog-export', [
            '--source' => $source,
            '--export' => $exported,
            '--section' => 'ALMACEN',
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exitCode, (string) ($report['message'] ?? Artisan::output()));
        $this->assertSame('ok', $report['status']);
    }

    /** @return array{string, string} */
    private function matchingFiles(): array
    {
        $source = $this->sourceFile([$this->row('10001'), $this->row('10002')]);
        $export = app(ExternalWorkflowExportService::class)->export($source, 'catalog_body');

        return [$source, $this->exportFile($export['content'])];
    }

    /** @param list<list<mixed>> $rows */
    private function sourceFile(array $rows): string
    {
        return $this->textFile(implode("\r\n", [
            implode("\t", $this->headers()),
            ...array_map(static fn (array $row): string => implode("\t", $row), $rows),
        ]), 'txt');
    }

    private function exportFile(string $content): string
    {
        return $this->textFile($content, 'txt');
    }

    /** @return list<string> */
    private function exportedLines(string $path): array
    {
        $content = file_get_contents($path);

        if (! is_string($content)) {
            throw new RuntimeException('No se pudo leer el export sintético.');
        }

        return explode("\r\n", mb_convert_encoding($content, 'UTF-8', 'Windows-1252'));
    }

    /** @return list<string> */
    private function headers(): array
    {
        return [
            'CATEGORIA', 'GRUPO', 'CODIGO', 'MARCA', 'DESCRIPCION', 'UXB',
            '@IMAGENES', 'PRECIOLISTA', '@IMAGENES', '   PRECIOOFERTA  ',
            ' PRECIOTACHADO ', '@IMAGENES', 'CUCARDA', 'Conca', 'Conca',
        ];
    }

    /** @return list<mixed> */
    private function row(string $code, string $category = 'ALMACEN'): array
    {
        return [
            $category, 'GRUPO', $code, 'MARCA', 'NIÑO 500 CC.', 12,
            '.\\imagenes\\'.$code.'.png', 699, '.\\ai\\azul.ai', 599, 799,
            '.\\ai\\rojo.ai', '.\\cucarda.png', '.\\imagenes\\', '.png',
        ];
    }

    /** @param array<string, list<list<mixed>>> $sheets */
    private function workbook(array $sheets): string
    {
        $spreadsheet = new Spreadsheet;
        $first = true;

        foreach ($sheets as $title => $rows) {
            $sheet = $first ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $first = false;
            $sheet->setTitle($title);
            $sheet->fromArray($rows);
            $sheet->getStyle('H2:K'.$sheet->getHighestDataRow())->getNumberFormat()->setFormatCode('"$ "#,##0');
        }

        $path = $this->temporaryPath('xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function textFile(string $content, string $extension): string
    {
        $path = $this->temporaryPath($extension);

        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('No se pudo crear el fixture sintético.');
        }

        return $path;
    }

    private function temporaryPath(string $extension): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'catalog-audit-'.bin2hex(random_bytes(8)).'.'.$extension;
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function windows1252(string $content): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252', $content);

        if ($encoded === false) {
            throw new RuntimeException('No se pudo codificar el fixture sintético.');
        }

        return $encoded;
    }
}
