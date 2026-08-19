<?php

namespace Tests\Feature;

use App\Models\ExportJob;
use App\Models\ImportBatch;
use App\Models\ImportFile;
use App\Models\ImportRow;
use App\Models\MasterProduct;
use App\Models\NormalizationRule;
use App\Models\NormalizationSuggestion;
use App\Models\ProductChangeLog;
use App\Models\ProductStagingRow;
use App\Models\User;
use App\Models\ValidationError;
use Dotenv\Dotenv;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
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

    public function test_a_product_staging_row_can_be_linked_to_its_import_origin(): void
    {
        $batch = ImportBatch::factory()->create();
        $file = ImportFile::factory()->for($batch, 'batch')->create();
        $row = ImportRow::factory()
            ->for($batch, 'batch')
            ->for($file, 'file')
            ->create();
        $approver = User::factory()->create();
        $stagingRow = ProductStagingRow::factory()
            ->for($batch, 'batch')
            ->for($file, 'file')
            ->for($row, 'importRow')
            ->for($approver, 'approvedBy')
            ->create();

        $this->assertTrue($stagingRow->batch->is($batch));
        $this->assertTrue($stagingRow->file->is($file));
        $this->assertTrue($stagingRow->importRow->is($row));
        $this->assertTrue($stagingRow->approvedBy->is($approver));
        $this->assertTrue($file->productStagingRows->contains($stagingRow));
        $this->assertTrue($row->productStagingRows->contains($stagingRow));
        $this->assertTrue($approver->approvedProductStagingRows->contains($stagingRow));
    }

    public function test_product_staging_row_casts_analysis_data_and_control_fields(): void
    {
        $stagingRow = ProductStagingRow::factory()->create([
            'raw_data' => ['Sku' => '1001'],
            'detected_data' => ['abbreviation' => 'D/P'],
            'normalized_preview' => ['packaging' => 'DOYPACK'],
            'requires_review' => true,
            'analyzed_at' => now(),
            'approved_at' => now(),
        ]);

        $this->assertIsArray($stagingRow->raw_data);
        $this->assertIsArray($stagingRow->detected_data);
        $this->assertIsArray($stagingRow->normalized_preview);
        $this->assertTrue($stagingRow->requires_review);
        $this->assertInstanceOf(Carbon::class, $stagingRow->analyzed_at);
        $this->assertInstanceOf(Carbon::class, $stagingRow->approved_at);
    }

    public function test_a_product_staging_row_can_have_many_normalization_suggestions(): void
    {
        $stagingRow = ProductStagingRow::factory()->create();
        $firstSuggestion = NormalizationSuggestion::factory()->for($stagingRow, 'stagingRow')->create();
        $secondSuggestion = NormalizationSuggestion::factory()->for($stagingRow, 'stagingRow')->create();

        $this->assertCount(2, $stagingRow->suggestions);
        $this->assertTrue($stagingRow->suggestions->contains($firstSuggestion));
        $this->assertTrue($stagingRow->suggestions->contains($secondSuggestion));
    }

    public function test_a_normalization_rule_can_have_suggestions_and_change_logs(): void
    {
        $creator = User::factory()->create();
        $updater = User::factory()->create();
        $rule = NormalizationRule::factory()
            ->for($creator, 'createdBy')
            ->for($updater, 'updatedBy')
            ->create();
        $suggestion = NormalizationSuggestion::factory()->for($rule, 'rule')->create();
        $changeLog = ProductChangeLog::factory()->for($rule, 'rule')->create();

        $this->assertTrue($rule->suggestions->contains($suggestion));
        $this->assertTrue($rule->changeLogs->contains($changeLog));
        $this->assertTrue($rule->createdBy->is($creator));
        $this->assertTrue($rule->updatedBy->is($updater));
        $this->assertTrue($creator->createdNormalizationRules->contains($rule));
        $this->assertTrue($updater->updatedNormalizationRules->contains($rule));
    }

    public function test_a_normalization_suggestion_links_staging_master_rule_and_reviewer(): void
    {
        $stagingRow = ProductStagingRow::factory()->create();
        $masterProduct = MasterProduct::factory()->create();
        $rule = NormalizationRule::factory()->create();
        $reviewer = User::factory()->create();
        $suggestion = NormalizationSuggestion::factory()
            ->for($stagingRow, 'stagingRow')
            ->for($masterProduct, 'masterProduct')
            ->for($rule, 'rule')
            ->for($reviewer, 'reviewedBy')
            ->create([
                'reviewed_at' => now(),
                'applied_at' => now(),
            ]);

        $this->assertTrue($suggestion->stagingRow->is($stagingRow));
        $this->assertTrue($suggestion->masterProduct->is($masterProduct));
        $this->assertTrue($suggestion->rule->is($rule));
        $this->assertTrue($suggestion->reviewedBy->is($reviewer));
        $this->assertInstanceOf(Carbon::class, $suggestion->reviewed_at);
        $this->assertInstanceOf(Carbon::class, $suggestion->applied_at);
        $this->assertTrue($reviewer->reviewedNormalizationSuggestions->contains($suggestion));
    }

    public function test_a_product_change_log_links_product_user_rule_and_batch(): void
    {
        $masterProduct = MasterProduct::factory()->create();
        $user = User::factory()->create();
        $rule = NormalizationRule::factory()->create();
        $batch = ImportBatch::factory()->create();
        $changeLog = ProductChangeLog::factory()
            ->for($masterProduct, 'masterProduct')
            ->for($user, 'changedBy')
            ->for($rule, 'rule')
            ->for($batch, 'batch')
            ->create();

        $this->assertTrue($changeLog->masterProduct->is($masterProduct));
        $this->assertTrue($changeLog->changedBy->is($user));
        $this->assertTrue($changeLog->rule->is($rule));
        $this->assertTrue($changeLog->batch->is($batch));
        $this->assertTrue($user->productChangeLogs->contains($changeLog));
        $this->assertTrue($batch->productChangeLogs->contains($changeLog));
    }

    public function test_a_product_change_log_does_not_require_updated_at(): void
    {
        $changeLog = ProductChangeLog::factory()->create();

        $this->assertNull($changeLog->getUpdatedAtColumn());
        $this->assertFalse(Schema::hasColumn('product_change_logs', 'updated_at'));
        $this->assertInstanceOf(Carbon::class, $changeLog->created_at);

        $changeLog->update(['new_value' => 'Valor actualizado']);

        $this->assertDatabaseHas('product_change_logs', [
            'id' => $changeLog->id,
            'new_value' => 'Valor actualizado',
        ]);
    }

    public function test_a_master_product_has_staging_rows_suggestions_and_change_logs(): void
    {
        $masterProduct = MasterProduct::factory()->create();
        $stagingRow = ProductStagingRow::factory()->for($masterProduct, 'masterProduct')->create();
        $suggestion = NormalizationSuggestion::factory()->for($masterProduct, 'masterProduct')->create();
        $changeLog = ProductChangeLog::factory()->for($masterProduct, 'masterProduct')->create();

        $this->assertTrue($masterProduct->productStagingRows->contains($stagingRow));
        $this->assertTrue($masterProduct->normalizationSuggestions->contains($suggestion));
        $this->assertTrue($masterProduct->changeLogs->contains($changeLog));
    }

    public function test_an_import_batch_has_product_staging_rows(): void
    {
        $batch = ImportBatch::factory()->create();
        $stagingRow = ProductStagingRow::factory()->for($batch, 'batch')->create();

        $this->assertTrue($batch->productStagingRows->contains($stagingRow));
        $this->assertTrue($stagingRow->batch->is($batch));
    }

    public function test_a_normalization_rule_can_represent_rell_as_manual_review(): void
    {
        $rule = NormalizationRule::factory()->create([
            'rule_name' => 'Revisión manual de RELL.',
            'detected_value' => 'RELL.',
            'replacement_value' => null,
            'rule_type' => 'manual_review',
            'requires_review' => true,
            'requires_preview' => true,
            'is_automatic' => false,
            'active' => true,
        ]);

        $this->assertSame('RELL.', $rule->detected_value);
        $this->assertSame('manual_review', $rule->rule_type);
        $this->assertNull($rule->replacement_value);
        $this->assertTrue($rule->requires_review);
        $this->assertTrue($rule->requires_preview);
        $this->assertFalse($rule->is_automatic);
        $this->assertTrue($rule->active);
    }
}
