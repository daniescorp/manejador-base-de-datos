<?php

namespace Tests\Feature;

use App\Models\ExportJob;
use App\Models\ImportBatch;
use App\Models\ImportFile;
use App\Models\ImportRow;
use App\Models\MasterProduct;
use App\Models\User;
use App\Models\ValidationError;
use Dotenv\Dotenv;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class BaseDataModelTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql'];

    protected function setUpTraits(): array
    {
        $environmentFile = base_path('.env');

        if (! is_file($environmentFile)) {
            throw new RuntimeException('A MySQL database configuration is required to run the domain tests.');
        }

        $environment = Dotenv::parse(file_get_contents($environmentFile));
        $database = $environment['DB_DATABASE'] ?? null;

        if (blank($database) || ($database === ':memory:')) {
            throw new RuntimeException('A persistent MySQL database name is required to run the domain tests.');
        }

        config()->set([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => $database,
        ]);

        return parent::setUpTraits();
    }

    public function test_an_import_batch_can_be_created(): void
    {
        $batch = ImportBatch::factory()->create([
            'name' => 'Carga inicial',
        ]);

        $this->assertDatabaseHas('import_batches', [
            'id' => $batch->id,
            'name' => 'Carga inicial',
        ]);
    }

    public function test_an_import_batch_has_import_files(): void
    {
        $batch = ImportBatch::factory()->create();
        $file = ImportFile::factory()->for($batch, 'batch')->create();

        $this->assertTrue($batch->files->contains($file));
        $this->assertTrue($file->batch->is($batch));
    }

    public function test_an_import_file_has_import_rows(): void
    {
        $batch = ImportBatch::factory()->create();
        $file = ImportFile::factory()->for($batch, 'batch')->create();
        $row = ImportRow::factory()
            ->for($batch, 'batch')
            ->for($file, 'file')
            ->create();

        $this->assertTrue($file->rows->contains($row));
        $this->assertTrue($row->file->is($file));
    }

    public function test_an_import_row_has_validation_errors(): void
    {
        $batch = ImportBatch::factory()->create();
        $file = ImportFile::factory()->for($batch, 'batch')->create();
        $row = ImportRow::factory()
            ->for($batch, 'batch')
            ->for($file, 'file')
            ->create();
        $error = ValidationError::factory()->for($row, 'row')->create();

        $this->assertTrue($row->validationErrors->contains($error));
        $this->assertTrue($error->row->is($row));
    }

    public function test_a_master_product_has_validation_errors(): void
    {
        $product = MasterProduct::factory()->create();
        $error = ValidationError::factory()->for($product, 'product')->create();

        $this->assertTrue($product->validationErrors->contains($error));
        $this->assertTrue($error->product->is($product));
    }

    public function test_an_export_job_belongs_to_a_user(): void
    {
        $user = User::factory()->create();
        $exportJob = ExportJob::factory()->for($user, 'createdBy')->create();

        $this->assertTrue($exportJob->createdBy->is($user));
    }

    public function test_json_columns_are_cast_to_arrays(): void
    {
        $batch = ImportBatch::factory()->create();
        $file = ImportFile::factory()->for($batch, 'batch')->create();
        $row = ImportRow::factory()
            ->for($batch, 'batch')
            ->for($file, 'file')
            ->create();
        $product = MasterProduct::factory()->create();
        $error = ValidationError::factory()->create();
        $exportJob = ExportJob::factory()->create();

        $this->assertIsArray($file->meta);
        $this->assertIsArray($row->raw_data);
        $this->assertIsArray($row->normalized_data);
        $this->assertIsArray($product->data);
        $this->assertIsArray($error->context);
        $this->assertIsArray($exportJob->meta);
    }

    public function test_master_products_use_soft_deletes(): void
    {
        $product = MasterProduct::factory()->create();

        $product->delete();

        $this->assertSoftDeleted($product);
        $this->assertNull(MasterProduct::find($product->id));
        $this->assertNotNull(MasterProduct::withTrashed()->find($product->id));
    }

    public function test_an_import_file_cannot_have_duplicate_row_numbers(): void
    {
        $batch = ImportBatch::factory()->create();
        $file = ImportFile::factory()->for($batch, 'batch')->create();

        ImportRow::factory()
            ->for($batch, 'batch')
            ->for($file, 'file')
            ->create(['row_number' => 10]);

        $this->expectException(QueryException::class);

        ImportRow::factory()
            ->for($batch, 'batch')
            ->for($file, 'file')
            ->create(['row_number' => 10]);
    }
}
