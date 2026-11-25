<script setup lang="ts">
import { computed, useId } from 'vue';
import { twMerge } from 'tailwind-merge';

const props = withDefaults(
    defineProps<{
        id?: string;
        modelValue?: string | number;
        type?: string;
        name?: string;
        placeholder?: string;
        autocomplete?: string;
        disabled?: boolean;
        invalid?: boolean;
        errorMessage?: string;
        describedBy?: string;
        class?: string;
    }>(),
    {
        id: undefined,
        modelValue: '',
        type: 'text',
        name: undefined,
        placeholder: '',
        autocomplete: undefined,
        disabled: false,
        invalid: false,
        errorMessage: '',
        describedBy: undefined,
        class: '',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const generatedId = useId();
const inputId = computed(() => props.id ?? generatedId);
const errorId = computed(() => `${inputId.value}-error`);
const isInvalid = computed(() => props.invalid || props.errorMessage.trim().length > 0);

const ariaDescribedBy = computed(() => {
    const ids = [
        props.describedBy,
        props.errorMessage.trim().length > 0 ? errorId.value : undefined,
    ].filter((value): value is string => Boolean(value));

    return ids.length > 0 ? ids.join(' ') : undefined;
});

const inputClasses = computed(() =>
    twMerge(
        'h-8 w-full rounded-[6px] border bg-surface px-2.5 text-[13px] text-primary placeholder:text-muted transition-colors duration-150 ease-out focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-accent-primary disabled:cursor-not-allowed disabled:opacity-50',
        isInvalid.value ? 'border-status-danger' : 'border-default',
        props.class,
    ),
);

function handleInput(event: Event): void {
    const target = event.target as HTMLInputElement;
    emit('update:modelValue', target.value);
}
</script>

<template>
    <div>
        <input
            :id="inputId"
            :name="props.name"
            :type="props.type"
            :value="props.modelValue"
            :placeholder="props.placeholder"
            :autocomplete="props.autocomplete"
            :disabled="props.disabled"
            :aria-invalid="isInvalid ? 'true' : undefined"
            :aria-describedby="ariaDescribedBy"
            :class="inputClasses"
            @input="handleInput"
        />
        <p
            v-if="props.errorMessage"
            :id="errorId"
            role="alert"
            class="mt-1 text-[12px] text-status-danger"
        >
            {{ props.errorMessage }}
        </p>
    </div>
</template>
