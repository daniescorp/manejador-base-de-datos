<?php

namespace Tests\Feature;

use App\Models\User;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ProductImageControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql'];

    private string $temporaryDirectory;

    private User $user;

    protected function setUpTraits(): array
    {
        $environmentFile = base_path('.env');

        if (! is_file($environmentFile)) {
            throw new RuntimeException('A MySQL database configuration is required to run the controller tests.');
        }

        $environment = Dotenv::parse(file_get_contents($environmentFile));
        $database = $environment['DB_DATABASE'] ?? null;

        if (blank($database) || $database === ':memory:') {
            throw new RuntimeException('A persistent MySQL database name is required to run the controller tests.');
        }

        config()->set([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => $database,
        ]);

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'product-images-'.Str::uuid();
        File::makeDirectory($this->temporaryDirectory);
        config()->set('product_images.base_path', $this->temporaryDirectory);
        config()->set('app.env', 'local');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_serves_an_existing_png_to_an_authenticated_user(): void
    {
        $contents = $this->createPng('220600');

        $response = $this->get(route('filament.admin.product-images.show', ['code' => '220600']));

        $response->assertSuccessful();
        $response->assertHeader('content-type', 'image/png');
        $response->assertHeader('x-content-type-options', 'nosniff');
        $this->assertSame($contents, $response->streamedContent());
        $this->assertStringNotContainsString(
            $this->temporaryDirectory,
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_it_returns_404_without_exposing_the_base_path_when_missing(): void
    {
        $response = $this->get(route('filament.admin.product-images.show', ['code' => 'NO-EXISTE']));

        $response->assertNotFound();
        $response->assertDontSee($this->temporaryDirectory, false);
    }

    public function test_the_image_route_requires_authentication(): void
    {
        auth()->logout();

        $this->get(route('filament.admin.product-images.show', ['code' => '220600']))
            ->assertRedirect();
    }

    private function createPng(string $code): string
    {
        $contents = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        File::put($this->temporaryDirectory.DIRECTORY_SEPARATOR."{$code}.png", $contents);

        return $contents;
    }
}
