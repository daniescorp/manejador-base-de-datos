<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DiagnoseExternalExportCommandTest extends TestCase
{
    /** @var list<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'diagnosis_forbidden');
        config()->set('database.connections.diagnosis_forbidden', [
            'driver' => 'diagnosis_forbidden',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            File::delete($file);
        }

        parent::tearDown();
    }

    public function test_command_exists_and_requires_file_option(): void
    {
        $this->assertArrayHasKey('app:diagnose-external-export', Artisan::all());

        $result = $this->runJson([], 1);

        $this->assertSame('error', $result['status']);
        $this->assertSame('La opción --file es obligatoria.', $result['message']);
    }

    public function test_promo_tapa_reports_price_map_special_codes_and_blocking_warning(): void
    {
        $file = $this->textFile(
            "CODIGO\tPRECIOLISTA\tPRECIOOFERTA\tPRECIOTACHADO\n"
            ."30385\t\t3699\t\n"
            ."VARIOS\t\t1499\t\n"
            ."40104 - 40105\t\t1699\t\n"
            ."60157 -\t\t529\t",
        );
        $hash = hash_file('sha256', $file);

        $result = $this->runJson([
            '--file' => $file,
            '--workflow' => 'promo_tapa',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('promo_tapa', $result['workflow_type']);
        $this->assertSame('txt', $result['format']);
        $this->assertSame('tab', $result['delimiter']);
        $this->assertSame(4, $result['column_count']);
        $this->assertSame(4, $result['rows_count']);
        $this->assertSame(1, $result['price_map_count']);
        $this->assertSame(3, $result['warning_count']);
        $this->assertSame(2, $result['review_count']);
        $this->assertSame(1, $result['blocked_count']);
        $this->assertFalse($result['can_export_automatically']);
        $this->assertSame([
            'grouped_varios_not_mapped',
            'composite_code_not_mapped',
            'incomplete_composite_code',
        ], array_column($result['warnings'], 'issue'));
        $this->assertSame('60157 -', $result['warnings'][2]['original_value']);
        $this->assertSame('blocked', $result['warnings'][2]['severity']);
        $this->assertSame([
            'product_count' => 1,
            'grouped_varios_count' => 1,
            'composite_code_count' => 1,
            'incomplete_composite_code_count' => 1,
            'duplicate_catalog_section_count' => 0,
            'price_requires_review_count' => 3,
        ], $result['summary']);
        $this->assertSame($hash, hash_file('sha256', $file));
    }

    public function test_review_warning_without_blocking_warning_requires_review(): void
    {
        $file = $this->textFile(
            "CODIGO\tPRECIOOFERTA\n30385\t3699\n40104 - 40105\t1699",
        );

        $result = $this->runJson([
            '--file' => $file,
            '--workflow' => 'promo_tapa',
        ]);

        $this->assertSame('review_required', $result['status']);
        $this->assertSame(1, $result['price_map_count']);
        $this->assertSame(1, $result['warning_count']);
        $this->assertSame(1, $result['review_count']);
        $this->assertSame(0, $result['blocked_count']);
        $this->assertFalse($result['can_export_automatically']);
        $this->assertSame('composite_code_not_mapped', $result['warnings'][0]['issue']);
    }

    public function test_clean_catalog_body_is_ok_and_builds_price_map(): void
    {
        $file = $this->textFile(
            "CODIGO\tMARCA\tDESCRIPCION\tPRECIOLISTA\n30385\tGALLO\tACEITE BLEND 900 CC.\t3699",
        );

        $result = $this->runJson([
            '--file' => $file,
            '--workflow' => 'catalog_body',
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('catalog_body', $result['workflow_type']);
        $this->assertSame(1, $result['rows_count']);
        $this->assertSame(1, $result['price_map_count']);
        $this->assertSame(0, $result['warning_count']);
        $this->assertSame(0, $result['review_count']);
        $this->assertSame(0, $result['blocked_count']);
        $this->assertTrue($result['can_export_automatically']);
        $this->assertSame(1, $result['summary']['product_count']);
        $this->assertSame([
            'precio_lista' => '$ 3.699',
            'precio_oferta' => '',
            'precio_tachado' => '',
        ], $result['price_map']['30385']);
        $this->assertSame('30385', $result['preview_rows'][0]['code']);
        $this->assertSame('GALLO', $result['preview_rows'][0]['brand']);
        $this->assertSame('ACEITE BLEND 900CC', $result['preview_rows'][0]['description']);
        $this->assertSame(0, $result['warning_count']);
        $this->assertSame('$ 3.699', $result['preview_rows'][0]['price_list']);
        $this->assertSame('ok', $result['preview_rows'][0]['status']);
    }

    public function test_measure_normalization_only_changes_preview_and_never_diagnosis_counts(): void
    {
        $file = $this->textFile(implode("\n", [
            "CODIGO\tMARCA\tDESCRIPCION\tUXB\tPRECIOLISTA",
            "10001\tMARCA A\tACEITE BLEND 900 CC.\t12\t1994",
            "10002\tMARCA B\tACEITE OLIVA Pet 500 CC.\t6\t2400",
            "10003\tMARCA C\tPRODUCTO 240 GR.\t4\t3500",
            "10004\tMARCA D\tPRODUCTO 500 ML.\t8\t1800",
            "10005\tMARCA E\tPRODUCTO 1 LT.\t10\t900",
        ]));
        $beforeHash = hash_file('sha256', $file);

        $result = $this->runJson([
            '--file' => $file,
            '--workflow' => 'catalog_body',
        ]);

        $this->assertSame(5, $result['rows_count']);
        $this->assertSame(5, $result['summary']['product_count']);
        $this->assertSame(5, $result['price_map_count']);
        $this->assertSame(0, $result['warning_count']);
        $this->assertSame([
            'ACEITE BLEND 900CC',
            'ACEITE OLIVA Pet 500CC',
            'PRODUCTO 240GR',
            'PRODUCTO 500ML',
            'PRODUCTO 1LT',
        ], array_column($result['preview_rows'], 'description'));
        $this->assertStringContainsString('ACEITE BLEND 900 CC.', file_get_contents($file));
        $this->assertStringContainsString('ACEITE OLIVA Pet 500 CC.', file_get_contents($file));
        $this->assertSame($beforeHash, hash_file('sha256', $file));
    }

    public function test_diagnosis_does_not_require_database_or_generate_export_txt(): void
    {
        $file = $this->textFile("CODIGO\tPRECIOOFERTA\n30385\t3699");
        $exportsBefore = File::glob(storage_path('app/exports/*.txt')) ?: [];

        $result = $this->runJson([
            '--file' => $file,
            '--workflow' => 'promo_tapa',
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame($exportsBefore, File::glob(storage_path('app/exports/*.txt')) ?: []);
        $this->assertArrayNotHasKey('file_path', $result);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function runJson(array $options, int $expectedExitCode = 0): array
    {
        $exitCode = Artisan::call('app:diagnose-external-export', [
            ...$options,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame($expectedExitCode, $exitCode, $output);

        try {
            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Invalid command JSON: '.json_encode($output), previous: $exception);
        }
    }

    private function textFile(string $content): string
    {
        $path = storage_path('framework/testing/external-diagnosis-'.Str::uuid().'.txt');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);
        $this->createdFiles[] = $path;

        return $path;
    }
}
