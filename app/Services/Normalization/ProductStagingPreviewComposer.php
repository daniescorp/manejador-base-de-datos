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
    private const DESCRIPTION_FIELD = 'descripcion_catalogo';

    private const BRAND_FIELD = 'marca_homologada';

    private const TERMINAL_ROW_STATUSES = [
        'approved',
        'rejected',
        'imported_to_master',
        'excluded',
    ];

    public function __construct(
        private readonly DescriptionRulePattern $descriptionRulePattern,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function compose(ProductStagingRow $row): array
    {
        return DB::transaction(function () use ($row): array {
            $stagingRow = ProductStagingRow::query()
                ->lockForUpdate()
                ->findOrFail($row->getKey());

            $this->ensureComposable($stagingRow);

            $descriptionSource = (string) $stagingRow->nombre_sku_original;
            $descriptionPreview = $descriptionSource;
            $descriptionIsBlank = blank(trim($descriptionSource));
            $descriptionAppliedIds = [];
            $descriptionBlockedIds = [];
            $descriptionManualReviewIds = [];
            $descriptionNoChangeIds = [];

            foreach ($this->pendingSuggestions($stagingRow, self::DESCRIPTION_FIELD) as $suggestion) {
                $rule = $suggestion->rule;

                if ($rule?->rule_type === 'manual_review') {
                    $descriptionManualReviewIds[] = $suggestion->getKey();

                    continue;
                }

                if ($rule?->rule_type === 'no_change') {
                    $descriptionNoChangeIds[] = $suggestion->getKey();

                    continue;
                }

                if ($descriptionIsBlank || ! $this->isDescriptionRuleApplicable($rule)) {
                    $descriptionBlockedIds[] = $suggestion->getKey();

                    continue;
                }

                [$descriptionPreview, $wasApplied] = $this->applyDescriptionRule(
                    $descriptionPreview,
                    $rule,
                );

                if ($wasApplied) {
                    $descriptionAppliedIds[] = $suggestion->getKey();
                } else {
                    $descriptionBlockedIds[] = $suggestion->getKey();
                }
            }

            $brandSource = (string) $stagingRow->marca_original;
            $brandPreview = $brandSource;
            $brandIsInvalid = $this->brandIsInvalid($brandSource);
            $brandAppliedIds = [];
            $brandPendingReviewIds = [];
            $brandBlockedIds = [];
            $selectedBrandPreview = null;

            foreach ($this->pendingSuggestions($stagingRow, self::BRAND_FIELD) as $suggestion) {
                $rule = $suggestion->rule;
                $proposedBrand = $this->proposedBrand($suggestion, $rule);

                if ($brandIsInvalid
                    || ! $this->isBrandSuggestionApplicable($brandSource, $proposedBrand, $rule)) {
                    $brandBlockedIds[] = $suggestion->getKey();

                    continue;
                }

                if ($selectedBrandPreview === null) {
                    $selectedBrandPreview = $proposedBrand;
                    $brandPreview = $proposedBrand;
                } elseif ($proposedBrand !== $selectedBrandPreview) {
                    $brandBlockedIds[] = $suggestion->getKey();

                    continue;
                }

                if ($this->brandRuleRequiresReview($rule)) {
                    $brandPendingReviewIds[] = $suggestion->getKey();
                } else {
                    $brandAppliedIds[] = $suggestion->getKey();
                }
            }

            $descriptionPreview = $this->normalizePreviewWhitespace($descriptionPreview);
            $brandPreview = $this->normalizePreviewWhitespace($brandPreview);

            if (! $brandIsInvalid) {
                foreach (array_unique([
                    $brandPreview,
                    $brandSource,
                    (string) data_get($stagingRow->normalized_preview, 'source_brand'),
                ]) as $descriptionBrand) {
                    $descriptionPreview = $this->removeCompleteBrandFromDescription(
                        $descriptionPreview,
                        $descriptionBrand,
                    );
                }
            }

            $payload = [
                self::DESCRIPTION_FIELD => $descriptionPreview,
                self::BRAND_FIELD => $brandPreview,
                'source_text' => $descriptionSource,
                'source_brand' => $brandSource,
                'field' => self::DESCRIPTION_FIELD,
                'applied_suggestion_ids' => $descriptionAppliedIds,
                'blocked_suggestion_ids' => $descriptionBlockedIds,
                'manual_review_suggestion_ids' => $descriptionManualReviewIds,
                'no_change_suggestion_ids' => $descriptionNoChangeIds,
                'fields' => [
                    self::DESCRIPTION_FIELD => [
                        'source' => $descriptionSource,
                        'preview' => $descriptionPreview,
                        'applied_suggestion_ids' => $descriptionAppliedIds,
                        'pending_review_suggestion_ids' => [],
                        'blocked_suggestion_ids' => $descriptionBlockedIds,
                        'manual_review_suggestion_ids' => $descriptionManualReviewIds,
                        'no_change_suggestion_ids' => $descriptionNoChangeIds,
                    ],
                    self::BRAND_FIELD => [
                        'source' => $brandSource,
                        'preview' => $brandPreview,
                        'applied_suggestion_ids' => $brandAppliedIds,
                        'pending_review_suggestion_ids' => $brandPendingReviewIds,
                        'blocked_suggestion_ids' => $brandBlockedIds,
                    ],
                ],
                'generated_by' => class_basename(self::class),
            ];
            $preview = $this->withGenerationTime($stagingRow->normalized_preview, $payload);
            $requiresReview = $descriptionIsBlank
                || $brandIsInvalid
                || $descriptionBlockedIds !== []
                || $descriptionManualReviewIds !== []
                || $brandPendingReviewIds !== []
                || $brandBlockedIds !== [];
            $reviewReason = $stagingRow->review_reason;

            if ($descriptionIsBlank) {
                $reviewReason = $this->appendReviewReason(
                    $reviewReason,
                    'Nombre Sku original vacío',
                );
            }

            if ($brandIsInvalid) {
                $reviewReason = $this->appendReviewReason(
                    $reviewReason,
                    'Marca original vacía o no válida',
                );
            }

            if ($descriptionBlockedIds !== [] || $descriptionManualReviewIds !== []) {
                $reviewReason = $this->appendReviewReason(
                    $reviewReason,
                    'La vista previa contiene sugerencias pendientes de revisión',
                );
            }

            if ($brandPendingReviewIds !== [] || $brandBlockedIds !== []) {
                $reviewReason = $this->appendReviewReason(
                    $reviewReason,
                    'La vista previa contiene sugerencias de marca pendientes de revisión',
                );
            }

            $requiresReview = $stagingRow->requires_review || $requiresReview;

            $stagingRow->fill([
                'normalized_preview' => $preview,
                'status' => $this->statusFor(
                    $descriptionSource,
                    $descriptionPreview,
                    $brandSource,
                    $brandPreview,
                    $descriptionIsBlank,
                    $brandIsInvalid,
                    $descriptionAppliedIds,
                    $descriptionBlockedIds,
                    $descriptionManualReviewIds,
                    $descriptionNoChangeIds,
                    $brandAppliedIds,
                    $brandPendingReviewIds,
                    $brandBlockedIds,
                    $requiresReview,
                ),
                'requires_review' => $requiresReview,
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
    private function pendingSuggestions(ProductStagingRow $row, string $fieldName): Collection
    {
        return $row->suggestions()
            ->where('field_name', $fieldName)
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

    private function isDescriptionRuleApplicable(?NormalizationRule $rule): bool
    {
        return $rule !== null
            && $rule->active
            && filled($rule->detected_value)
            && filled($rule->replacement_value)
            && $rule->is_automatic
            && ! $rule->requires_review
            && (blank($rule->applies_to_field)
                || $rule->applies_to_field === self::DESCRIPTION_FIELD);
    }

    private function isBrandSuggestionApplicable(
        string $source,
        ?string $proposedBrand,
        ?NormalizationRule $rule,
    ): bool {
        return $rule !== null
            && $rule->active
            && $rule->applies_to_field === self::BRAND_FIELD
            && $rule->rule_type !== 'no_change'
            && filled($rule->detected_value)
            && filled($proposedBrand)
            && $this->brandsMatch($source, $rule->detected_value);
    }

    private function brandRuleRequiresReview(NormalizationRule $rule): bool
    {
        return $rule->requires_review
            || ! $rule->is_automatic
            || $rule->rule_type === 'manual_review';
    }

    private function proposedBrand(
        NormalizationSuggestion $suggestion,
        ?NormalizationRule $rule,
    ): ?string {
        if (filled($suggestion->suggested_value)) {
            return $suggestion->suggested_value;
        }

        return filled($rule?->replacement_value) ? $rule->replacement_value : null;
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function applyDescriptionRule(string $text, NormalizationRule $rule): array
    {
        return $this->descriptionRulePattern->replace($text, $rule);
    }

    private function brandsMatch(string $source, string $detectedValue): bool
    {
        return mb_strtolower(trim($source), 'UTF-8')
            === mb_strtolower(trim($detectedValue), 'UTF-8');
    }

    private function brandIsInvalid(string $source): bool
    {
        $normalized = trim($source);

        return $normalized === '' || $normalized === '0';
    }

    private function normalizePreviewWhitespace(string $text): string
    {
        return preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    }

    private function removeCompleteBrandFromDescription(string $description, string $brand): string
    {
        $normalizedDescription = $this->normalizePreviewWhitespace($description);
        $normalizedBrand = $this->normalizePreviewWhitespace($brand);

        if ($this->brandIsInvalid($normalizedBrand)) {
            return $normalizedDescription;
        }

        $brandPattern = preg_replace('/\s+/u', '\\s+', preg_quote($normalizedBrand, '~'));

        if ($brandPattern === null) {
            return $normalizedDescription;
        }

        $withoutBrand = preg_replace(
            "~(?<![\\p{L}\\p{N}_]){$brandPattern}(?![\\p{L}\\p{N}_])~iu",
            ' ',
            $normalizedDescription,
        );

        if ($withoutBrand === null) {
            return $normalizedDescription;
        }

        $withoutBrand = $this->normalizePreviewWhitespace($withoutBrand);

        return mb_strlen($withoutBrand, 'UTF-8') >= 3
            ? $withoutBrand
            : $normalizedDescription;
    }

    /**
     * @param  array<string, mixed>|null  $currentPreview
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
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
     * @param  array<int, int>  $descriptionAppliedIds
     * @param  array<int, int>  $descriptionBlockedIds
     * @param  array<int, int>  $descriptionManualReviewIds
     * @param  array<int, int>  $descriptionNoChangeIds
     * @param  array<int, int>  $brandAppliedIds
     * @param  array<int, int>  $brandPendingReviewIds
     * @param  array<int, int>  $brandBlockedIds
     */
    private function statusFor(
        string $descriptionSource,
        string $descriptionPreview,
        string $brandSource,
        string $brandPreview,
        bool $descriptionIsBlank,
        bool $brandIsInvalid,
        array $descriptionAppliedIds,
        array $descriptionBlockedIds,
        array $descriptionManualReviewIds,
        array $descriptionNoChangeIds,
        array $brandAppliedIds,
        array $brandPendingReviewIds,
        array $brandBlockedIds,
        bool $requiresReview,
    ): string {
        if ($descriptionIsBlank || $brandIsInvalid) {
            return 'requires_review';
        }

        if ($descriptionPreview !== $descriptionSource
            || $brandPreview !== $brandSource
            || $brandPendingReviewIds !== []) {
            return 'previewed';
        }

        if ($descriptionBlockedIds !== []
            || $descriptionManualReviewIds !== []
            || $brandBlockedIds !== []) {
            return 'requires_review';
        }

        if ($descriptionAppliedIds !== []
            || $descriptionNoChangeIds !== []
            || $brandAppliedIds !== []) {
            return 'suggested';
        }

        return $requiresReview ? 'requires_review' : 'analyzed';
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
