<script setup lang="ts">
import { computed } from 'vue';
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
    VisuallyHidden,
} from 'reka-ui';
import { twMerge } from 'tailwind-merge';
import { X } from 'lucide-vue-next';

const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description?: string;
        hideHeader?: boolean;
        maxWidthClass?: string;
        portalDisabled?: boolean;
        contentClass?: string;
    }>(),
    {
        description: '',
        hideHeader: false,
        maxWidthClass: 'max-w-lg',
        portalDisabled: false,
        contentClass: '',
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const mergedContentClass = computed(() =>
    twMerge(
        'fixed left-1/2 top-1/2 z-50 w-[calc(100%-2rem)] -translate-x-1/2 -translate-y-1/2 rounded-[8px] border border-default bg-surface text-primary shadow-lg focus:outline-none',
        props.maxWidthClass,
        props.contentClass,
    ),
);
</script>

<template>
    <DialogRoot :open="props.open" @update:open="emit('update:open', $event)">
        <DialogPortal :disabled="props.portalDisabled">
            <DialogOverlay
                class="fixed inset-0 z-50 bg-black/45 transition-opacity duration-150 ease-out"
            />
            <DialogContent :class="mergedContentClass">
                <div
                    v-if="!props.hideHeader"
                    class="flex items-start justify-between gap-3 border-b border-default px-4 py-3"
                >
                    <div class="min-w-0">
                        <DialogTitle class="text-[14px] font-semibold leading-5 text-primary">
                            {{ props.title }}
                        </DialogTitle>
                        <DialogDescription
                            v-if="props.description"
                            class="mt-0.5 text-[12px] leading-4 text-secondary"
                        >
                            {{ props.description }}
                        </DialogDescription>
                        <VisuallyHidden v-else as-child>
                            <DialogDescription>{{ props.title }}</DialogDescription>
                        </VisuallyHidden>
                    </div>
                    <DialogClose
                        type="button"
                        aria-label="Close dialog"
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-[6px] text-secondary transition-colors duration-150 hover:bg-subtle hover:text-primary"
                    >
                        <X class="h-4 w-4" :stroke-width="1.75" aria-hidden="true" />
                    </DialogClose>
                </div>

                <template v-else>
                    <VisuallyHidden as-child>
                        <DialogTitle>{{ props.title }}</DialogTitle>
                    </VisuallyHidden>
                    <VisuallyHidden as-child>
                        <DialogDescription>{{ props.description || props.title }}</DialogDescription>
                    </VisuallyHidden>
                </template>

                <div class="p-4">
                    <slot />
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
