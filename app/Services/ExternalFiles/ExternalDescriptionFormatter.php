<?php

namespace App\Services\ExternalFiles;

class ExternalDescriptionFormatter
{
    private const MEASURE_PATTERN = <<<'REGEX'
        ~(?<![\p{L}\p{N}])(\d+(?:[.,]\d+)?)\h*(C\h*\.?\h*C|M\h*\.?\h*L|L(?:\h*\.?\h*T(?:\h*\.?\h*S)?)?|G\h*\.?\h*R(?:\h*\.?\h*S)?|K\h*\.?\h*G(?:\h*\.?\h*S)?|UNIDADES|UNID|UND|UN)\.?(?![\p{L}\p{N}])~iu
        REGEX;

    public function format(?string $description): string
    {
        if ($description === null || $description === '') {
            return (string) $description;
        }

        return preg_replace_callback(
            self::MEASURE_PATTERN,
            static function (array $matches): string {
                $unit = mb_strtoupper(preg_replace('/[^a-z]/iu', '', $matches[2]) ?? '', 'UTF-8');
                $normalizedUnit = match ($unit) {
                    'CC' => 'CC',
                    'ML' => 'ML',
                    'L', 'LT', 'LTS' => 'LT',
                    'GR', 'GRS' => 'GR',
                    'KG', 'KGS' => 'KG',
                    'UN', 'UND', 'UNID', 'UNIDADES' => 'UN',
                };

                return $matches[1].$normalizedUnit;
            },
            $description,
        ) ?? $description;
    }
}
