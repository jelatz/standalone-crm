<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Badge from '@/components/ui/Badge.vue';

const props = defineProps<{
    groups: App.Modules.Projects.Tasks.Data.MyWorkGroupData[];
    totalCount: number;
    priorities: string[];
    statuses: string[];
}>();
</script>

<template>
    <Head title="My Work" />

    <div class="space-y-4 rounded-[8px] border border-default bg-surface p-5">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                    My Work
                </h1>
                <p class="text-[13px] text-secondary">
                    Assigned project tasks and CRM follow-ups ordered by due date.
                </p>
            </div>
            <Badge variant="cobalt">{{ props.totalCount }} open</Badge>
        </div>

        <div class="space-y-3">
            <section
                v-for="group in props.groups"
                :key="group.key"
                class="rounded-[6px] border border-default bg-subtle p-3"
            >
                <div class="flex items-center justify-between gap-2 text-[13px] font-semibold text-primary">
                    <span>{{ group.label }}</span>
                    <Badge variant="neutral">{{ group.tasks_count }}</Badge>
                </div>
            </section>
        </div>
    </div>
</template>
