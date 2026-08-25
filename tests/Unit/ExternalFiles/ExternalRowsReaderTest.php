<?php

namespace Tests\Unit\ExternalFiles;

use App\Services\Audits\ExternalFormatSamplesAuditService;
use App\Services\ExternalFiles\ExternalPriceFormatter;
use App\Services\ExternalFiles\ExternalPriceMapBuilder;
use App\Services\ExternalFiles\ExternalRowsReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

class ExternalRowsReaderTest extends TestCase
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

    public function test_it_reads_a_windows_1252_tab_file_with_safe_duplicate_headers(): void
    {
        $header = "CATEGORIA\tGRUPO\tCODIGO\tMARCA\tDESCRIPCION\tUXB\t@folder\tPRECIOLISTA\t@folder\t PRECIOOFERTA \t PRECIOTACHADO \t@folder\t@folder\tConca\tConca";
        $row = "\t\t30385\tGALLO\tArroz café\t10\t.\\imagenes\\30385.png\t\t\t3699\t\t\t\t\t";
        $file = $this->textFile($header."\r\n".$row, 'Windows-1252');
        $beforeHash = hash_file('sha256', $file);

        $result = $this->reader()->read(
            $file,
            ExternalFormatSamplesAuditService::WORKFLOW_PROMO_TAPA,
        );
        $data = $result['rows'][0]['data'];

        $this->assertSame('txt', $result['metadata']['format']);
        $this->assertSame('tab', $result['metadata']['delimiter']);
        $this->assertSame('Windows-1252', $result['metadata']['encoding']);
        $this->assertSame('promo_tapa', $result['metadata']['workflow_type']);
        $this->assertSame(15, $result['metadata']['column_count']);
        $this->assertSame(1, $result['metadata']['row_count']);
        $this->assertCount(15, $data);
        $this->assertSame('30385', $data['CODIGO']);
        $this->assertSame('', $data['PRECIOLISTA']);
        $this->assertSame('3699', $data['PRECIOOFERTA']);
        $this->assertSame('', $data['PRECIOTACHADO']);
        $this->assertSame('Arroz café', $data['DESCRIPCION']);
        $this->assertArrayHasKey('@folder', $data);
        $this->assertArrayHasKey('@folder_2', $data);
        $this->assertArrayHasKey('@folder_3', $data);
        $this->assertArrayHasKey('@folder_4', $data);
        $this->assertArrayHasKey('Conca', $data);
        $this->assertArrayHasKey('Conca_2', $data);
        $this->assertSame(2, $result['rows'][0]['row_number']);
        $this->assertNull($result['rows'][0]['source_sheet']);
        $this->assertSame($beforeHash, hash_file('sha256', $file));
    }

    public function test_txt_rows_feed_the_price_map_builder_without_adapter_logic(): void
    {
        $file = $this->textFile(
            "CODIGO\tPRECIOLISTA\tPRECIOOFERTA\tPRECIOTACHADO\n30385\t\t3699\t",
        );
        $reader = $this->reader();
        $readResult = $reader->read(
            $file,
            ExternalFormatSamplesAuditService::WORKFLOW_PROMO_TAPA,
        );
        $priceResult = $this->priceMapBuilder()->build($reader->rowsForPriceMap($readResult));

        $this->assertSame([
            'precio_lista' => '',
            'precio_oferta' => '$ 3.699',
            'precio_tachado' => '',
        ], $priceResult['price_map']['30385']);
        $this->assertFalse($priceResult['requires_review']);
    }

    public function test_it_reads_only_the_primary_xlsx_table_and_ignores_empty_and_secondary_rows(): void
    {
        $file = $this->promoWorkbook();
        $beforeHash = hash_file('sha256', $file);

        $result = $this->reader()->read(
            $file,
            ExternalFormatSamplesAuditService::WORKFLOW_PROMO_TAPA,
        );

        $this->assertSame('xlsx', $result['metadata']['format']);
        $this->assertSame('promo_tapa', $result['metadata']['workflow_type']);
        $this->assertSame(2, $result['metadata']['row_count']);
        $this->assertSame(1, $result['metadata']['ignored_secondary_block_count']);
        $this->assertCount(2, $result['rows']);
        $this->assertSame([2, 3], array_column($result['rows'], 'row_number'));
        $this->assertSame(['30385', '61267'], array_column(array_column($result['rows'], 'data'), 'CODIGO'));
        $this->assertSame('3699', $result['rows'][0]['data']['PRECIOOFERTA']);
        $this->assertSame('OFERTAS', $result['rows'][0]['source_sheet']);
        $this->assertSame($beforeHash, hash_file('sha256', $file));
    }

    public function test_it_does_not_write_output_or_depend_on_database_or_models(): void
    {
        $file = $this->textFile("CODIGO\tPRECIOLISTA\n30385\t3699");
        $relatedPattern = substr($file, 0, -4).'*';
        $filesBefore = glob($relatedPattern);

        $this->reader()->read($file, ExternalFormatSamplesAuditService::WORKFLOW_CATALOG_BODY);

        $this->assertSame($filesBefore, glob($relatedPattern));
        $source = file_get_contents((new ReflectionClass(ExternalRowsReader::class))->getFileName());
        $this->assertIsString($source);
        $this->assertStringNotContainsString('App\\Models', $source);
        $this->assertStringNotContainsString('Facades\\DB', $source);
        $this->assertStringNotContainsString('master_products', $source);
        $this->assertStringNotContainsString('product_change_logs', $source);
        $this->assertStringNotContainsString('File::put', $source);
        $this->assertStringNotContainsString('file_put_contents', $source);
    }

    private function reader(): ExternalRowsReader
    {
        return new ExternalRowsReader(new ExternalFormatSamplesAuditService);
    }

    private function priceMapBuilder(): ExternalPriceMapBuilder
    {
        return new ExternalPriceMapBuilder(
            new ExternalPriceFormatter,
            new ExternalFormatSamplesAuditService,
        );
    }

    private function textFile(string $content, string $encoding = 'UTF-8'): string
    {
        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'external-rows-reader-'.bin2hex(random_bytes(6)).'.txt';

        $bytes = $encoding === 'UTF-8'
            ? $content
            : mb_convert_encoding($content, $encoding, 'UTF-8');

        if (file_put_contents($file, $bytes) === false) {
            throw new RuntimeException('No fue posible escribir el TXT sintético.');
        }

        $this->temporaryFiles[] = $file;

        return $file;
    }

    private function promoWorkbook(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('OFERTAS');
        $sheet->fromArray([
            ['CODIGO', 'MARCA', 'DESCRIPCION', 'PRECIO'],
            ['30385', 'GALLO', 'Arroz curry', '3699'],
            ['61267', 'NORTON', 'Vino elegido', '10999'],
            [],
            [],
            ['NOTA', 'INFORMACION MANUAL'],
            ['TOTAL', '2'],
        ]);
        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'external-rows-reader-'.bin2hex(random_bytes(6)).'.xlsx';
        (new Xlsx($spreadsheet))->save($file);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryFiles[] = $file;

        return $file;
    }
}
