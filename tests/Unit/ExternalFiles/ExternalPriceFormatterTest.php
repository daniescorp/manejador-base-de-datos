<?php

namespace Tests\Unit\ExternalFiles;

use App\Services\ExternalFiles\ExternalPriceFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ExternalPriceFormatterTest extends TestCase
{
    #[DataProvider('validPrices')]
    public function test_it_formats_external_prices(string|int|float|null $input, string $expected): void
    {
        $result = $this->formatter()->format($input);

        $this->assertSame($expected, $result['formatted_value']);
        $this->assertFalse($result['requires_review']);
        $this->assertNull($result['warning']);
        $this->assertSame($expected === '' ? 'empty' : 'formatted', $result['status']);
    }

    public static function validPrices(): array
    {
        return [
            'integer' => [3699, '$ 3.699'],
            'thousands string' => ['3.699', '$ 3.699'],
            'currency without space' => ['$3.699', '$ 3.699'],
            'currency with space' => ['$ 3.699', '$ 3.699'],
            'zero decimal plain' => ['3699,00', '$ 3.699'],
            'zero decimal thousands' => ['3.699,00', '$ 3.699'],
            'empty' => ['', ''],
            'null' => [null, ''],
        ];
    }

    public function test_non_zero_cents_require_review_without_silent_rounding(): void
    {
        $result = $this->formatter()->format('1699,50');

        $this->assertSame('', $result['formatted_value']);
        $this->assertSame('requires_review', $result['status']);
        $this->assertTrue($result['requires_review']);
        $this->assertStringContainsString('centavos', $result['warning']);
    }

    public function test_it_is_a_pure_service_without_database_or_model_dependencies(): void
    {
        $reflection = new ReflectionClass(ExternalPriceFormatter::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('App\\Models', $source);
        $this->assertStringNotContainsString('Facades\\DB', $source);
        $this->assertStringNotContainsString('master_products', $source);
        $this->assertStringNotContainsString('product_change_logs', $source);
    }

    private function formatter(): ExternalPriceFormatter
    {
        return new ExternalPriceFormatter;
    }
}
