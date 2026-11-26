<script setup lang="ts">
import { computed, useId } from 'vue';
import Input from '@/components/ui/Input.vue';

const props = withDefaults(
    defineProps<{
        definitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
        modelValue: Record<string, any>;
        assignableUsers?: App.Modules.Core.Users.Data.UserSummaryData[];
        errors?: Record<string, string>;
        highlightedKeys?: string[];
        onlyKeys?: string[];
        disabled?: boolean;
        idPrefix?: string;
    }>(),
    {
        assignableUsers: () => [],
        errors: () => ({}),
        highlightedKeys: () => [],
        onlyKeys: undefined,
        disabled: false,
        idPrefix: undefined,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: Record<string, any>];
}>();

const fallbackPrefix = useId();
const domPrefix = computed(() => props.idPrefix ?? `cf-${fallbackPrefix}`);

const visibleDefinitions = computed(() => {
    const active = props.definitions.filter((def) => def.is_active);
    if (!props.onlyKeys) {
        return active;
    }

    const allowed = new Set(props.onlyKeys);
    return active.filter((def) => allowed.has(def.key));
});

const highlightedSet = computed(() => new Set(props.highlightedKeys));

interface SelectOption {
    value: string;
    label: string;
}

function normalizeOptions(
    rawOptions: unknown[] | null | undefined,
): SelectOption[] {
    if (!Array.isArray(rawOptions)) {
        return [];
    }

    const normalized: SelectOption[] = [];
    for (const item of rawOptions) {
        if (typeof item === 'string' && item.trim() !== '') {
            normalized.push({ value: item, label: item });
        } else if (item && typeof item === 'object' && 'value' in item) {
            const candidate = item as { value?: unknown; label?: unknown };
            if (typeof candidate.value === 'string' && candidate.value.trim() !== '') {
                normalized.push({
                    value: candidate.value,
                    label:
                        typeof candidate.label === 'string' && candidate.label.trim() !== ''
                            ? candidate.label
                            : candidate.value,
                });
            }
        }
    }

    return normalized;
}

function fieldDomId(key: string): string {
    return `${domPrefix.value}-${key}`;
}

function fieldError(key: string): string {
    return props.errors[`custom_fields.${key}`] ?? props.errors[key] ?? '';
}

function isRequired(def: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData): boolean {
    return def.validation_rules?.required === true || highlightedSet.value.has(def.key);
}

function updateField(key: string, nextValue: unknown): void {
    emit('update:modelValue', {
        ...props.modelValue,
        [key]: nextValue,
    });
}

function getStringValue(key: string): string {
    const val = props.modelValue[key];
    if (val === null || val === undefined) {
        return '';
    }
    return String(val);
}

function handleTextChange(key: string, value: string): void {
    updateField(key, value.trim() === '' ? null : value);
}

function handleNumberChange(key: string, rawValue: string): void {
    const trimmed = rawValue.trim();
    if (trimmed === '') {
        updateField(key, null);
        return;
    }

    const parsed = Number(trimmed);
    updateField(key, Number.isFinite(parsed) ? parsed : trimmed);
}

function handleBooleanChange(key: string, event: Event): void {
    const target = event.target as HTMLInputElement;
    updateField(key, target.checked);
}

function inputTypeFor(fieldType: App.Modules.Crm.CustomFields.Enums.CustomFieldTypeEnum): string {
    if (fieldType === 'number') {
        return 'number';
    }
    if (fieldType === 'date') {
        return 'date';
    }
    if (fieldType === 'url') {
        return 'url';
    }
    return 'text';
}

function inputPlaceholderFor(fieldType: App.Modules.Crm.CustomFields.Enums.CustomFieldTypeEnum): string {
    return fieldType === 'url' ? 'https://example.internal/doc' : '';
}

function handleScalarInput(
    definition: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData,
    value: string,
): void {
    if (definition.field_type === 'number' || definition.field_type === 'currency') {
        handleNumberChange(definition.key, value);
        return;
    }
    handleTextChange(definition.key, value);
}

function getMultiSelectValues(key: string): string[] {
    const val = props.modelValue[key];
    return Array.isArray(val) ? val.map(String) : [];
}

function toggleMultiSelectOption(key: string, optionValue: string, checked: boolean): void {
    const current = new Set(getMultiSelectValues(key));
    if (checked) {
        current.add(optionValue);
    } else {
        current.delete(optionValue);
    }
    updateField(key, Array.from(current));
}
</script>

<template>
    <div v-if="visibleDefinitions.length > 0" class="space-y-3">
        <div
            v-for="definition in visibleDefinitions"
            :key="definition.id"
            :class="[
                'rounded-[6px] transition-colors duration-150',
                highlightedSet.has(definition.key)
                    ? 'border border-status-warning/50 bg-status-warning/5 p-2.5'
                    : '',
            ]"
        >
            <!-- Multi-select uses fieldset + legend -->
            <fieldset v-if="definition.field_type === 'multi_select'" class="space-y-1.5">
                <legend class="flex items-center gap-1.5 text-[12px] font-medium text-primary">
                    <span>{{ definition.label }}</span>
                    <span v-if="isRequired(definition)" class="text-status-danger">*</span>
                    <span
                        v-if="highlightedSet.has(definition.key)"
                        class="rounded bg-status-warning/15 px-1.5 py-0.5 font-mono text-[10px] font-medium text-status-warning"
                    >
                        Gate required
                    </span>
                </legend>
                <div class="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                    <label
                        v-for="opt in normalizeOptions(definition.options)"
                        :key="opt.value"
                        :for="`${fieldDomId(definition.key)}-${opt.value}`"
                        class="flex items-center gap-2 rounded-[5px] border border-default bg-surface px-2.5 py-1.5 text-[12px] text-primary cursor-pointer hover:bg-subtle"
                    >
                        <input
                            :id="`${fieldDomId(definition.key)}-${opt.value}`"
                            type="checkbox"
                            :checked="getMultiSelectValues(definition.key).includes(opt.value)"
                            :disabled="props.disabled"
                            class="h-3.5 w-3.5 rounded border-default accent-accent-primary"
                            @change="
                                toggleMultiSelectOption(
                                    definition.key,
                                    opt.value,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />
                        <span class="truncate">{{ opt.label }}</span>
                    </label>
                </div>
                <p
                    v-if="fieldError(definition.key)"
                    role="alert"
                    class="text-[12px] text-status-danger"
                >
                    {{ fieldError(definition.key) }}
                </p>
            </fieldset>

            <!-- Boolean checkbox -->
            <div v-else-if="definition.field_type === 'boolean'" class="space-y-1">
                <div class="flex items-center justify-between gap-2">
                    <label
                        :for="fieldDomId(definition.key)"
                        class="flex items-center gap-2 text-[12px] font-medium text-primary cursor-pointer"
                    >
                        <input
                            :id="fieldDomId(definition.key)"
                            type="checkbox"
                            :checked="props.modelValue[definition.key] === true"
                            :disabled="props.disabled"
                            :aria-invalid="fieldError(definition.key) ? 'true' : undefined"
                            class="h-3.5 w-3.5 rounded border-default accent-accent-primary"
                            @change="handleBooleanChange(definition.key, $event)"
                        />
                        <span>{{ definition.label }}</span>
                        <span v-if="isRequired(definition)" class="text-status-danger">*</span>
                    </label>
                    <button
                        v-if="props.modelValue[definition.key] !== undefined && props.modelValue[definition.key] !== null && !props.disabled"
                        type="button"
                        class="text-[11px] text-secondary hover:text-primary cursor-pointer"
                        @click="updateField(definition.key, null)"
                    >
                        Clear
                    </button>
                </div>
                <p
                    v-if="fieldError(definition.key)"
                    role="alert"
                    class="text-[12px] text-status-danger"
                >
                    {{ fieldError(definition.key) }}
                </p>
            </div>

            <!-- Standard labeled inputs -->
            <div v-else class="space-y-1">
                <label
                    :for="fieldDomId(definition.key)"
                    class="flex items-center gap-1.5 text-[12px] font-medium text-secondary"
                >
                    <span>{{ definition.label }}</span>
                    <span v-if="isRequired(definition)" class="text-status-danger">*</span>
                    <span
                        v-if="highlightedSet.has(definition.key)"
                        class="rounded bg-status-warning/15 px-1.5 py-0.5 font-mono text-[10px] font-medium text-status-warning"
                    >
                        Gate required
                    </span>
                </label>

                <textarea
                    v-if="definition.field_type === 'textarea'"
                    :id="fieldDomId(definition.key)"
                    :value="getStringValue(definition.key)"
                    rows="3"
                    :disabled="props.disabled"
                    :aria-invalid="fieldError(definition.key) ? 'true' : undefined"
                    :class="[
                        'w-full rounded-[6px] border bg-surface px-2.5 py-1.5 text-[13px] text-primary placeholder:text-muted focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-accent-primary disabled:cursor-not-allowed disabled:opacity-50',
                        fieldError(definition.key) ? 'border-status-danger' : 'border-default',
                    ]"
                    @input="handleTextChange(definition.key, ($event.target as HTMLTextAreaElement).value)"
                ></textarea>

                <select
                    v-else-if="definition.field_type === 'select'"
                    :id="fieldDomId(definition.key)"
                    :value="getStringValue(definition.key)"
                    :disabled="props.disabled"
                    :aria-invalid="fieldError(definition.key) ? 'true' : undefined"
                    :class="[
                        'h-8 w-full rounded-[6px] border bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-accent-primary disabled:cursor-not-allowed disabled:opacity-50',
                        fieldError(definition.key) ? 'border-status-danger' : 'border-default',
                    ]"
                    @change="handleTextChange(definition.key, ($event.target as HTMLSelectElement).value)"
                >
                    <option value="">Select an option...</option>
                    <option
                        v-for="opt in normalizeOptions(definition.options)"
                        :key="opt.value"
                        :value="opt.value"
                    >
                        {{ opt.label }}
                    </option>
                </select>

                <select
                    v-else-if="definition.field_type === 'user_id'"
                    :id="fieldDomId(definition.key)"
                    :value="getStringValue(definition.key)"
                    :disabled="props.disabled"
                    :aria-invalid="fieldError(definition.key) ? 'true' : undefined"
                    :class="[
                        'h-8 w-full rounded-[6px] border bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-accent-primary disabled:cursor-not-allowed disabled:opacity-50',
                        fieldError(definition.key) ? 'border-status-danger' : 'border-default',
                    ]"
                    @change="handleTextChange(definition.key, ($event.target as HTMLSelectElement).value)"
                >
                    <option value="">Unassigned</option>
                    <option
                        v-for="user in props.assignableUsers"
                        :key="user.id"
                        :value="user.id"
                    >
                        {{ user.name }} ({{ user.employee_code }})
                    </option>
                </select>

                <div v-else-if="definition.field_type === 'currency'" class="relative">
                    <span
                        class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 font-mono text-[12px] text-muted"
                        aria-hidden="true"
                    >
                        $
                    </span>
                    <Input
                        :id="fieldDomId(definition.key)"
                        type="number"
                        :model-value="getStringValue(definition.key)"
                        :disabled="props.disabled"
                        :invalid="Boolean(fieldError(definition.key))"
                        class="pl-6 font-mono tabular-nums"
                        placeholder="0.00"
                        @update:model-value="handleNumberChange(definition.key, $event)"
                    />
                </div>

                <Input
                    v-else
                    :id="fieldDomId(definition.key)"
                    :type="inputTypeFor(definition.field_type)"
                    :model-value="getStringValue(definition.key)"
                    :disabled="props.disabled"
                    :invalid="Boolean(fieldError(definition.key))"
                    :placeholder="inputPlaceholderFor(definition.field_type)"
                    :class="definition.field_type === 'number' || definition.field_type === 'date' ? 'font-mono tabular-nums' : ''"
                    @update:model-value="handleScalarInput(definition, $event)"
                />

                <p
                    v-if="fieldError(definition.key)"
                    role="alert"
                    class="text-[12px] text-status-danger"
                >
                    {{ fieldError(definition.key) }}
                </p>
            </div>
        </div>
    </div>
</template>
