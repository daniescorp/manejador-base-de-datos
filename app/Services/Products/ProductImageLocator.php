<?php

namespace App\Services\Products;

class ProductImageLocator
{
    /**
     * @return array{
     *     status: 'found'|'missing'|'not_configured'|'invalid',
     *     exists: bool,
     *     code: string|null,
     *     filename: string|null,
     *     full_path: string|null
     * }
     */
    public function findByCode(string|int|null $code): array
    {
        $normalizedCode = trim((string) $code);

        if ($normalizedCode === '' || preg_match('/\A[A-Za-z0-9_-]+\z/u', $normalizedCode) !== 1) {
            return $this->result('invalid', false, null, null, null);
        }

        $filename = "{$normalizedCode}.png";
        $basePath = trim((string) config('product_images.base_path'));

        if ($basePath === '') {
            return $this->result('not_configured', false, $normalizedCode, $filename, null);
        }

        $fullPath = rtrim($basePath, '\\/').DIRECTORY_SEPARATOR.$filename;

        if (@is_file($fullPath)) {
            return $this->result('found', true, $normalizedCode, $filename, $fullPath);
        }

        return $this->result('missing', false, $normalizedCode, $filename, null);
    }

    /**
     * @return array{
     *     status: 'found'|'missing'|'not_configured'|'invalid',
     *     exists: bool,
     *     code: string|null,
     *     filename: string|null,
     *     full_path: string|null
     * }
     */
    private function result(
        string $status,
        bool $exists,
        ?string $code,
        ?string $filename,
        ?string $fullPath,
    ): array {
        return [
            'status' => $status,
            'exists' => $exists,
            'code' => $code,
            'filename' => $filename,
            'full_path' => $fullPath,
        ];
    }
}
