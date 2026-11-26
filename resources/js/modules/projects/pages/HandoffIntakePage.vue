<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/components/ui/Badge.vue';

const props = defineProps<{
    handoffs: App.Modules.Projects.Handoffs.Data.DealHandoffSummaryData[];
    statusCounts: Record<string, number>;
    activeStatus: string | null;
    templates: App.Modules.Projects.Templates.Data.ProjectTemplateSummaryData[];
    assignablePms: App.Modules.Core.Users.Data.UserSummaryData[];
    assignableAms: App.Modules.Core.Users.Data.UserSummaryData[];
    can: {
        review: boolean;
        approve: boolean;
        submit: boolean;
    };
}>();
</script>

<template>
    <Head title="Handoff Intake" />

    <div class="space-y-4 rounded-[8px] border border-default bg-surface p-5">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                    Handoff Intake Queue
                </h1>
                <p class="text-[13px] text-secondary">
                    Approval-gated Deal Won handoffs awaiting Operations review and PM assignment.
                </p>
            </div>
            <Badge variant="cobalt">{{ props.handoffs.length }} visible</Badge>
        </div>

        <div v-if="props.handoffs.length === 0" class="rounded-[6px] border border-default bg-subtle p-4 text-[13px] text-secondary">
            No deal handoffs match the current filter.
        </div>

        <ul v-else class="divide-y divide-default rounded-[6px] border border-default">
            <li
                v-for="handoff in props.handoffs"
                :key="handoff.id"
                class="flex items-center justify-between gap-3 px-4 py-3 text-[13px]"
            >
                <div class="min-w-0">
                    <Link
                        :href="`/app/projects/handoffs/${handoff.id}`"
                        class="font-medium text-primary hover:underline"
                    >
                        {{ handoff.deal_title ?? 'Deal Handoff' }}
                    </Link>
                    <p class="text-[12px] text-secondary">
                        {{ handoff.company_name ?? 'Account' }}
                    </p>
                </div>
                <Badge variant="neutral">{{ handoff.status }}</Badge>
            </li>
        </ul>
    </div>
</template>
