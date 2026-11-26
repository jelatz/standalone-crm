<?php

namespace App\Modules\Crm\CustomFields\Actions;

use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\CustomFields\Enums\CustomFieldTypeEnum;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyCustomFieldFiltersAction
{
    /**
     * Strictly validate filterable custom_fields keys and apply parameterized PostgreSQL JSONB @> filters.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<mixed, mixed>|null  $filters
     */
    public function execute(
        Builder $query,
        CustomFieldEntityTypeEnum $entityType,
        ?array $filters,
        string $qualifiedColumn = 'custom_fields',
        ?string $pipelineId = null,
    ): void {
        if ($filters === null || $filters === []) {
            return;
        }

        $definitionsQuery = CustomFieldDefinition::query()
            ->where('entity_type', $entityType->value)
            ->where('is_active', true)
            ->where('is_filterable', true);

        if ($pipelineId !== null) {
            $definitionsQuery->where(function ($sub) use ($pipelineId): void {
                $sub->whereNull('pipeline_id')
                    ->orWhere('pipeline_id', $pipelineId);
            });
        } else {
            $definitionsQuery->whereNull('pipeline_id');
        }

        $filterableByKey = $definitionsQuery->get()->keyBy('key');
        $errors = [];
        $normalizedFilters = [];

        foreach ($filters as $key => $rawValue) {
            $keyName = (string) $key;

            /** @var CustomFieldDefinition|null $definition */
            $definition = is_string($key) ? $filterableByKey->get($key) : null;

            if ($definition === null) {
                $errors["custom_fields.{$keyName}"] = [
                    "The custom field '{$keyName}' is not filterable or does not exist for this entity.",
                ];

                continue;
            }

            if ($rawValue === null || $rawValue === '' || $rawValue === []) {
                continue;
            }

            try {
                $normalizedFilters[$keyName] = $this->normalizeFilterValue($definition, $rawValue);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $messages) {
                    $errors["custom_fields.{$keyName}"] = $messages;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $isPgsql = DB::getDriverName() === 'pgsql';

        foreach ($normalizedFilters as $key => $val) {
            if ($isPgsql) {
                $query->whereRaw(
                    "{$qualifiedColumn} @> ?::jsonb",
                    [json_encode([$key => $val], JSON_THROW_ON_ERROR)],
                );
            } else {
                $query->whereJsonContains($qualifiedColumn.'->'.$key, $val);
            }
        }
    }

    protected function normalizeFilterValue(CustomFieldDefinition $definition, mixed $rawValue): mixed
    {
        $label = $definition->label;

        return match ($definition->field_type) {
            CustomFieldTypeEnum::Number,
            CustomFieldTypeEnum::Currency => $this->normalizeNumericFilter($rawValue, $label),
            CustomFieldTypeEnum::Boolean => $this->normalizeBooleanFilter($rawValue, $label),
            CustomFieldTypeEnum::MultiSelect => $this->normalizeMultiSelectFilter($rawValue, $label),
            default => $this->normalizeScalarStringFilter($rawValue, $label),
        };
    }

    protected function normalizeNumericFilter(mixed $value, string $label): int|float
    {
        if (is_bool($value) || (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric(trim($value))))) {
            throw ValidationException::withMessages([
                'value' => ["{$label} filter must be a valid number."],
            ]);
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return (float) $value;
    }

    protected function normalizeBooleanFilter(mixed $value, string $label): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1' || (is_string($value) && strtolower(trim($value)) === 'true')) {
            return true;
        }

        if ($value === 0 || $value === '0' || (is_string($value) && strtolower(trim($value)) === 'false')) {
            return false;
        }

        throw ValidationException::withMessages([
            'value' => ["{$label} filter must be a boolean."],
        ]);
    }

    /**
     * @return list<string>
     */
    protected function normalizeMultiSelectFilter(mixed $value, string $label): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw ValidationException::withMessages([
                'value' => ["{$label} filter must be a list of option values."],
            ]);
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_string($item) && ! is_int($item)) {
                throw ValidationException::withMessages([
                    'value' => ["{$label} filter contains an invalid option."],
                ]);
            }

            $items[] = (string) $item;
        }

        return array_values(array_unique($items));
    }

    protected function normalizeScalarStringFilter(mixed $value, string $label): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw ValidationException::withMessages([
                'value' => ["{$label} filter must be a scalar value."],
            ]);
        }

        return (string) $value;
    }
}
