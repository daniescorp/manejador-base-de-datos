<?php

namespace App\Services\Normalization;

use App\Models\NormalizationRule;

class DescriptionRulePattern
{
    /**
     * @return array{0: string, 1: bool}
     */
    public function replace(string $text, NormalizationRule $rule): array
    {
        $replacementCount = 0;
        $measurement = $this->measurementDefinition($rule);

        if ($measurement !== null) {
            $combined = preg_replace_callback(
                $measurement['pattern'],
                static fn (array $matches): string => $matches['quantity'].$measurement['unit'],
                $text,
                -1,
                $replacementCount,
            );

            return [$combined ?? $text, $combined !== null && $replacementCount > 0];
        }

        $combined = preg_replace_callback(
            $this->pattern($rule),
            static fn (): string => (string) $rule->replacement_value,
            $text,
            -1,
            $replacementCount,
        );

        return [$combined ?? $text, $combined !== null && $replacementCount > 0];
    }

    public function matches(string $text, NormalizationRule $rule): bool
    {
        return preg_match($this->pattern($rule), $text) === 1;
    }

    private function pattern(NormalizationRule $rule): string
    {
        $measurement = $this->measurementDefinition($rule);

        if ($measurement !== null) {
            return $measurement['pattern'];
        }

        if ($rule->rule_type === 'abbreviation'
            && in_array(
                mb_strtoupper(trim((string) $rule->detected_value), 'UTF-8'),
                ['CEBOLL', 'MX'],
                true,
            )) {
            $token = preg_quote((string) $rule->detected_value, '~');

            return "~(?<![\\p{L}\\p{N}_]){$token}(?![\\p{L}\\p{N}_])~iu";
        }

        $literal = preg_quote((string) $rule->detected_value, '~');

        return "~{$literal}~iu";
    }

    /**
     * @return array{pattern: string, unit: string}|null
     */
    private function measurementDefinition(NormalizationRule $rule): ?array
    {
        if ($rule->rule_type !== 'measurement') {
            return null;
        }

        if (preg_match(
            '/^\s*\d+(?:[.,]\d+)?\s*(GR|KG|CC|LT|MT)\.?\s*$/iu',
            (string) $rule->detected_value,
            $matches,
        ) !== 1) {
            return null;
        }

        $unit = mb_strtoupper($matches[1], 'UTF-8');
        $unitPattern = match ($unit) {
            'GR' => '(?:GRS|GR|G)',
            'KG' => '(?:KGS|KG|K)',
            'CC' => 'CC',
            'LT' => '(?:LTS|LT|L)',
            'MT' => '(?:MTS|MT)',
        };

        return [
            'pattern' => "~(?<![\\p{L}\\p{N}])(?<quantity>\\d+(?:[.,]\\d+)?)\\h*{$unitPattern}\\.?(?![\\p{L}\\p{N}])~iu",
            'unit' => $unit,
        ];
    }
}
