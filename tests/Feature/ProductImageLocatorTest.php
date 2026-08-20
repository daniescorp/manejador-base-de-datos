<?php

namespace Tests\Feature;

use App\Services\Products\ProductImageLocator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductImageLocatorTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'product-images-'.Str::uuid();
        File::makeDirectory($this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_reports_when_the_base_path_is_not_configured(): void
    {
        config()->set('product_images.base_path');

        $result = $this->locator()->findByCode('220600');

        $this->assertSame('not_configured', $result['status']);
        $this->assertFalse($result['exists']);
        $this->assertSame('220600.png', $result['filename']);
        $this->assertNull($result['full_path']);
    }

    public function test_it_finds_only_the_expected_png_for_a_trimmed_code(): void
    {
        config()->set('product_images.base_path', $this->temporaryDirectory);
        $expectedPath = $this->createPng('220600');

        $result = $this->locator()->findByCode(' 220600 ');

        $this->assertSame('found', $result['status']);
        $this->assertTrue($result['exists']);
        $this->assertSame('220600', $result['code']);
        $this->assertSame('220600.png', $result['filename']);
        $this->assertSame($expectedPath, $result['full_path']);
    }

    public function test_it_reports_a_missing_expected_png(): void
    {
        config()->set('product_images.base_path', $this->temporaryDirectory);

        $result = $this->locator()->findByCode('NO-EXISTE');

        $this->assertSame('missing', $result['status']);
        $this->assertFalse($result['exists']);
        $this->assertSame('NO-EXISTE.png', $result['filename']);
        $this->assertNull($result['full_path']);
    }

    public function test_it_rejects_empty_and_null_codes(): void
    {
        foreach ([null, '', '   '] as $code) {
            $result = $this->locator()->findByCode($code);

            $this->assertSame('invalid', $result['status']);
            $this->assertFalse($result['exists']);
            $this->assertNull($result['filename']);
            $this->assertNull($result['full_path']);
        }
    }

    public function test_it_prevents_path_traversal_and_non_code_characters(): void
    {
        config()->set('product_images.base_path', $this->temporaryDirectory);

        foreach (['../secret', '..\\secret', 'folder/file', 'code.png', '%2e%2e'] as $code) {
            $result = $this->locator()->findByCode($code);

            $this->assertSame('invalid', $result['status']);
            $this->assertFalse($result['exists']);
            $this->assertNull($result['full_path']);
        }
    }

    private function locator(): ProductImageLocator
    {
        return app(ProductImageLocator::class);
    }

    private function createPng(string $code): string
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR."{$code}.png";
        File::put($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        ));

        return $path;
    }
}
