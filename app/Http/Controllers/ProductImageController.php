<?php

namespace App\Http\Controllers;

use App\Services\Products\ProductImageLocator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductImageController extends Controller
{
    public function __invoke(string $code, ProductImageLocator $locator): BinaryFileResponse
    {
        $image = $locator->findByCode($code);

        abort_unless($image['status'] === 'found' && $image['full_path'] !== null, 404);

        return response()->file($image['full_path'], [
            'Content-Type' => 'image/png',
            'Content-Disposition' => "inline; filename=\"{$image['filename']}\"",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
