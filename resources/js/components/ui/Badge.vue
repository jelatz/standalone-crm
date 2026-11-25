<script setup lang="ts">
import { computed } from 'vue';
import { twMerge } from 'tailwind-merge';

export type BadgeVariant = 'neutral' | 'cobalt' | 'success' | 'warning' | 'danger';

const props = withDefaults(
    defineProps<{
        variant?: BadgeVariant;
        class?: string;
    }>(),
    {
        variant: 'neutral',
        class: '',
    },
);

const variantClasses: Record<BadgeVariant, string> = {
    neutral: 'bg-subtle text-secondary border-default',
    cobalt: 'bg-accent-primary/10 text-accent-primary border-accent-primary/25',
    success: 'bg-status-success/10 text-status-success border-status-success/25',
    warning: 'bg-status-warning/10 text-status-warning border-status-warning/25',
    danger: 'bg-status-danger/10 text-status-danger border-status-danger/25',
};

const badgeClasses = computed(() =>
    twMerge(
        'inline-flex items-center justify-center rounded-full border px-2 py-0.5 font-mono text-[11px] font-medium leading-4 tabular-nums whitespace-nowrap',
        variantClasses[props.variant],
        props.class,
    ),
);
</script>

<template>
    <span :class="badgeClasses">
        <slot />
    </span>
</template>
