<?php

namespace App\Services\ExternalFiles;

class ExternalPriceFormatter
{
    /**
     * @return array{
     *     original_value: string|int|float|null,
     *     formatted_value: string,
     *     status: 'empty'|'formatted'|'requires_review',
     *     requires_review: bool,
     *     warning: string|null
     * }
     */
    public function format(string|int|float|null $value): array
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $this->result($value, '', 'empty');
        }

        $normalized = $this->normalizedInput($value);

        if ($normalized === '' || preg_match('/^[0-9.,]+$/', $normalized) !== 1) {
            return $this->reviewResult($value, 'El precio no tiene un formato numérico reconocido.');
        }

        $parsed = $this->parse($normalized);

        if ($parsed === null) {
            return $this->reviewResult($value, 'El precio no tiene un formato numérico reconocido.');
        }

        if ($parsed['decimals'] !== '' && preg_match('/^0+$/', $parsed['decimals']) !== 1) {
            return $this->reviewResult(
                $value,
                'El precio contiene centavos distintos de cero y requiere revisión.',
            );
        }

        return $this->result(
            $value,
            '$ '.$this->thousands($parsed['integer']),
            'formatted',
        );
    }

    private function normalizedInput(string|int|float $value): string
    {
        if (is_float($value)) {
            $value = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
        }

        $normalized = preg_replace('/[\s\x{00A0}]+/u', '', (string) $value) ?? (string) $value;

        return str_replace('$', '', $normalized);
    }

    /** @return array{integer: string, decimals: string}|null */
    private function parse(string $value): ?array
    {
        if (preg_match('/^(?<integer>\d{1,3}(?:\.\d{3})+)(?:,(?<decimals>\d+))?$/', $value, $matches) === 1) {
            return [
                'integer' => str_replace('.', '', $matches['integer']),
                'decimals' => $matches['decimals'] ?? '',
            ];
        }

        if (preg_match('/^(?<integer>\d+)(?:,(?<decimals>\d+))?$/', $value, $matches) === 1) {
            return [
                'integer' => $matches['integer'],
                'decimals' => $matches['decimals'] ?? '',
            ];
        }

        if (preg_match('/^(?<integer>\d+)\.(?<decimals>\d{1,2})$/', $value, $matches) === 1) {
            return [
                'integer' => $matches['integer'],
                'decimals' => $matches['decimals'],
            ];
        }

        return null;
    }

    private function thousands(string $integer): string
    {
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;

        return strrev(implode('.', str_split(strrev($integer), 3)));
    }

    /**
     * @return array{
     *     original_value: string|int|float|null,
     *     formatted_value: string,
     *     status: 'empty'|'formatted'|'requires_review',
     *     requires_review: bool,
     *     warning: string|null
     * }
     */
    private function result(
        string|int|float|null $originalValue,
        string $formattedValue,
        string $status,
        ?string $warning = null,
    ): array {
        return [
            'original_value' => $originalValue,
            'formatted_value' => $formattedValue,
            'status' => $status,
            'requires_review' => $status === 'requires_review',
            'warning' => $warning,
        ];
    }

    /**
     * @return array{
     *     original_value: string|int|float|null,
     *     formatted_value: string,
     *     status: 'requires_review',
     *     requires_review: true,
     *     warning: string
     * }
     */
    private function reviewResult(string|int|float|null $value, string $warning): array
    {
        return $this->result($value, '', 'requires_review', $warning);
    }
}
