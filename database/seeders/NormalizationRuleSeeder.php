<?php

namespace Database\Seeders;

use App\Models\NormalizationRule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class NormalizationRuleSeeder extends Seeder
{
    use WithoutModelEvents;

    public const RULE_COUNT = 108;

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ($this->rules() as $rule) {
                $identity = Arr::only($rule, [
                    'detected_value',
                    'rule_type',
                    'applies_to_field',
                    'context',
                ]);
                $existingRule = NormalizationRule::query()
                    ->whereRaw('BINARY detected_value = ?', [$identity['detected_value']])
                    ->where('rule_type', $identity['rule_type'])
                    ->where('applies_to_field', $identity['applies_to_field'])
                    ->where('context', $identity['context'])
                    ->first();

                if ($existingRule === null) {
                    NormalizationRule::query()->create($rule);

                    continue;
                }

                $existingRule->fill(Arr::except($rule, [
                    'detected_value',
                    'rule_type',
                    'applies_to_field',
                    'context',
                ]))->save();
            }
        });
    }

    /**
     * @return array<int, array<string, bool|int|string|null>>
     */
    private function rules(): array
    {
        return [
            ...$this->replacementRules(
                replacements: [
                    'D/P' => 'DOYPACK',
                    'DES/AMBIENTE' => 'Desodorante de ambiente',
                    'L/F' => 'Largo fino',
                    'T/B' => 'Tetrabrik',
                    'S/E' => 'Sin ensobrar',
                    'S/DEO' => 'Sin Deo',
                    'C/DEO' => 'Con Deo',
                    'L/PROFUNDA' => 'Limpieza profunda',
                    'S/CAROZO' => 'sin carozo',
                    'S/SAL' => 'sin sal',
                    'C/SAL' => 'con sal',
                    'C/LECHE' => 'con leche',
                    'P/HORNO' => 'para horno',
                    'P/DILUIR' => 'para diluir',
                    'C/CHIPS' => 'con chips',
                    'C/ALAS' => 'con alas',
                    'C/MIEL' => 'con miel',
                    'C/HIERBAS' => 'con hierbas',
                    'DCE/LECHE' => 'dulce de leche',
                    'C/DDL' => 'con dulce de leche',
                    'C/LIMON' => 'con limón',
                    'C/NARANJA' => 'con naranja',
                    'S/AZUCAR' => 'sin azúcar',
                    'S/TACC' => 'sin TACC',
                    'S/OLOR' => 'sin olor',
                    'C/STEVIA' => 'con stevia',
                    'P/FREEZER' => 'para freezer',
                    'CAFE/COGN' => 'café al cognac',
                ],
                ruleType: 'slash_abbreviation',
            ),
            ...$this->replacementRules(
                replacements: [
                    'C/' => 'con',
                    'S/' => 'sin',
                    'P/' => 'para',
                ],
                ruleType: 'slash_abbreviation',
                isAutomatic: false,
                requiresReview: true,
                confidenceLevel: 'contextual',
                notes: 'Aplicar solo cuando funcione como abreviatura de con, sin o para.',
            ),
            ...$this->replacementRules(
                replacements: [
                    'CAFE/RON' => 'café al ron',
                    'VAIN/DDL' => 'vainilla / dulce de leche',
                    'CHOC/CARAM' => 'chocolate / caramelo',
                    'CREM/CEB' => 'crema y cebolla',
                    'FR/BOSQUE' => 'frutos del bosque',
                    'FRT/ROJ' => 'frutos rojos',
                    'NAR/DURAZ' => 'naranja/durazno',
                    'NJA/LIMA' => 'naranja/lima',
                    'AVE/CHIPS' => 'avena/chips',
                    'PESC/ARROZ' => 'pescado/arroz',
                    'CAR/PESC/ARR' => 'carne/pescado/arroz',
                ],
                ruleType: 'flavor_variant',
            ),
            ...$this->replacementRules(
                replacements: [
                    '900/800' => null,
                    '50/40' => null,
                    '300/250' => null,
                    '200/225' => null,
                    '700/750' => null,
                    '100/90' => null,
                    '180/170' => null,
                    '90/100' => null,
                    '125/130' => null,
                    '250/260' => null,
                ],
                ruleType: 'manual_review',
                isAutomatic: false,
                requiresReview: true,
                confidenceLevel: 'blocked',
                notes: 'Rango, formato comercial o medida con barra; no modificar automáticamente.',
            ),
            ...$this->replacementRules(
                replacements: [
                    'FID.' => 'Fideos',
                    'LIQ.' => 'Líquido',
                    'MERM.' => 'Mermelada',
                    'ACOND.' => 'Acondicionador',
                    'ALIM.GATO' => 'Alimento gato',
                    'ALIM.PERRO' => 'Alimento perro',
                    'T.FEMENINA' => 'Toalla femenina',
                    'T.HUMEDAS' => 'Toallas húmedas',
                    'BIZC.' => 'Bizcochuelo',
                    'GELAT.' => 'Gelatina',
                    'P.FRITAS' => 'Papas fritas',
                    'P.HIGIENICO' => 'Papel Higiénico',
                    'M.COCIDO' => 'Mate cocido',
                    'PROT.SOLAR' => 'Protector solar',
                    'DESINF.' => 'Desinfectante',
                    'INSECT.' => 'Insecticida',
                    'RVA.' => 'Reserva',
                    'BCO.DULCE' => 'Blanco dulce',
                    'DCE.LECHE' => 'dulce de leche',
                    'DESM.' => 'Descremada',
                    'PREM.' => 'Premium',
                    'PVO.' => 'Polvo',
                    'POM.ROSADO' => 'Pomelo rosado',
                    'ANTIBACT.' => 'Antibacterial',
                    'LIMP.PISOS' => 'Limpiador pisos',
                    'JAB.POLVO' => 'Jabón en polvo',
                    'JABON.LIQ' => 'Jabón líquido',
                ],
                ruleType: 'dotted_abbreviation',
            ),
            ...$this->replacementRules(
                replacements: [
                    'CHAMP.' => 'Espumantes',
                    'Champagne' => 'Espumantes',
                    'Champaña' => 'Espumantes',
                ],
                ruleType: 'category_word_replacement',
                context: 'bebidas / espumantes',
                notes: 'Criterio comercial confirmado por usuario.',
            ),
            ...$this->replacementRules(
                replacements: [
                    'LIMON' => 'LIMÓN',
                    'CAFE' => 'CAFÉ',
                    'MAIZ' => 'MAÍZ',
                    'HIGIENICO' => 'HIGIÉNICO',
                ],
                ruleType: 'accent',
            ),
            ...$this->replacementRules(
                replacements: [
                    'CEBOLL' => 'CEBOLLA',
                ],
                ruleType: 'abbreviation',
                notes: 'Corrección de palabra incompleta detectada en descripción; ejemplo: sabor crema y cebolla.',
            ),
            ...$this->replacementRules(
                replacements: [
                    'MX' => 'MAX',
                ],
                ruleType: 'abbreviation',
                isAutomatic: false,
                requiresReview: true,
                confidenceLevel: 'contextual',
                notes: 'Aplicar solo cuando MX funcione como abreviatura de MAX en descripción. No aplicar dentro de medidas como 80MX4UN.',
            ),
            ...$this->replacementRules(
                replacements: [
                    'ARLISTAN' => 'Arlistán',
                ],
                ruleType: 'brand_normalization',
                isAutomatic: false,
                requiresReview: true,
                confidenceLevel: 'contextual',
                notes: 'Marca con escritura oficial a validar antes de aplicar masivamente.',
                appliesToField: 'marca_homologada',
            ),
            ...$this->replacementRules(
                replacements: [
                    'MANON' => 'Manón',
                ],
                ruleType: 'brand_normalization',
                isAutomatic: false,
                requiresReview: true,
                confidenceLevel: 'contextual',
                notes: 'Marca con posible tilde; validar escritura oficial antes de aplicar masivamente.',
                appliesToField: 'marca_homologada',
            ),
            ...$this->replacementRules(
                replacements: [
                    'TARAGUI' => 'Taragüi',
                    'TARAGÜI' => 'Taragüi',
                ],
                ruleType: 'brand_normalization',
                isAutomatic: false,
                requiresReview: true,
                confidenceLevel: 'contextual',
                notes: 'Marca con escritura comercial homologada; conservar el valor original y revisar antes de aplicar.',
                appliesToField: 'marca_homologada',
            ),
            ...$this->replacementRules(
                replacements: [
                    '750 CC' => '750CC',
                    '500 GR' => '500GR',
                    '1 LT' => '1LT',
                    '1 KG' => '1KG',
                    '30 MT' => '30MT',
                    '4 x 30 MT' => '4x30MT',
                    '12 x 50 GR' => '12x50GR',
                    '3 x 1 LT' => '3x1LT',
                ],
                ruleType: 'measurement',
                context: 'indesign_catalog',
                notes: 'Formato compacto para evitar cortes de línea en InDesign.',
            ),
            ...$this->replacementRules(
                replacements: [
                    'CANTIDAD+S' => 'sobres',
                ],
                ruleType: 'contextual_abbreviation',
                context: 'te_infusiones_ensobrados',
                notes: 'Interpretar cantidades como 25s, 50s o 100s como sobres solo en contexto de té, infusiones o productos ensobrados.',
            ),
            ...$this->replacementRules(
                replacements: [
                    'HS' => 'Hoja Simple',
                    'DH' => 'Doble Hoja',
                    'TH' => 'Triple Hoja',
                ],
                ruleType: 'contextual_abbreviation',
                context: 'papel_higienico',
                isAutomatic: false,
                requiresReview: true,
                confidenceLevel: 'contextual',
                notes: 'Aplicar solo en contexto papel higiénico o productos de papel.',
            ),
            ...$this->replacementRules(
                replacements: [
                    'RELL.' => null,
                ],
                ruleType: 'manual_review',
                isAutomatic: false,
                requiresReview: true,
                confidenceLevel: 'blocked',
                notes: 'No corregir automáticamente; puede ser relleno, rellena, rellenos o rellenas; revisión individual.',
            ),
            ...$this->replacementRules(
                replacements: [
                    'CAB/MALBEC' => null,
                    'CAB/SAUV' => null,
                    'MALB/SYRAH' => null,
                ],
                ruleType: 'no_change',
                context: 'vinos',
                isAutomatic: false,
                requiresPreview: false,
                confidenceLevel: 'confirmed_no_change',
                notes: 'Varietales o blends de vinos; conservar como están.',
            ),
        ];
    }

    /**
     * @param  array<string, string|null>  $replacements
     * @return array<int, array<string, bool|int|string|null>>
     */
    private function replacementRules(
        array $replacements,
        string $ruleType,
        ?string $context = null,
        bool $isAutomatic = true,
        bool $requiresPreview = true,
        bool $requiresReview = false,
        string $confidenceLevel = 'high',
        ?string $notes = null,
        string $appliesToField = 'descripcion_catalogo',
    ): array {
        $rules = [];

        foreach ($replacements as $detectedValue => $replacementValue) {
            $rules[] = [
                'rule_name' => $this->ruleName($detectedValue, $replacementValue, $ruleType),
                'detected_value' => $detectedValue,
                'replacement_value' => $replacementValue,
                'rule_type' => $ruleType,
                'applies_to_field' => $appliesToField,
                'context' => $context,
                'priority' => 100,
                'is_automatic' => $isAutomatic,
                'requires_preview' => $requiresPreview,
                'requires_review' => $requiresReview,
                'confidence_level' => $confidenceLevel,
                'active' => true,
                'notes' => $notes,
            ];
        }

        return $rules;
    }

    private function ruleName(string $detectedValue, ?string $replacementValue, string $ruleType): string
    {
        return match ($ruleType) {
            'manual_review' => "Revisión manual: {$detectedValue}",
            'no_change' => "Conservar sin cambios: {$detectedValue}",
            default => "{$detectedValue} → {$replacementValue}",
        };
    }
}
