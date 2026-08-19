<?php

namespace App\Services\Normalization;

use App\Models\NormalizationRule;
use App\Models\NormalizationSuggestion;
use App\Models\ProductStagingRow;
use Illuminate\Support\Facades\DB;

class ProductStagingAnalyzer
{
    private const TARGET_FIELD = 'descripcion_catalogo';

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

            $source = (string) $stagingRow->nombre_sku_original;

            if (blank(trim($source))) {
                $stagingRow->update([
                    'status' => 'requires_review',
                    'requires_review' => true,
                    'review_reason' => $this->appendReviewReason(
                        $stagingRow->review_reason,
                        'Nombre Sku original vacío',
                    ),
                    'analyzed_at' => now(),
                ]);

                return;
            }

            $matchedRules = 0;
            $reviewReasons = [];

            foreach ($this->activeRules() as $rule) {
                if (! $this->matches($source, $rule->detected_value)) {
                    continue;
                }

                $matchedRules++;
                $this->storeSuggestion($stagingRow, $rule, $source);

                if ($rule->requires_review) {
                    $reviewReasons[] = "Regla {$rule->detected_value}: requiere revisión manual o contextual";
                }
            }

            $reviewReason = $stagingRow->review_reason;

            foreach (array_unique($reviewReasons) as $reason) {
                $reviewReason = $this->appendReviewReason($reviewReason, $reason);
            }

            $stagingRow->update([
                'status' => $matchedRules > 0 ? 'suggested' : 'analyzed',
                'requires_review' => $stagingRow->requires_review || $reviewReasons !== [],
                'review_reason' => $reviewReason,
                'analyzed_at' => now(),
            ]);
        });
    }

    /**
     * @return iterable<int, NormalizationRule>
     */
    private function activeRules(): iterable
    {
        return NormalizationRule::query()
            ->where('active', true)
            ->where(function ($query): void {
                $query
                    ->where('applies_to_field', self::TARGET_FIELD)
                    ->orWhereNull('applies_to_field');
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    private function storeSuggestion(
        ProductStagingRow $row,
        NormalizationRule $rule,
        string $source,
    ): void {
        $suggestion = NormalizationSuggestion::query()->firstOrNew([
            'product_staging_row_id' => $row->getKey(),
            'normalization_rule_id' => $rule->getKey(),
            'field_name' => self::TARGET_FIELD,
        ]);

        if ($suggestion->exists
            && ! in_array($suggestion->status, self::EDITABLE_SUGGESTION_STATUSES, true)) {
            return;
        }

        $suggestion->fill([
            'original_value' => $source,
            'suggested_value' => $this->suggestedValue($source, $rule),
            'suggestion_reason' => $this->suggestionReason($rule),
            'confidence_level' => $rule->confidence_level,
        ]);

        if (! $suggestion->exists) {
            $suggestion->status = 'pending';
        }

        $suggestion->save();
    }

    private function matches(string $source, ?string $detectedValue): bool
    {
        if (blank($detectedValue)) {
            return false;
        }

        return preg_match($this->literalPattern($detectedValue), $source) === 1;
    }

    private function suggestedValue(string $source, NormalizationRule $rule): ?string
    {
        if ($rule->replacement_value === null) {
            return null;
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

    private function suggestionReason(NormalizationRule $rule): string
    {
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
