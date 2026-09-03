<?php

namespace Tests\Feature;

use App\Services\ExternalFiles\ExternalExportDiagnosisService;
use App\Services\ExternalFiles\ExternalWorkflowExportService;
use DomainException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class ExternalCatalogSectionExportServiceTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_catalog_workbook_exposes_two_sheet_summaries_and_exports_a_zip(): void
    {
        $source = $this->workbook([
            'ALMACEN' => [
                $this->modelHeaders(),
                $this->modelRow('ALMACEN', '10001', 'ACEITE 500 CC.', 699, '.\\cucardas\\oferta.png'),
            ],
            'BEBIDAS' => [
                [...array_slice($this->modelHeaders(), 0, 12), 'CUCARDA', 'Conca', 'Conca'],
                $this->modelRow('BEBIDAS', '20001', 'GASEOSA 1 LT.', 1299, '.\\cucardas\\nuevo.png'),
            ],
        ]);
        $beforeHash = hash_file('sha256', $source);

        $diagnosis = app(ExternalExportDiagnosisService::class)->diagnose($source, 'catalog_body');

        $this->assertSame('ok', $diagnosis['status']);
        $this->assertSame(['ALMACEN', 'BEBIDAS'], array_column($diagnosis['category_summary'], 'name'));
        $this->assertSame([1, 1], array_column($diagnosis['category_summary'], 'rows'));
        $this->assertSame([0, 1], array_column($diagnosis['category_summary'], 'badge_count'));

        $export = app(ExternalWorkflowExportService::class)->export($source, 'catalog_body');

        $this->assertSame('zip', $export['format']);
        $this->assertSame('application/zip', $export['mime_type']);
        $this->assertCount(2, $export['artifacts']);
        $this->assertSame($beforeHash, hash_file('sha256', $source));

        $entries = $this->zipEntries($export['content']);
        $this->assertSame(['almacen.txt', 'bebidas.txt'], array_keys($entries));
        $this->assertSame(
            implode("\t", $this->modelHeaders()),
            explode("\r\n", $entries['almacen.txt'])[0],
        );
        $this->assertStringNotContainsString('@IMAGENES_2', $entries['almacen.txt']);
        $this->assertStringContainsString('ACEITE 500CC', $entries['almacen.txt']);
        $this->assertStringContainsString('.\\cucardas\\oferta.png', $entries['almacen.txt']);
        $this->assertStringNotContainsString(';', explode("\r\n", $entries['almacen.txt'])[0]);
        $almacenValues = explode("\t", explode("\r\n", $entries['almacen.txt'])[1]);
        $this->assertSame(['', '', ''], array_slice($almacenValues, 8, 3));
        $this->assertStringNotContainsString('20001', $entries['almacen.txt']);
        $this->assertStringContainsString('20001', $entries['bebidas.txt']);
        $this->assertStringContainsString('.\\cucardas\\nuevo.png', $entries['bebidas.txt']);

        foreach ($entries as $content) {
            $this->assertFalse(str_starts_with($content, "\xEF\xBB\xBF"));
            $lines = explode("\r\n", $content);
            $columnCount = count(explode("\t", $lines[0]));

            foreach (array_slice($lines, 1) as $line) {
                $this->assertCount($columnCount, explode("\t", $line));
            }
        }
    }

    public function test_single_txt_catalog_exports_directly_and_preserves_badge_and_auxiliary_columns(): void
    {
        $source = $this->textFile(implode("\r\n", [
            implode("\t", [...array_slice($this->modelHeaders(), 0, 12), 'CUCARDA', 'Conca', 'Conca']),
            implode("\t", $this->modelRow('ALMACEN', '10001', 'NIÑO CAFÉ 500 CC.', '699,00', 'badge.png')),
        ]));

        $diagnosis = app(ExternalExportDiagnosisService::class)->diagnose($source, 'catalog_body');
        $export = app(ExternalWorkflowExportService::class)->export($source, 'catalog_body');

        $this->assertSame('ok', $diagnosis['status']);
        $this->assertCount(1, $diagnosis['category_summary']);
        $this->assertSame(1, $diagnosis['category_summary'][0]['badge_count']);
        $this->assertSame('txt', $export['format']);
        $this->assertStringEndsWith('.txt', $export['file_name']);
        $this->assertSame('text/plain; charset=Windows-1252', $export['mime_type']);
        $this->assertFalse(str_starts_with($export['content'], "\xEF\xBB\xBF"));
        $this->assertFalse(mb_check_encoding($export['content'], 'UTF-8'));
        $utf8Content = mb_convert_encoding($export['content'], 'UTF-8', 'Windows-1252');
        $this->assertDoesNotMatchRegularExpression('/(?<!\r)\n/', $utf8Content);
        $this->assertStringContainsString('NIÑO CAFÉ 500CC', $utf8Content);
        $this->assertStringContainsString('badge.png', $utf8Content);
        $this->assertSame(14, substr_count(explode("\r\n", $utf8Content)[1], "\t"));
    }

    public function test_categories_inside_one_sheet_are_split_without_losing_rows_with_inherited_labels(): void
    {
        $source = $this->workbook([
            'CATALOGO' => [
                $this->modelHeaders(),
                $this->modelRow('ALMACEN', '10001', 'PRODUCTO A', 699),
                $this->modelRow('', '10002', 'PRODUCTO B', 799),
                $this->modelRow('BEBIDAS', '20001', 'PRODUCTO C', 899),
                $this->modelRow('', '20002', 'PRODUCTO D', 999),
            ],
        ]);

        $diagnosis = app(ExternalExportDiagnosisService::class)->diagnose($source, 'catalog_body');
        $export = app(ExternalWorkflowExportService::class)->export($source, 'catalog_body');

        $this->assertSame('ok', $diagnosis['status']);
        $this->assertSame(['ALMACEN', 'BEBIDAS'], array_column($diagnosis['category_summary'], 'name'));
        $this->assertSame([2, 2], array_column($diagnosis['category_summary'], 'rows'));
        $this->assertSame(4, $export['rows']);
        $this->assertSame(['almacen.txt', 'bebidas.txt'], array_column($export['artifacts'], 'file_name'));
        $this->assertStringContainsString('10002', $export['artifacts'][0]['content']);
        $this->assertStringContainsString('20002', $export['artifacts'][1]['content']);
    }

    public function test_duplicate_catalog_sections_block_the_complete_package_and_list_both_origins(): void
    {
        $source = $this->workbook([
            'HOJA A' => [
                $this->modelHeaders(),
                $this->modelRow('ALMACEN', '10001', 'PRODUCTO A', 699),
            ],
            'HOJA B' => [
                $this->modelHeaders(),
                $this->modelRow('ALMACEN', '10002', 'PRODUCTO B', 799),
            ],
        ]);

        $diagnosis = app(ExternalExportDiagnosisService::class)->diagnose($source, 'catalog_body');

        $this->assertSame('blocked', $diagnosis['status']);
        $this->assertSame(2, $diagnosis['category_summary'][0]['blocked_count'] + $diagnosis['category_summary'][1]['blocked_count']);
        $this->assertSame(['blocked', 'blocked'], array_column($diagnosis['category_summary'], 'status'));
        $this->assertSame(2, $diagnosis['warnings'][0]['detected_blocks']);
        $this->assertCount(2, $diagnosis['warnings'][0]['origins']);

        $this->expectException(DomainException::class);
        app(ExternalWorkflowExportService::class)->export($source, 'catalog_body');
    }

    public function test_irregular_source_columns_block_export_with_a_controlled_error(): void
    {
        $source = $this->textFile("CODIGO\tDESCRIPCION\tPRECIOLISTA\r\n10001\tPRODUCTO");

        $diagnosis = app(ExternalExportDiagnosisService::class)->diagnose($source, 'catalog_body');
        $this->assertSame('review_required', $diagnosis['status']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Solo se puede exportar el paquete');
        app(ExternalWorkflowExportService::class)->export($source, 'catalog_body');
    }

    public function test_characters_outside_windows_1252_block_export_with_a_clear_error(): void
    {
        $source = $this->textFile(implode("\r\n", [
            implode("\t", $this->modelHeaders()),
            implode("\t", $this->modelRow('ALMACEN', '10001', 'PRODUCTO 😀', 699)),
        ]));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('caracteres que no pueden exportarse');
        app(ExternalWorkflowExportService::class)->export($source, 'catalog_body');
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
            $lastColumn = $sheet->getHighestDataColumn();
            $lastRow = $sheet->getHighestDataRow();
            $sheet->getStyle("A2:{$lastColumn}{$lastRow}")->getNumberFormat()->setFormatCode('0');

            foreach ($rows[0] as $index => $header) {
                if ($header === 'PRECIOLISTA') {
                    $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
                    $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('"$ "#,##0');
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'catalog-sections-');

        if ($path === false) {
            throw new RuntimeException('No se pudo crear el fixture XLSX.');
        }

        $xlsxPath = $path.'.xlsx';
        (new Xlsx($spreadsheet))->save($xlsxPath);
        unlink($path);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryFiles[] = $xlsxPath;

        return $xlsxPath;
    }

    private function textFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog-section-txt-');

        if ($path === false || file_put_contents($path, $content) === false) {
            throw new RuntimeException('No se pudo crear el fixture TXT.');
        }

        $txtPath = $path.'.txt';
        rename($path, $txtPath);
        $this->temporaryFiles[] = $txtPath;

        return $txtPath;
    }

    /** @return list<string> */
    private function modelHeaders(): array
    {
        return [
            'CATEGORIA', 'GRUPO', 'CODIGO', 'MARCA', 'DESCRIPCION', 'UXB',
            '@IMAGENES', 'PRECIOLISTA', '@IMAGENES', '   PRECIOOFERTA  ',
            ' PRECIOTACHADO ', '@IMAGENES', '@IMAGENES', 'Conca', 'Conca',
        ];
    }

    /** @return list<mixed> */
    private function modelRow(
        string $category,
        string $code,
        string $description,
        mixed $price,
        string $badge = '',
    ): array {
        return [
            $category, 'GRUPO', $code, 'MARCA', $description, 12,
            '.\\imagenes\\'.$code.'.png', $price, '', '', '',
            '.\\ai\\contenedor_rojo.ai', $badge, '.\\imagenes\\', '.png',
        ];
    }

    /** @return array<string, string> */
    private function zipEntries(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog-export-zip-');

        if ($path === false || file_put_contents($path, $content) === false) {
            throw new RuntimeException('No se pudo inspeccionar el ZIP.');
        }

        $this->temporaryFiles[] = $path;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            $entries[$name] = $zip->getFromIndex($index);
        }

        $zip->close();

        return $entries;
    }
}
