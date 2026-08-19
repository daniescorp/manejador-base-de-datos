<?php

namespace App\Services\Normalization;

use App\Models\NormalizationRule;
use App\Models\NormalizationSuggestion;
use App\Models\ProductStagingRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class ProductStagingPreviewComposer
{
    private const TARGET_FIELD = 'descripcion_catalogo';

    private const TERMINAL_ROW_STATUSES = [
        'approved',
        'rejected',
        'imported_to_master',
        'excluded',
    ];

    /**
     * @return array<string, array<int, int>|string>
     */
    public function compose(ProductStagingRow $row): array
    {
        return DB::transaction(function () use ($row): array {
            $stagingRow = ProductStagingRow::query()
                ->lockForUpdate()
                ->findOrFail($row->getKey());

            $this->ensureComposable($stagingRow);

            $source = (string) $stagingRow->nombre_sku_original;
            $combined = $source;
            $sourceIsBlank = blank(trim($source));
            $appliedSuggestionIds = [];
            $blockedSuggestionIds = [];
            $manualReviewSuggestionIds = [];
            $noChangeSuggestionIds = [];

            foreach ($this->pendingSuggestions($stagingRow) as $suggestion) {
                $rule = $suggestion->rule;

                if ($rule?->rule_type === 'manual_review') {
                    $manualReviewSuggestionIds[] = $suggestion->getKey();

                    continue;
                }

                if ($rule?->rule_type === 'no_change') {
                    $noChangeSuggestionIds[] = $suggestion->getKey();

                    continue;
                }

                if ($sourceIsBlank || ! $this->isApplicable($rule)) {
                    $blockedSuggestionIds[] = $suggestion->getKey();

                    continue;
                }

                [$combined, $wasApplied] = $this->applyRule($combined, $rule);

                if ($wasApplied) {
                    $appliedSuggestionIds[] = $suggestion->getKey();
                } else {
                    $blockedSuggestionIds[] = $suggestion->getKey();
                }
            }

            $payload = [
                self::TARGET_FIELD => $combined,
                'source_text' => $source,
                'field' => self::TARGET_FIELD,
                'applied_suggestion_ids' => $appliedSuggestionIds,
                'blocked_suggestion_ids' => $blockedSuggestionIds,
                'manual_review_suggestion_ids' => $manualReviewSuggestionIds,
                'no_change_suggestion_ids' => $noChangeSuggestionIds,
                'generated_by' => class_basename(self::class),
            ];
            $preview = $this->withGenerationTime($stagingRow->normalized_preview, $payload);
            $requiresReview = $sourceIsBlank
                || $blockedSuggestionIds !== []
                || $manualReviewSuggestionIds !== [];
            $reviewReason = $stagingRow->review_reason;

            if ($sourceIsBlank) {
                $reviewReason = $this->appendReviewReason(
                    $reviewReason,
                    'Nombre Sku original vacío',
                );
            }

            if ($blockedSuggestionIds !== [] || $manualReviewSuggestionIds !== []) {
                $reviewReason = $this->appendReviewReason(
                    $reviewReason,
                    'La vista previa contiene sugerencias pendientes de revisión',
                );
            }

            $stagingRow->fill([
                'normalized_preview' => $preview,
                'status' => $this->statusFor(
                    $source,
                    $combined,
                    $sourceIsBlank,
                    $appliedSuggestionIds,
                    $blockedSuggestionIds,
                    $manualReviewSuggestionIds,
                    $noChangeSuggestionIds,
                ),
                'requires_review' => $stagingRow->requires_review || $requiresReview,
                'review_reason' => $reviewReason,
            ]);

            if ($stagingRow->isDirty()) {
                $stagingRow->save();
            }

            $stagingRow->refresh();

            return $stagingRow->normalized_preview;
        });
    }

    /**
     * @return Collection<int, NormalizationSuggestion>
     */
    private function pendingSuggestions(ProductStagingRow $row): Collection
    {
        return $row->suggestions()
            ->where('field_name', self::TARGET_FIELD)
            ->where('status', 'pending')
            ->with('rule')
            ->lockForUpdate()
            ->get()
            ->sort(function (NormalizationSuggestion $first, NormalizationSuggestion $second): int {
                $firstPriority = $first->rule?->priority;
                $secondPriority = $second->rule?->priority;

                if ($firstPriority === null && $secondPriority !== null) {
                    return 1;
                }

                if ($firstPriority !== null && $secondPriority === null) {
                    return -1;
                }

                $priorityComparison = ($firstPriority ?? 0) <=> ($secondPriority ?? 0);

                return $priorityComparison !== 0
                    ? $priorityComparison
                    : $first->getKey() <=> $second->getKey();
            })
            ->values();
    }

    private function isApplicable(?NormalizationRule $rule): bool
    {
        return $rule !== null
            && $rule->active
            && filled($rule->detected_value)
            && filled($rule->replacement_value)
            && $rule->is_automatic
            && ! $rule->requires_review
            && (blank($rule->applies_to_field) || $rule->applies_to_field === self::TARGET_FIELD);
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function applyRule(string $text, NormalizationRule $rule): array
    {
        $replacementCount = 0;
        $literal = preg_quote($rule->detected_value, '~');
        $combined = preg_replace_callback(
            "~{$literal}~iu",
            static fn (): string => $rule->replacement_value,
            $text,
            -1,
            $replacementCount,
        );

        return [$combined ?? $text, $combined !== null && $replacementCount > 0];
    }

    /**
     * @param  array<string, mixed>|null  $currentPreview
     * @param  array<string, array<int, int>|string>  $payload
     * @return array<string, array<int, int>|string>
     */
    private function withGenerationTime(?array $currentPreview, array $payload): array
    {
        if (is_array($currentPreview)) {
            $currentPayload = $currentPreview;
            $generatedAt = $currentPayload['generated_at'] ?? null;
            unset($currentPayload['generated_at']);

            if ($currentPayload == $payload && filled($generatedAt)) {
                return $currentPreview;
            }
        }

        return [
            ...$payload,
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * @param  array<int, int>  $appliedSuggestionIds
     * @param  array<int, int>  $blockedSuggestionIds
     * @param  array<int, int>  $manualReviewSuggestionIds
     * @param  array<int, int>  $noChangeSuggestionIds
     */
    private function statusFor(
        string $source,
        string $combined,
        bool $sourceIsBlank,
        array $appliedSuggestionIds,
        array $blockedSuggestionIds,
        array $manualReviewSuggestionIds,
        array $noChangeSuggestionIds,
    ): string {
        if ($sourceIsBlank) {
            return 'requires_review';
        }

        if ($combined !== $source) {
            return 'previewed';
        }

        if ($blockedSuggestionIds !== [] || $manualReviewSuggestionIds !== []) {
            return 'requires_review';
        }

        if ($appliedSuggestionIds !== [] || $noChangeSuggestionIds !== []) {
            return 'suggested';
        }

        return 'analyzed';
    }

    private function appendReviewReason(?string $currentReason, string $newReason): string
    {
        $currentReason = trim((string) $currentReason);

        if ($currentReason === '') {
            return $newReason;
        }

        if (Str::contains($currentReason, $newReason)) {
            return $currentReason;
        }

        return "{$currentReason}; {$newReason}";
    }

    private function ensureComposable(ProductStagingRow $row): void
    {
        if ($row->approved_at !== null
            || $row->approved_by_id !== null
            || in_array($row->status, self::TERMINAL_ROW_STATUSES, true)) {
            throw new LogicException('No se puede recomponer una fila de staging aprobada o terminal.');
        }
    }
}
