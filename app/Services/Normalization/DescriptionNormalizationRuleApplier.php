<?php

namespace App\Services\Normalization;

use App\Models\NormalizationRule;

class DescriptionNormalizationRuleApplier
{
    private const DESCRIPTION_FIELD = 'descripcion_catalogo';

    public function __construct(
        private readonly DescriptionRulePattern $pattern,
    ) {}

    /**
     * @return array{
     *     original: string,
     *     normalized: string,
     *     changed: bool,
     *     applied_rules: list<array<string, mixed>>,
     *     pending_suggestions: list<array<string, mixed>>
     * }
     */
    public function apply(string $description, string $homologatedBrand): array
    {
        $normalized = $description;
        $appliedRules = [];
        $pendingSuggestions = [];

        foreach ($this->activeRules() as $rule) {
            $evaluation = $this->evaluateRule($normalized, $homologatedBrand, $rule);

            if (! $evaluation['matched']) {
                continue;
            }

            if ($evaluation['pending_review']) {
                $pendingSuggestions[] = $this->ruleData($rule);

                continue;
            }

            if (! $evaluation['changed']) {
                continue;
            }

            $normalized = $evaluation['normalized'];
            $appliedRules[] = $this->ruleData($rule);
        }

        return [
            'original' => $description,
            'normalized' => $normalized,
            'changed' => $normalized !== $description,
            'applied_rules' => $appliedRules,
            'pending_suggestions' => $pendingSuggestions,
        ];
    }

    /**
     * @return array{matched: bool, normalized: string, changed: bool, pending_review: bool}
     */
    public function evaluateRule(
        string $description,
        string $homologatedBrand,
        ?NormalizationRule $rule,
    ): array {
        $matched = $rule !== null
            && $rule->active
            && $rule->rule_type === 'description_normalization'
            && $rule->applies_to_field === self::DESCRIPTION_FIELD
            && filled($rule->detected_value)
            && $this->pattern->matchesHomologatedBrandContext($homologatedBrand, $rule)
            && $this->pattern->matches($description, $rule);

        if (! $matched) {
            return [
                'matched' => false,
                'normalized' => $description,
                'changed' => false,
                'pending_review' => false,
            ];
        }

        if ($rule->requires_review || ! $rule->is_automatic) {
            return [
                'matched' => true,
                'normalized' => $description,
                'changed' => false,
                'pending_review' => true,
            ];
        }

        [$normalized, $changed] = $this->pattern->replace($description, $rule);

        return [
            'matched' => true,
            'normalized' => $normalized,
            'changed' => $changed,
            'pending_review' => false,
        ];
    }

    /** @return iterable<int, NormalizationRule> */
    private function activeRules(): iterable
    {
        return NormalizationRule::query()
            ->where('active', true)
            ->where('rule_type', 'description_normalization')
            ->where('applies_to_field', self::DESCRIPTION_FIELD)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function ruleData(NormalizationRule $rule): array
    {
        return [
            'id' => $rule->getKey(),
            'name' => $rule->rule_name,
            'detected_value' => $rule->detected_value,
            'replacement_value' => $rule->replacement_value,
            'context' => $rule->context,
        ];
    }
}
