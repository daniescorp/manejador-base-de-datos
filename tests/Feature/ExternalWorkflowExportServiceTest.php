<?php

namespace Tests\Feature;

use App\Services\ExternalFiles\ExternalWorkflowExportService;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExternalWorkflowExportServiceTest extends TestCase
{
    public function test_it_exports_an_ok_file_preserving_structure_order_and_source(): void
    {
        $source = $this->fixture(implode("\r\n", [
            "CODIGO\tMARCA\tDESCRIPCION\tPRECIOLISTA\t@folder\tPRECIOLISTA\tARTE",
            "10001\tBETA\tACEITE BLEND 900 CC.\t699,00\t.\\imagenes\\10001.png\t1199\tpieza-10001.ai",
            "10002\tALFA\tACCESORIO GRANDE\t$ 3.699\t.\\imagenes\\10002.png\t10.999,00\tpieza-10002.ai",
        ]));
        $beforeHash = hash_file('sha256', $source);
        $writeQueries = [];
        DB::listen(static function (QueryExecuted $query) use (&$writeQueries): void {
            if (preg_match('/^\s*(?:insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql) === 1) {
                $writeQueries[] = $query->sql;
            }
        });

        try {
            $export = app(ExternalWorkflowExportService::class)->export($source, 'catalog_body');
        } finally {
            $afterHash = hash_file('sha256', $source);
            @unlink($source);
        }

        $lines = explode("\r\n", $export['content']);

        $this->assertSame(2, $export['rows']);
        $this->assertSame(7, $export['columns']);
        $this->assertSame("CODIGO\tMARCA\tDESCRIPCION\tPRECIOLISTA\t@folder\tPRECIOLISTA\tARTE", $lines[0]);
        $this->assertSame([
            '10001', 'BETA', 'ACEITE BLEND 900CC', '$ 699', '.\\imagenes\\10001.png', '$ 1.199', 'pieza-10001.ai',
        ], explode("\t", $lines[1]));
        $this->assertSame([
            '10002', 'ALFA', 'ACCESORIO GRANDE', '$ 3.699', '.\\imagenes\\10002.png', '$ 10.999', 'pieza-10002.ai',
        ], explode("\t", $lines[2]));
        $this->assertSame($beforeHash, $afterHash);
        $this->assertSame([], $writeQueries);
    }

    public function test_it_rejects_review_required_files(): void
    {
        $source = $this->fixture("CODIGO\tPRECIOOFERTA\tPRECIOOFERTA\r\n10001\t699\t699,50");

        try {
            $this->expectException(DomainException::class);
            app(ExternalWorkflowExportService::class)->export($source, 'catalog_body');
        } finally {
            @unlink($source);
        }
    }

    public function test_it_rejects_blocked_promotions_files(): void
    {
        $source = $this->fixture("CODIGO\tPRECIOOFERTA\r\n60157 -\t529");

        try {
            $this->expectException(DomainException::class);
            app(ExternalWorkflowExportService::class)->export($source, 'promo_tapa');
        } finally {
            @unlink($source);
        }
    }

    private function fixture(string $content): string
    {
        $path = storage_path('framework/testing/'.Str::uuid().'.txt');
        file_put_contents($path, $content);

        return $path;
    }
}
