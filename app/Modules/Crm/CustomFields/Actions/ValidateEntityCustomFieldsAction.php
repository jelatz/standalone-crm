<?php

namespace App\Modules\Crm\CustomFields\Actions;

use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\CustomFields\Enums\CustomFieldTypeEnum;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ValidateEntityCustomFieldsAction
{
    /**
     * Validate and normalize an entity's custom_fields payload against active CustomFieldDefinition rows.
     *
     * @param  array<mixed, mixed>  $values
     * @param  list<string>  $requiredDefinitionIds
     * @return array<string, mixed>
     */
    public function execute(
        CustomFieldEntityTypeEnum $entityType,
        array $values,
        ?string $pipelineId = null,
        array $requiredDefinitionIds = [],
        bool $partial = false,
    ): array {
        $query = CustomFieldDefinition::query()
            ->where('entity_type', $entityType->value)
            ->where('is_active', true)
            ->orderBy('sort_order');

        if ($pipelineId !== null) {
            $query->where(function ($sub) use ($pipelineId): void {
                $sub->whereNull('pipeline_id')
                    ->orWhere('pipeline_id', $pipelineId);
            });
        } else {
            $query->whereNull('pipeline_id');
        }

        $definitions = $query->get();
        $definitionsByKey = $definitions->keyBy('key');

        $candidateUserIds = [];
        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $definition = $definitionsByKey->get($key);
            if (
                $definition !== null
                && $definition->field_type === CustomFieldTypeEnum::UserId
                && is_string($value)
                && Str::isUuid($value)
            ) {
                $candidateUserIds[] = $value;
            }
        }

        /** @var array<string, bool> $activeUserIds */
        $activeUserIds = [];
        if ($candidateUserIds !== []) {
            $activeUserIds = User::query()
                ->whereIn('id', array_values(array_unique($candidateUserIds)))
                ->where('status', UserStatusEnum::Active->value)
                ->pluck('id')
                ->mapWithKeys(static fn (mixed $id): array => [(string) $id => true])
                ->all();
        }

        $errors = [];
        $normalized = [];

        foreach ($values as $key => $value) {
            $keyName = (string) $key;
            $definition = is_string($key) ? $definitionsByKey->get($key) : null;

            if ($definition === null) {
                $errors["custom_fields.{$keyName}"] = ["The custom field '{$keyName}' is not active or defined for this entity."];

                continue;
            }

            if ($value === null) {
                $normalized[$keyName] = null;

                continue;
            }

            try {
                $normalized[$keyName] = $this->validateAndNormalizeValue($definition, $value, $activeUserIds);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $messages) {
                    $errors["custom_fields.{$keyName}"] = $messages;
                }
            }
        }

        $requiredIdsMap = array_flip(array_map('strval', $requiredDefinitionIds));

        foreach ($definitions as $definition) {
            $key = $definition->key;
            $provided = array_key_exists($key, $values);

            $isExplicitlyRequired = isset($requiredIdsMap[(string) $definition->id]);
            $isDefinitionRequired = (($definition->validation_rules['required'] ?? false) === true)
                && (! $partial || $provided);

            if (! $isExplicitlyRequired && ! $isDefinitionRequired) {
                continue;
            }

            if (isset($errors["custom_fields.{$key}"])) {
                continue;
            }

            $rawValue = $provided ? $values[$key] : null;

            if ($this->isEmptyRequiredValue($rawValue)) {
                $errors["custom_fields.{$key}"] = ["The {$definition->label} field is required."];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * @param  array<string, bool>  $activeUserIds
     */
    protected function validateAndNormalizeValue(
        CustomFieldDefinition $definition,
        mixed $value,
        array $activeUserIds,
    ): mixed {
        $rules = is_array($definition->validation_rules) ? $definition->validation_rules : [];
        $label = $definition->label;

        return match ($definition->field_type) {
            CustomFieldTypeEnum::Text,
            CustomFieldTypeEnum::Textarea => $this->validateText($value, $rules, $label),
            CustomFieldTypeEnum::Number,
            CustomFieldTypeEnum::Currency => $this->validateNumeric($value, $rules, $label),
            CustomFieldTypeEnum::Boolean => $this->validateBoolean($value, $label),
            CustomFieldTypeEnum::Date => $this->validateDate($value, $label),
            CustomFieldTypeEnum::Select => $this->validateSelect($value, $definition->options, $label),
            CustomFieldTypeEnum::MultiSelect => $this->validateMultiSelect($value, $definition->options, $label),
            CustomFieldTypeEnum::UserId => $this->validateUserId($value, $activeUserIds, $label),
            CustomFieldTypeEnum::Url => $this->validateUrl($value, $label),
        };
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    protected function validateText(mixed $value, array $rules, string $label): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages(['value' => ["{$label} must be a string."]]);
        }

        $length = mb_strlen($value);

        if (isset($rules['min_length']) && $length < (int) $rules['min_length']) {
            throw ValidationException::withMessages([
                'value' => ["{$label} must be at least ".(int) $rules['min_length'].' characters.'],
            ]);
        }

        if (isset($rules['max_length']) && $length > (int) $rules['max_length']) {
            throw ValidationException::withMessages([
                'value' => ["{$label} must not exceed ".(int) $rules['max_length'].' characters.'],
            ]);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    protected function validateNumeric(mixed $value, array $rules, string $label): int|float
    {
        if (is_bool($value) || (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric(trim($value))))) {
            throw ValidationException::withMessages(['value' => ["{$label} must be a valid number."]]);
        }

        if (is_int($value)) {
            $normalized = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $normalized = (int) trim($value);
        } else {
            $normalized = (float) $value;
        }

        if (isset($rules['min']) && $normalized < $rules['min']) {
            throw ValidationException::withMessages([
                'value' => ["{$label} must be at least {$rules['min']}."],
            ]);
        }

        if (isset($rules['max']) && $normalized > $rules['max']) {
            throw ValidationException::withMessages([
                'value' => ["{$label} must not exceed {$rules['max']}."],
            ]);
        }

        return $normalized;
    }

    protected function validateBoolean(mixed $value, string $label): bool
    {
        if (! is_bool($value)) {
            throw ValidationException::withMessages(['value' => ["{$label} must be a boolean."]]);
        }

        return $value;
    }

    protected function validateDate(mixed $value, string $label): string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw ValidationException::withMessages(['value' => ["{$label} must be a valid Y-m-d date."]]);
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $lastErrors = DateTimeImmutable::getLastErrors();

        if (
            $parsed === false
            || ($lastErrors !== false && ($lastErrors['warning_count'] > 0 || $lastErrors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $value
        ) {
            throw ValidationException::withMessages(['value' => ["{$label} is not a valid calendar date."]]);
        }

        return $value;
    }

    /**
     * @param  array<mixed, mixed>|null  $options
     */
    protected function validateSelect(mixed $value, ?array $options, string $label): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages(['value' => ["{$label} must be a string option."]]);
        }

        $allowed = $this->extractAllowedOptionValues($options);

        if (! in_array($value, $allowed, true)) {
            throw ValidationException::withMessages(['value' => ["The selected {$label} option is invalid."]]);
        }

        return $value;
    }

    /**
     * @param  array<mixed, mixed>|null  $options
     * @return list<string>
     */
    protected function validateMultiSelect(mixed $value, ?array $options, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw ValidationException::withMessages(['value' => ["{$label} must be a list of options."]]);
        }

        $allowed = $this->extractAllowedOptionValues($options);
        $seen = [];

        foreach ($value as $item) {
            if (! is_string($item) || ! in_array($item, $allowed, true)) {
                throw ValidationException::withMessages(['value' => ["{$label} contains an invalid option."]]);
            }

            if (isset($seen[$item])) {
                throw ValidationException::withMessages(['value' => ["{$label} must not contain duplicate options."]]);
            }

            $seen[$item] = true;
        }

        return array_values($value);
    }

    /**
     * @param  array<string, bool>  $activeUserIds
     */
    protected function validateUserId(mixed $value, array $activeUserIds, string $label): string
    {
        if (! is_string($value) || ! Str::isUuid($value) || ! isset($activeUserIds[$value])) {
            throw ValidationException::withMessages(['value' => ["{$label} must reference an active employee."]]);
        }

        return $value;
    }

    protected function validateUrl(mixed $value, string $label): string
    {
        if (! is_string($value) || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw ValidationException::withMessages(['value' => ["{$label} must be a valid http or https URL."]]);
        }

        $trimmed = trim($value);
        $scheme = strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));
        $host = parse_url($trimmed, PHP_URL_HOST);

        if (
            filter_var($trimmed, FILTER_VALIDATE_URL) === false
            || ! in_array($scheme, ['http', 'https'], true)
            || ! is_string($host)
            || $host === ''
        ) {
            throw ValidationException::withMessages(['value' => ["{$label} must be a valid http or https URL."]]);
        }

        return $trimmed;
    }

    /**
     * @param  array<mixed, mixed>|null  $options
     * @return list<string>
     */
    protected function extractAllowedOptionValues(?array $options): array
    {
        if ($options === null || $options === []) {
            return [];
        }

        $allowed = [];

        if (! array_is_list($options)) {
            foreach ($options as $key => $item) {
                if (is_array($item) && isset($item['value'])) {
                    $allowed[] = (string) $item['value'];
                } else {
                    $allowed[] = (string) $key;
                }
            }

            return array_values(array_unique($allowed));
        }

        foreach ($options as $item) {
            if (is_array($item) && isset($item['value'])) {
                $allowed[] = (string) $item['value'];
            } elseif (is_string($item) || is_int($item)) {
                $allowed[] = (string) $item;
            }
        }

        return array_values(array_unique($allowed));
    }

    protected function isEmptyRequiredValue(mixed $value): bool
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
