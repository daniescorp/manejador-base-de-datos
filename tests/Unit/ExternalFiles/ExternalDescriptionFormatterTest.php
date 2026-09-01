<?php

namespace Tests\Unit\ExternalFiles;

use App\Services\ExternalFiles\ExternalDescriptionFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ExternalDescriptionFormatterTest extends TestCase
{
    #[DataProvider('descriptions')]
    public function test_it_normalizes_safe_external_measurements(string $input, string $expected): void
    {
        $this->assertSame($expected, (new ExternalDescriptionFormatter)->format($input));
    }

    public static function descriptions(): array
    {
        return [
            'cc' => ['ACEITE BLEND 900 CC.', 'ACEITE BLEND 900CC'],
            'cc preserving text case' => ['ACEITE OLIVA Pet 500 CC.', 'ACEITE OLIVA Pet 500CC'],
            'gr' => ['Producto 240 GR.', 'Producto 240GR'],
            'lt' => ['Producto 1 LT.', 'Producto 1LT'],
            'ml' => ['Producto 500 ML.', 'Producto 500ML'],
            'un' => ['Producto 25 UN.', 'Producto 25UN'],
            'lowercase cc' => ['Producto 900 cc.', 'Producto 900CC'],
            'dotted cc' => ['Producto 900 C.C.', 'Producto 900CC'],
            'plural gr' => ['Producto 500 grs.', 'Producto 500GR'],
            'plural lt' => ['Producto 1 lts.', 'Producto 1LT'],
            'kg' => ['Producto 1 KG.', 'Producto 1KG'],
            'long units' => ['Producto 25 unidades.', 'Producto 25UN'],
            'protected words' => ['ACCESORIO GRANDE ML', 'ACCESORIO GRANDE ML'],
            'unit letters inside a word' => ['Producto 900 CCESORIO', 'Producto 900 CCESORIO'],
        ];
    }

    public function test_it_is_a_pure_service_without_database_or_model_dependencies(): void
    {
        $source = file_get_contents((new ReflectionClass(ExternalDescriptionFormatter::class))->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('App\\Models', $source);
        $this->assertStringNotContainsString('Facades\\DB', $source);
        $this->assertStringNotContainsString('master_products', $source);
    }
}
