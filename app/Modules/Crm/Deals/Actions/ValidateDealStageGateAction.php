<?php

namespace App\Modules\Crm\Deals\Actions;

use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use App\Modules\Crm\Deals\Exceptions\StageGateValidationException;
use App\Modules\Crm\Pipelines\Models\PipelineStage;

class ValidateDealStageGateAction
{
    /**
     * @var array<string, string>
     */
    private const array CORE_FIELD_LABELS = [
        'amount_cents' => 'Deal Amount',
        'expected_close_date' => 'Expected Close Date',
        'primary_contact_id' => 'Primary Contact',
        'lost_reason' => 'Lost Reason',
    ];

    /**
     * Enforce stage-required core and custom fields uniformly for Deal creation, edits, and Kanban moves.
     *
     * @param  array<string, mixed>  $mergedCustomFields
     */
    public function execute(
        PipelineStage $targetStage,
        ?int $amountCents,
        ?string $expectedCloseDate,
        ?string $primaryContactId,
        ?string $lostReason,
        array $mergedCustomFields,
    ): void {
        $targetStage->loadMissing('requiredCustomFields');

        /** @var list<string> $missingCoreFields */
        $missingCoreFields = [];
        /** @var list<string> $missingCustomFields */
        $missingCustomFields = [];
        /** @var array<string, list<string>> $errors */
        $errors = [];

        $requiredCoreFields = array_values(array_unique(array_map('strval', $targetStage->required_core_fields ?? [])));

        foreach ($requiredCoreFields as $field) {
            $isMissing = match ($field) {
                'amount_cents' => $amountCents === null || $amountCents <= 0,
                'expected_close_date' => $expectedCloseDate === null || trim($expectedCloseDate) === '',
                'primary_contact_id' => $primaryContactId === null || trim($primaryContactId) === '',
                'lost_reason' => $lostReason === null || trim($lostReason) === '',
                default => false,
            };

            if ($isMissing) {
                $missingCoreFields[] = $field;
                $label = self::CORE_FIELD_LABELS[$field] ?? $field;
                $errors[$field] = ["{$label} is required before moving to stage {$targetStage->name}."];
            }
        }

        foreach ($targetStage->requiredCustomFields as $definition) {
            /** @var CustomFieldDefinition $definition */
            if (! $definition->is_active) {
                continue;
            }

            $key = $definition->key;
            $value = $mergedCustomFields[$key] ?? null;

            if ($this->isEmptyValue($value)) {
                $missingCustomFields[] = $key;
                $errors["custom_fields.{$key}"] = ["The {$definition->label} field is required before moving to stage {$targetStage->name}."];
            }
        }

        if ($missingCoreFields !== [] || $missingCustomFields !== []) {
            throw new StageGateValidationException(
                missingCoreFields: $missingCoreFields,
                missingCustomFields: $missingCustomFields,
                errors: $errors,
            );
        }
    }

    protected function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }
}
