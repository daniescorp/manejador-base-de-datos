<?php

namespace Tests\Unit\ExternalFiles;

use App\Services\Audits\ExternalFormatSamplesAuditService;
use App\Services\Exports\IndesignTxtExportService;
use App\Services\ExternalFiles\ExternalPriceFormatter;
use App\Services\ExternalFiles\ExternalPriceMapBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ExternalPriceMapBuilderTest extends TestCase
{
    public function test_it_builds_a_stable_formatted_map_from_supported_aliases(): void
    {
        $result = $this->builder()->build([
            [
                'Código' => '30385',
                'Precio Lista' => 3699,
                'precio_oferta' => '',
                'PRECIOTACHADO' => null,
                '@folder' => '.\\imagenes\\30385.png',
                'MARCA' => 'GALLO',
            ],
            [
                'Sku' => '61267',
                'precio_lista' => 10999,
                'PRECIOOFERTA' => 8999,
                'Precio Tachado' => 12999,
                'Conca' => 'ignorado',
            ],
        ]);

        $this->assertSame(['30385', '61267'], array_map('strval', array_keys($result['price_map'])));
        $this->assertSame([
            'precio_lista' => '$ 3.699',
            'precio_oferta' => '',
            'precio_tachado' => '',
        ], $result['price_map']['30385']);
        $this->assertSame([
            'precio_lista' => '$ 10.999',
            'precio_oferta' => '$ 8.999',
            'precio_tachado' => '$ 12.999',
        ], $result['price_map']['61267']);
        $this->assertFalse($result['requires_review']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame(4, $result['formatted_count']);
        $this->assertSame(2, $result['empty_price_count']);
        $this->assertSame(0, $result['blocked_count']);
        $this->assertSame(0, $result['review_count']);
        $this->assertSame(0, $result['duplicate_code_count']);
        $this->assertSame(0, $result['invalid_code_count']);
    }

    public function test_real_cents_require_review_and_leave_no_exportable_value(): void
    {
        $result = $this->builder()->build([
            ['codigo' => '30385', 'PRECIOLISTA' => '1699,50'],
        ]);
        $warning = $result['warnings'][0];

        $this->assertSame('', $result['price_map']['30385']['precio_lista']);
        $this->assertTrue($result['requires_review']);
        $this->assertSame(1, $result['review_count']);
        $this->assertSame(0, $result['blocked_count']);
        $this->assertSame('30385', $warning['code']);
        $this->assertSame(1, $warning['row_number']);
        $this->assertSame('PRECIOLISTA', $warning['field']);
        $this->assertSame('1699,50', $warning['original_value']);
        $this->assertSame('price_requires_review', $warning['issue']);
        $this->assertSame('review', $warning['severity']);
        $this->assertStringContainsString('centavos', $warning['message']);
    }

    public function test_missing_and_special_codes_do_not_enter_the_normal_map(): void
    {
        $result = $this->builder()->build([
            ['PRECIOLISTA' => 100],
            ['CODIGO' => 'VARIOS', 'PRECIOLISTA' => 200],
            ['CODIGO' => '40104 - 40105', 'PRECIOLISTA' => 300],
            ['CODIGO' => '60157 -', 'PRECIOLISTA' => 400],
            ['CODIGO' => 'NO-VALIDO', 'PRECIOLISTA' => 500],
        ]);
        $warnings = collect($result['warnings'])->keyBy('issue');

        $this->assertSame([], $result['price_map']);
        $this->assertSame(5, $result['invalid_code_count']);
        $this->assertSame(5, $result['review_count']);
        $this->assertSame(3, $result['blocked_count']);
        $this->assertSame(1, $warnings['missing_code']['row_number']);
        $this->assertSame('grouped_varios', $warnings['grouped_varios_not_mapped']['line_type']);
        $this->assertSame(['40104', '40105'], $warnings['composite_code_not_mapped']['component_codes']);
        $this->assertSame('review', $warnings['composite_code_not_mapped']['severity']);
        $this->assertSame(['60157'], $warnings['incomplete_composite_code']['component_codes']);
        $this->assertTrue($warnings['incomplete_composite_code']['missing_component']);
        $this->assertSame('blocked', $warnings['incomplete_composite_code']['severity']);
        $this->assertSame('invalid_code', $warnings['invalid_code']['issue']);
    }

    public function test_equal_duplicate_prices_are_deduplicated_without_breaking_the_map(): void
    {
        $result = $this->builder()->build([
            ['CODIGO' => '30385', 'PRECIOLISTA' => 3699],
            ['SKU' => '30385', 'precio_lista' => '$3.699'],
        ]);

        $this->assertCount(1, $result['price_map']);
        $this->assertSame('$ 3.699', $result['price_map']['30385']['precio_lista']);
        $this->assertSame(1, $result['duplicate_code_count']);
        $this->assertFalse($result['requires_review']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_different_duplicate_prices_keep_the_first_record_and_require_review(): void
    {
        $result = $this->builder()->build([
            ['CODIGO' => '30385', 'PRECIOLISTA' => 3699],
            ['CODIGO' => '30385', 'PRECIOLISTA' => 4999],
        ]);
        $warning = $result['warnings'][0];

        $this->assertSame('$ 3.699', $result['price_map']['30385']['precio_lista']);
        $this->assertSame(1, $result['duplicate_code_count']);
        $this->assertTrue($result['requires_review']);
        $this->assertSame(1, $result['blocked_count']);
        $this->assertSame('duplicate_price_code', $warning['issue']);
        $this->assertSame(2, $warning['row_number']);
        $this->assertSame('blocked', $warning['severity']);
        $this->assertSame('$ 3.699', $warning['first_prices']['precio_lista']);
        $this->assertSame('$ 4.999', $warning['duplicate_prices']['precio_lista']);
    }

    public function test_map_is_compatible_with_the_indesign_export_price_contract(): void
    {
        $formatter = new ExternalPriceFormatter;
        $result = $this->builder($formatter)->build([
            [
                'CODIGO' => '30385',
                'PRECIOLISTA' => 3699,
                'PRECIOOFERTA' => 2999,
                'PRECIOTACHADO' => 3999,
            ],
        ]);
        $exportPrices = (new IndesignTxtExportService($formatter))
            ->formatExternalPrices($result['price_map']['30385']);

        $this->assertSame($result['price_map']['30385'], $exportPrices['formatted_values']);
        $this->assertFalse($exportPrices['requires_review']);
    }

    public function test_it_is_pure_and_has_no_database_or_product_model_dependencies(): void
    {
        $source = file_get_contents((new ReflectionClass(ExternalPriceMapBuilder::class))->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('App\\Models', $source);
        $this->assertStringNotContainsString('Facades\\DB', $source);
        $this->assertStringNotContainsString('master_products', $source);
        $this->assertStringNotContainsString('product_change_logs', $source);
    }

    private function builder(?ExternalPriceFormatter $formatter = null): ExternalPriceMapBuilder
    {
        return new ExternalPriceMapBuilder(
            $formatter ?? new ExternalPriceFormatter,
            new ExternalFormatSamplesAuditService,
        );
    }
}
