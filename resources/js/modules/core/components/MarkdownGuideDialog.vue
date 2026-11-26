<script setup lang="ts">
import Dialog from '@/components/ui/Dialog.vue';
import MarkdownGuideReference from '@/modules/core/components/MarkdownGuideReference.vue';
import type { MarkdownFormatId } from '@/modules/core/utils/markdownFormatting';

const props = withDefaults(
    defineProps<{
        open: boolean;
        disabled?: boolean;
    }>(),
    {
        disabled: false,
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
    insert: [action: MarkdownFormatId];
    'insert-template': [];
}>();
</script>

<template>
    <Dialog
        :open="props.open"
        title="Markdown Formatting Guide"
        description="Syntax supported by the SOP text box and live preview. Click Insert to place a snippet at your cursor."
        max-width-class="max-w-2xl"
        @update:open="emit('update:open', $event)"
    >
        <div
            data-testid="markdown-guide-dialog"
            class="max-h-[70vh] overflow-y-auto pr-0.5"
        >
            <MarkdownGuideReference
                :disabled="props.disabled"
                @insert="emit('insert', $event)"
                @insert-template="emit('insert-template')"
            />
        </div>
    </Dialog>
</template>
