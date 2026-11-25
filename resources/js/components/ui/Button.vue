<script setup lang="ts">
import { computed } from 'vue';
import { twMerge } from 'tailwind-merge';
import { Loader2 } from 'lucide-vue-next';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger';
export type ButtonSize = 'sm' | 'md';

const props = withDefaults(
    defineProps<{
        variant?: ButtonVariant;
        size?: ButtonSize;
        type?: 'button' | 'submit' | 'reset';
        loading?: boolean;
        disabled?: boolean;
        class?: string;
    }>(),
    {
        variant: 'secondary',
        size: 'md',
        type: 'button',
        loading: false,
        disabled: false,
        class: '',
    },
);

const isDisabled = computed(() => props.disabled || props.loading);

const variantClasses: Record<ButtonVariant, string> = {
    primary: 'bg-accent-primary text-accent-contrast border-transparent hover:bg-accent-hover',
    secondary: 'bg-surface text-primary border-default hover:bg-subtle',
    ghost: 'bg-transparent text-secondary border-transparent hover:bg-subtle hover:text-primary',
    danger: 'bg-status-danger text-accent-contrast border-transparent hover:opacity-90',
};

const sizeClasses: Record<ButtonSize, string> = {
    sm: 'h-7 px-2.5 text-[12px] gap-1.5',
    md: 'h-8 px-3 text-[13px] gap-2',
};

const buttonClasses = computed(() =>
    twMerge(
        'inline-flex items-center justify-center rounded-[6px] border font-medium whitespace-nowrap transition-colors duration-150 ease-out cursor-pointer disabled:cursor-not-allowed disabled:opacity-50',
        variantClasses[props.variant],
        sizeClasses[props.size],
        props.class,
    ),
);
</script>

<template>
    <button
        :type="props.type"
        :disabled="isDisabled"
        :aria-busy="props.loading ? 'true' : undefined"
        :class="buttonClasses"
    >
        <Loader2
            v-if="props.loading"
            class="h-3.5 w-3.5 shrink-0 animate-spin"
            :stroke-width="1.75"
            aria-hidden="true"
        />
        <slot />
    </button>
</template>
