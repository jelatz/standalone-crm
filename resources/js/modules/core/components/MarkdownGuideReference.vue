<script setup lang="ts">
import { FileCode2, Plus } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import {
    MARKDOWN_GUIDE_ITEMS,
    type MarkdownFormatId,
} from '@/modules/core/utils/markdownFormatting';

const props = withDefaults(
    defineProps<{
        compact?: boolean;
        disabled?: boolean;
    }>(),
    {
        compact: false,
        disabled: false,
    },
);

const emit = defineEmits<{
    insert: [action: MarkdownFormatId];
    'insert-template': [];
}>();
</script>

<template>
    <div class="space-y-2.5">
        <div class="overflow-x-auto rounded-[6px] border border-default bg-surface">
            <table class="w-full border-collapse text-left">
                <thead>
                    <tr class="h-7 border-b border-default bg-subtle text-[11px] font-medium text-secondary">
                        <th class="px-3 py-1 font-medium whitespace-nowrap">Element</th>
                        <th class="px-3 py-1 font-medium whitespace-nowrap">Syntax</th>
                        <th v-if="!props.compact" class="px-3 py-1 font-medium">Purpose</th>
                        <th class="px-3 py-1 text-right font-medium whitespace-nowrap">Insert</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-default text-[12px]">
                    <tr
                        v-for="item in MARKDOWN_GUIDE_ITEMS"
                        :key="item.id"
                        class="transition-colors duration-150 hover:bg-subtle/60"
                    >
                        <td class="px-3 py-1.5 font-medium text-primary whitespace-nowrap">
                            {{ item.label }}
                        </td>
                        <td class="px-3 py-1.5">
                            <code class="rounded border border-default bg-subtle px-1.5 py-0.5 font-mono text-[11px] text-primary whitespace-nowrap">
                                {{ item.syntax }}
                            </code>
                        </td>
                        <td v-if="!props.compact" class="px-3 py-1.5 text-secondary">
                            {{ item.description }}
                        </td>
                        <td class="px-3 py-1.5 text-right whitespace-nowrap">
                            <Button
                                variant="secondary"
                                size="sm"
                                :disabled="props.disabled"
                                :data-testid="`guide-insert-${item.id}`"
                                :aria-label="`Insert ${item.label}`"
                                @click="emit('insert', item.id)"
                            >
                                <Plus class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                                <span>Insert</span>
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 rounded-[6px] border border-default bg-subtle/70 px-3 py-2">
            <div class="flex min-w-0 items-center gap-2 text-[12px] text-secondary">
                <FileCode2 class="h-4 w-4 shrink-0 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                <span class="truncate">
                    Starting a new procedure? Drop in the standard CRM SOP section structure.
                </span>
            </div>
            <Button
                variant="secondary"
                size="sm"
                :disabled="props.disabled"
                data-testid="guide-insert-template"
                @click="emit('insert-template')"
            >
                Insert SOP Template
            </Button>
        </div>
    </div>
</template>
