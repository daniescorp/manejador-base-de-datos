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
            "CATEGORIA\tGRUPO\tCODIGO\tMARCA\tDESCRIPCION\tUXB\t@IMAGENES\tPRECIOLISTA\t@IMAGENES\t   PRECIOOFERTA  \t PRECIOTACHADO \t@IMAGENES\t@IMAGENES\tConca\tConca",
            "ALMACEN\tACEITES\t10001\tBETA\tACEITE BLEND 900 CC.\t12\t.\\imagenes\\10001.png\t699,00\t\t1199\t\tpieza-10001.ai\t\t.\\imagenes\\\t.png",
            "ALMACEN\tACEITES\t10002\tALFA\tACCESORIO GRANDE\t6\t.\\imagenes\\10002.png\t$ 3.699\t\t10.999,00\t\tpieza-10002.ai\t\t.\\imagenes\\\t.png",
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

        $lines = explode("\r\n", mb_convert_encoding($export['content'], 'UTF-8', 'Windows-1252'));

        $this->assertSame(2, $export['rows']);
        $this->assertSame(15, $export['columns']);
        $this->assertSame("CATEGORIA\tGRUPO\tCODIGO\tMARCA\tDESCRIPCION\tUXB\t@IMAGENES\tPRECIOLISTA\t@IMAGENES\t   PRECIOOFERTA  \t PRECIOTACHADO \t@IMAGENES\t@IMAGENES\tConca\tConca", $lines[0]);
        $this->assertSame([
            'ALMACEN', 'ACEITES', '10001', 'BETA', 'ACEITE BLEND 900CC', '12', '.\\imagenes\\10001.png', '$ 699', '', '$ 1.199', '', 'pieza-10001.ai', '', '.\\imagenes\\', '.png',
        ], explode("\t", $lines[1]));
        $this->assertSame([
            'ALMACEN', 'ACEITES', '10002', 'ALFA', 'ACCESORIO GRANDE', '6', '.\\imagenes\\10002.png', '$ 3.699', '', '$ 10.999', '', 'pieza-10002.ai', '', '.\\imagenes\\', '.png',
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
