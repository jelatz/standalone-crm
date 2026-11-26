<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Badge from '@/components/ui/Badge.vue';

const props = defineProps<{
    pipelines: App.Modules.Crm.Pipelines.Data.PipelineData[];
    customFieldDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    allowedCoreFields: string[];
    canManage: boolean;
}>();
</script>

<template>
    <Head title="Pipeline Settings" />

    <div class="rounded-[8px] border border-default bg-surface p-5 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                    Pipelines &amp; Custom Fields
                </h2>
                <p class="text-[13px] text-secondary">
                    Deal stages, stage-required gates, and entity metadata definitions.
                </p>
            </div>
            <Badge variant="cobalt">{{ props.pipelines.length }} Pipelines</Badge>
        </div>

        <ul class="divide-y divide-default rounded-[6px] border border-default">
            <li
                v-for="pipeline in props.pipelines"
                :key="pipeline.id"
                class="flex items-center justify-between px-3.5 py-2.5 text-[13px]"
            >
                <span class="font-medium text-primary">{{ pipeline.name }}</span>
                <span class="font-mono text-[12px] text-secondary">
                    {{ pipeline.stages.length }} stages
                </span>
            </li>
        </ul>
    </div>
</template>
