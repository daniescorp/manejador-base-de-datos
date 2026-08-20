<?php

namespace App\Services\Normalization;

use App\Models\NormalizationRule;
use App\Models\NormalizationSuggestion;
use App\Models\ProductStagingRow;
use Illuminate\Support\Facades\DB;

class ProductStagingAnalyzer
{
    private const DESCRIPTION_FIELD = 'descripcion_catalogo';

    private const BRAND_FIELD = 'marca_homologada';

    private const EDITABLE_SUGGESTION_STATUSES = [
        'pending',
        'ignored',
    ];

    public function analyze(ProductStagingRow $row): void
    {
        DB::transaction(function () use ($row): void {
            $stagingRow = ProductStagingRow::query()
                ->lockForUpdate()
                ->findOrFail($row->getKey());

            $matchedRules = 0;
            $reviewReasons = [];
            $descriptionSource = (string) $stagingRow->nombre_sku_original;
            $descriptionIsBlank = blank(trim($descriptionSource));

            if ($descriptionIsBlank) {
                $reviewReasons[] = 'Nombre Sku original vacío';
            } else {
                foreach ($this->activeDescriptionRules() as $rule) {
                    if (! $this->matchesDescription($descriptionSource, $rule->detected_value)) {
                        continue;
                    }

                    $matchedRules++;
                    $this->storeSuggestion(
                        $stagingRow,
                        $rule,
                        self::DESCRIPTION_FIELD,
                        $descriptionSource,
                    );

                    if ($rule->requires_review) {
                        $reviewReasons[] = "Regla {$rule->detected_value}: requiere revisión manual o contextual";
                    }
                }
            }

            $brandSource = (string) $stagingRow->marca_original;
            $brandIsInvalid = $this->brandIsInvalid($brandSource);

            if ($brandIsInvalid) {
                $reviewReasons[] = 'Marca original vacía o no válida';
            } else {
                foreach ($this->activeBrandRules() as $rule) {
                    if (! $this->matchesBrand($brandSource, $rule->detected_value)) {
                        continue;
                    }

                    $matchedRules++;
                    $this->storeSuggestion(
                        $stagingRow,
                        $rule,
                        self::BRAND_FIELD,
                        $brandSource,
                    );

                    if ($this->brandRuleRequiresReview($rule)) {
                        $reviewReasons[] = "Regla de marca {$rule->detected_value}: requiere revisión manual o contextual";
                    }
                }
            }

            $reviewReason = $stagingRow->review_reason;

            foreach (array_unique($reviewReasons) as $reason) {
                $reviewReason = $this->appendReviewReason($reviewReason, $reason);
            }

            $requiresReview = $stagingRow->requires_review || $reviewReasons !== [];

            $stagingRow->update([
                'status' => $descriptionIsBlank || $brandIsInvalid
                    ? 'requires_review'
                    : ($matchedRules > 0
                        ? 'suggested'
                        : ($requiresReview ? 'requires_review' : 'analyzed')),
                'requires_review' => $requiresReview,
                'review_reason' => $reviewReason,
                'analyzed_at' => now(),
            ]);
        });
    }

    /**
     * @return iterable<int, NormalizationRule>
     */
    private function activeDescriptionRules(): iterable
    {
        return NormalizationRule::query()
            ->where('active', true)
            ->where(function ($query): void {
                $query
                    ->where('applies_to_field', self::DESCRIPTION_FIELD)
                    ->orWhereNull('applies_to_field');
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return iterable<int, NormalizationRule>
     */
    private function activeBrandRules(): iterable
    {
        return NormalizationRule::query()
            ->where('active', true)
            ->where('applies_to_field', self::BRAND_FIELD)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    private function storeSuggestion(
        ProductStagingRow $row,
        NormalizationRule $rule,
        string $fieldName,
        string $source,
    ): void {
        $suggestion = NormalizationSuggestion::query()->firstOrNew([
            'product_staging_row_id' => $row->getKey(),
            'normalization_rule_id' => $rule->getKey(),
            'field_name' => $fieldName,
        ]);

        if ($suggestion->exists
            && ! in_array($suggestion->status, self::EDITABLE_SUGGESTION_STATUSES, true)) {
            return;
        }

        $suggestion->fill([
            'original_value' => $source,
            'suggested_value' => $this->suggestedValue($source, $rule, $fieldName),
            'suggestion_reason' => $this->suggestionReason($rule, $fieldName),
            'confidence_level' => $rule->confidence_level,
        ]);

        if (! $suggestion->exists) {
            $suggestion->status = 'pending';
        }

        $suggestion->save();
    }

    private function matchesDescription(string $source, ?string $detectedValue): bool
    {
        if (blank($detectedValue)) {
            return false;
        }

        return preg_match($this->literalPattern($detectedValue), $source) === 1;
    }

    private function matchesBrand(string $source, ?string $detectedValue): bool
    {
        if (blank($detectedValue)) {
            return false;
        }

        return mb_strtolower(trim($source), 'UTF-8')
            === mb_strtolower(trim($detectedValue), 'UTF-8');
    }

    private function brandIsInvalid(string $source): bool
    {
        $normalized = trim($source);

        return $normalized === '' || $normalized === '0';
    }

    private function brandRuleRequiresReview(NormalizationRule $rule): bool
    {
        return $rule->requires_review
            || ! $rule->is_automatic
            || $rule->rule_type === 'manual_review';
    }

    private function suggestedValue(
        string $source,
        NormalizationRule $rule,
        string $fieldName,
    ): ?string {
        if ($rule->replacement_value === null) {
            return null;
        }

        if ($fieldName === self::BRAND_FIELD) {
            return $rule->replacement_value;
        }

        return preg_replace_callback(
            $this->literalPattern($rule->detected_value),
            static fn (): string => $rule->replacement_value,
            $source,
        ) ?? $source;
    }

    private function literalPattern(string $detectedValue): string
    {
        $literal = preg_quote($detectedValue, '~');

        return "~{$literal}~iu";
    }

    private function suggestionReason(NormalizationRule $rule, string $fieldName): string
    {
        if ($fieldName === self::BRAND_FIELD) {
            if ($rule->replacement_value === null) {
                return 'Regla sensible de homologación de marca: requiere revisión manual. No aplicar automáticamente.';
            }

            if ($this->brandRuleRequiresReview($rule)) {
                return 'Regla de homologación de marca: requiere revisión antes de aplicar.';
            }

            return 'Regla de homologación de marca detectada. Requiere previsualización y aprobación.';
        }

        if ($rule->rule_type === 'no_change') {
            return 'Regla de conservación: mantener el valor original sin cambios.';
        }

        if ($rule->replacement_value === null) {
            return 'Regla sensible: requiere revisión manual. No aplicar automáticamente.';
        }

        if ($rule->requires_review) {
            return 'Regla contextual: requiere revisión antes de aplicar.';
        }

        return 'Regla aplicable detectada. Requiere previsualización y aprobación.';
    }

    private function appendReviewReason(?string $currentReason, string $newReason): string
    {
        $currentReason = trim((string) $currentReason);

        if ($currentReason === '') {
            return $newReason;
        }

        if (str_contains($currentReason, $newReason)) {
            return $currentReason;
        }

        return "{$currentReason}; {$newReason}";
    }
}
