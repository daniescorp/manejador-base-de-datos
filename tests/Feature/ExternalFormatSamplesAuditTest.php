<?php

namespace Tests\Feature;

use App\Services\Audits\ExternalFormatSamplesAuditService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

class ExternalFormatSamplesAuditTest extends TestCase
{
    /** @var array<int, string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }

        parent::tearDown();
    }

    public function test_it_detects_tab_and_semicolon_text_files(): void
    {
        $tab = $this->textFile("CODIGO\tMARCA\tDESCRIPCION\n30385\tGALLO\tArroz curry");
        $semicolon = $this->textFile("CODIGO;MARCA;DESCRIPCION\n61267;NORTON;Vino elegido");

        $this->assertSame('TAB', $this->service()->auditTextFile($tab)['delimiter']);
        $this->assertSame('semicolon', $this->service()->auditTextFile($semicolon)['delimiter']);
    }

    public function test_it_reports_a_genuinely_mixed_delimiter(): void
    {
        $file = $this->textFile(
            "CODIGO\tMARCA\tDESCRIPCION\n"
            .'30385;GALLO;Arroz curry',
        );

        $this->assertSame('mixed', $this->service()->auditTextFile($file)['delimiter']);
    }

    public function test_it_classifies_codes_according_to_the_workflow(): void
    {
        $service = $this->service();

        $product = $service->classifyCode('30385', ExternalFormatSamplesAuditService::WORKFLOW_CATALOG_BODY);
        $this->assertSame('product', $product['line_type']);
        $this->assertTrue($product['requires_master_lookup']);
        $this->assertTrue($product['exportable_automatically']);

        foreach (['40104 - 40105', '260161 - 260179', '60157 -'] as $code) {
            $classification = $service->classifyCode(
                $code,
                ExternalFormatSamplesAuditService::WORKFLOW_CATALOG_BODY,
            );

            $this->assertSame('composite_code', $classification['line_type']);
            $this->assertFalse($classification['requires_master_lookup']);
            $this->assertSame('invalid_for_catalog_body', $classification['workflow_status']);
            $this->assertTrue($classification['requires_review']);
            $this->assertFalse($classification['exportable_automatically']);
        }

        $catalogVarios = $service->classifyCode(
            'VARIOS',
            ExternalFormatSamplesAuditService::WORKFLOW_CATALOG_BODY,
        );
        $this->assertSame('grouped_varios', $catalogVarios['line_type']);
        $this->assertSame('invalid_for_catalog_body', $catalogVarios['workflow_status']);
        $this->assertTrue($catalogVarios['requires_review']);
        $this->assertFalse($catalogVarios['exportable_automatically']);

        $promoVarios = $service->classifyCode(
            'VARIOS',
            ExternalFormatSamplesAuditService::WORKFLOW_PROMO_TAPA,
        );
        $this->assertSame('grouped_varios', $promoVarios['line_type']);
        $this->assertSame('valid', $promoVarios['workflow_status']);
        $this->assertFalse($promoVarios['requires_master_lookup']);
        $this->assertFalse($promoVarios['requires_review']);
        $this->assertTrue($promoVarios['exportable_automatically']);
    }

    public function test_duplicate_headers_and_irregular_rows_are_reported_without_breaking_audit(): void
    {
        $file = $this->textFile(
            "CODIGO\tMARCA\t@folder\t@folder\n"
            ."VARIOS\tMARUCHAN\t.\\imagenes\\VARIOS.png\tcontenedor-rojo.ai\n"
            ."40104 - 40105\tMARCA\t.\\imagenes\\40104.png",
        );

        $report = $this->service()->auditTextFile($file);

        $this->assertSame(['@folder'], $report['duplicate_headers']);
        $this->assertSame(1, $report['irregular_row_count']);
        $this->assertSame(1, $report['line_types']['grouped_varios']);
        $this->assertSame(1, $report['line_types']['composite_code']);
        $this->assertSame(2, $report['image_path_count']);
        $this->assertSame(1, $report['container_ai_path_count']);
    }

    public function test_it_audits_a_workbook_with_a_primary_and_secondary_block(): void
    {
        $file = $this->workbook();
        $report = $this->service()->auditWorkbook($file);

        $this->assertSame(['OFERTAS'], $report['sheet_names']);
        $this->assertSame('A1:D7', $report['sheets'][0]['dimension']);
        $this->assertSame('A1:D3', $report['first_table']['range']);
        $this->assertSame(2, $report['first_table']['useful_rows']);
        $this->assertSame(1, $report['first_table']['line_types']['product']);
        $this->assertSame(1, $report['first_table']['line_types']['grouped_varios']);
        $this->assertCount(1, $report['secondary_blocks']);
        $this->assertSame('A6:B7', $report['secondary_blocks'][0]['range']);
    }

    public function test_audit_does_not_execute_database_queries_or_write_a_report_by_default(): void
    {
        $directory = $this->directory();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->textFile(
            "CODIGO\tMARCA\tDESCRIPCION\nVARIOS\tQUARA\tVino varietal 750cc",
            $directory.DIRECTORY_SEPARATOR.ExternalFormatSamplesAuditService::PROMOTIONS_OUTPUT,
        );

        $report = $this->service()->auditDirectory($directory);

        $this->assertSame('partial', $report['status']);
        $this->assertTrue($report['read_only']);
        $this->assertSame([], $queries);
        $this->assertSame(
            [ExternalFormatSamplesAuditService::PROMOTIONS_OUTPUT],
            $report['detected_files'],
        );
        $this->assertTrue($report['rules']['promo_tapa']['grouped_varios_allowed']);
        $this->assertFalse($report['rules']['promo_tapa']['grouped_varios_requires_master_lookup']);
        $this->assertFalse($report['rules']['catalog_body']['grouped_varios_allowed']);
        $this->assertSame([], array_values(array_filter(
            scandir($directory) ?: [],
            static fn (string $name): bool => str_ends_with($name, '.json'),
        )));
    }

    public function test_the_command_outputs_json_without_creating_a_default_report(): void
    {
        $directory = $this->directory();
        $this->textFile(
            "CODIGO;MARCA;DESCRIPCION\nVARIOS;DR.LEMON;Aperitivo lata 473cc",
            $directory.DIRECTORY_SEPARATOR.ExternalFormatSamplesAuditService::PROMOTIONS_OUTPUT,
        );

        $exitCode = Artisan::call('app:audit-external-format-samples', [
            '--base-path' => $directory,
            '--json' => true,
            '--sample' => 3,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('partial', $report['status']);
        $this->assertSame('semicolon', $report['promotions']['output']['delimiter']);
        $this->assertSame(1, $report['totals']['line_types']['grouped_varios']);
        $this->assertArrayNotHasKey('output_path', $report);
    }

    public function test_directory_infers_workflows_and_separates_contextual_counts(): void
    {
        $directory = $this->directory();
        $this->textFile(
            "CODIGO\tMARCA\nVARIOS\tMARCA\n40104 - 40105\tMARCA",
            $directory.DIRECTORY_SEPARATOR.'ALMACEN INT.txt',
        );
        $this->textFile(
            "CODIGO\tMARCA\nVARIOS\tQUARA",
            $directory.DIRECTORY_SEPARATOR.ExternalFormatSamplesAuditService::PROMOTIONS_OUTPUT,
        );

        $report = $this->service()->auditDirectory($directory);
        $catalog = $report['catalog']['outputs']['ALMACEN INT.txt'];
        $promo = $report['promotions']['output'];

        $this->assertSame('catalog_body', $catalog['workflow_type']);
        $this->assertSame(1, $catalog['classification_counts']['grouped_varios']);
        $this->assertSame(1, $catalog['classification_counts']['composite_code']);
        $this->assertSame(2, $catalog['classification_counts']['invalid_for_catalog_body']);
        $this->assertSame(2, $catalog['classification_counts']['requires_review']);
        $this->assertFalse($catalog['examples'][0]['exportable_automatically']);

        $this->assertSame('promo_tapa', $promo['workflow_type']);
        $this->assertSame(1, $promo['classification_counts']['grouped_varios']);
        $this->assertSame(0, $promo['classification_counts']['invalid_for_catalog_body']);
        $this->assertSame(0, $promo['classification_counts']['requires_review']);
        $this->assertTrue($promo['examples'][0]['exportable_automatically']);

        $this->assertSame(2, $report['workflow_totals']['catalog_body']['invalid_for_catalog_body']);
        $this->assertSame(1, $report['workflow_totals']['promo_tapa']['grouped_varios']);
    }

    public function test_a_single_catalog_section_does_not_require_duplicate_review(): void
    {
        $report = $this->service()->auditWorkbook(
            $this->catalogWorkbook(['Importados']),
            workflowType: ExternalFormatSamplesAuditService::WORKFLOW_CATALOG_BODY,
        );

        $this->assertFalse($report['has_duplicate_catalog_sections']);
        $this->assertSame([], $report['duplicate_catalog_sections']);
        $this->assertCount(1, $report['catalog_sections']);
        $this->assertSame('importados', $report['catalog_sections'][0]['normalized_section']);
        $this->assertFalse($report['catalog_sections'][0]['requires_review']);
        $this->assertTrue($report['catalog_sections'][0]['exportable_automatically']);
    }

    public function test_duplicate_catalog_sections_are_blocked_without_blocking_other_sections(): void
    {
        $report = $this->service()->auditWorkbook(
            $this->catalogWorkbook(['Importados', 'IMPORTADOS', 'Limpieza']),
            workflowType: ExternalFormatSamplesAuditService::WORKFLOW_CATALOG_BODY,
        );
        $issue = $report['duplicate_catalog_sections'][0];
        $importados = array_values(array_filter(
            $report['catalog_sections'],
            static fn (array $section): bool => $section['normalized_section'] === 'importados',
        ));
        $limpieza = array_values(array_filter(
            $report['catalog_sections'],
            static fn (array $section): bool => $section['normalized_section'] === 'limpieza',
        ))[0];

        $this->assertTrue($report['has_duplicate_catalog_sections']);
        $this->assertSame('duplicate_catalog_section', $issue['problem']);
        $this->assertSame('catalog_body', $issue['workflow_type']);
        $this->assertSame('importados', $issue['normalized_section']);
        $this->assertSame(2, $issue['detected_blocks']);
        $this->assertSame([1, 1], $issue['rows_per_block']);
        $this->assertCount(2, $issue['origins']);
        $this->assertSame('requires_review', $issue['status']);
        $this->assertTrue($issue['requires_review']);
        $this->assertSame('blocked', $issue['severity']);
        $this->assertFalse($issue['exportable_automatically']);
        $this->assertFalse($issue['automatic_selection']);
        $this->assertFalse($issue['merge_blocks']);
        $this->assertSame('manual_selection', $issue['recommendation']);
        $this->assertSame('Se detectaron 2 bloques para Importados. Elegí cuál usar.', $issue['message']);
        $this->assertCount(2, $importados);
        $this->assertTrue(collect($importados)->every(
            static fn (array $section): bool => $section['requires_review']
                && $section['problem'] === 'duplicate_catalog_section'
                && $section['exportable_automatically'] === false,
        ));
        $this->assertFalse($limpieza['requires_review']);
        $this->assertTrue($limpieza['exportable_automatically']);
    }

    public function test_catalog_section_aliases_resolve_to_the_same_export_key(): void
    {
        $report = $this->service()->auditWorkbook(
            $this->catalogWorkbook([
                'Importados',
                'IMPORTADOS INT',
                'Bebidas C/Alcohol',
                'BEBIDAS CON AL INT',
                'Alimentos',
                'ALMACÉN INT',
            ]),
            workflowType: ExternalFormatSamplesAuditService::WORKFLOW_CATALOG_BODY,
        );
        $duplicates = collect($report['duplicate_catalog_sections'])->keyBy('normalized_section');

        $this->assertSame(
            ['almacen', 'bebidas_con_al', 'importados'],
            $duplicates->keys()->sort()->values()->all(),
        );
        $this->assertSame(2, $duplicates['importados']['detected_blocks']);
        $this->assertSame(2, $duplicates['bebidas_con_al']['detected_blocks']);
        $this->assertSame(2, $duplicates['almacen']['detected_blocks']);
    }

    public function test_promo_tapa_does_not_apply_catalog_duplicate_blocking(): void
    {
        $report = $this->service()->auditWorkbook(
            $this->catalogWorkbook(['Importados', 'IMPORTADOS INT']),
            workflowType: ExternalFormatSamplesAuditService::WORKFLOW_PROMO_TAPA,
        );

        $this->assertFalse($report['has_duplicate_catalog_sections']);
        $this->assertSame([], $report['catalog_sections']);
        $this->assertSame([], $report['duplicate_catalog_sections']);
    }

    public function test_duplicate_section_audit_does_not_query_or_modify_product_tables(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->service()->auditWorkbook(
            $this->catalogWorkbook(['Importados', 'IMPORTADOS INT']),
            workflowType: ExternalFormatSamplesAuditService::WORKFLOW_CATALOG_BODY,
        );

        $this->assertSame([], $queries);
        $source = file_get_contents((new \ReflectionClass(ExternalFormatSamplesAuditService::class))->getFileName());
        $this->assertIsString($source);
        $this->assertStringNotContainsString('master_products', $source);
        $this->assertStringNotContainsString('product_change_logs', $source);
        $this->assertStringNotContainsString('Facades\\DB', $source);
    }

    public function test_the_command_writes_json_only_when_output_is_explicit(): void
    {
        $directory = $this->directory();
        $output = $directory.DIRECTORY_SEPARATOR.'audit.json';
        $this->temporaryPaths[] = $output;
        $this->textFile(
            "CODIGO\tMARCA\n30385\tGALLO",
            $directory.DIRECTORY_SEPARATOR.ExternalFormatSamplesAuditService::PROMOTIONS_OUTPUT,
        );

        $exitCode = Artisan::call('app:audit-external-format-samples', [
            '--base-path' => $directory,
            '--json' => true,
            '--output' => $output,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($output);
        $this->assertSame('partial', json_decode(
            file_get_contents($output),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['status']);
    }

    private function service(): ExternalFormatSamplesAuditService
    {
        return app(ExternalFormatSamplesAuditService::class);
    }

    private function directory(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'external-format-audit-'.bin2hex(random_bytes(6));

        if (! mkdir($path) && ! is_dir($path)) {
            throw new RuntimeException('No fue posible crear la carpeta temporal.');
        }

        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function textFile(string $content, ?string $path = null): string
    {
        $path ??= tempnam(sys_get_temp_dir(), 'external-format-audit-txt-');

        if ($path === false || file_put_contents($path, $content) === false) {
            throw new RuntimeException('No fue posible crear el TXT sintético.');
        }

        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function workbook(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('OFERTAS');
        $sheet->fromArray([
            ['CODIGO', 'MARCA', 'DESCRIPCION', 'PRECIOOFERTA'],
            ['30385', 'GALLO', 'Arroz curry', 100],
            ['VARIOS', 'QUARA', 'Vino varietal', 200],
            [],
            [],
            ['NOTA', 'Uso manual'],
            ['TOTAL', 2],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'external-format-audit-xlsx-');

        if ($path === false) {
            throw new RuntimeException('No fue posible crear el XLSX sintético.');
        }

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryPaths[] = $path;

        return $path;
    }

    /** @param array<int, string> $sections */
    private function catalogWorkbook(array $sections): string
    {
        $spreadsheet = new Spreadsheet;

        foreach ($sections as $index => $section) {
            $sheet = $index === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $sheet->setTitle('Origen '.($index + 1));
            $sheet->fromArray([
                ['CATEGORIA', 'CODIGO', 'MARCA', 'DESCRIPCION'],
                [$section, (string) (30000 + $index), 'MARCA', 'Producto sintético'],
            ]);
        }

        $path = tempnam(sys_get_temp_dir(), 'external-format-catalog-xlsx-');

        if ($path === false) {
            throw new RuntimeException('No fue posible crear el XLSX sintético de catálogo.');
        }

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryPaths[] = $path;

        return $path;
    }
}
