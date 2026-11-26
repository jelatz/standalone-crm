<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/components/ui/Badge.vue';

const props = defineProps<{
    handoff: App.Modules.Projects.Handoffs.Data.DealHandoffDetailData;
    templates: App.Modules.Projects.Templates.Data.ProjectTemplateSummaryData[];
    assignablePms: App.Modules.Core.Users.Data.UserSummaryData[];
    assignableAms: App.Modules.Core.Users.Data.UserSummaryData[];
    can: {
        resubmit: boolean;
        requestChanges: boolean;
        reject: boolean;
        approve: boolean;
        viewProject: boolean;
    };
}>();
</script>

<template>
    <Head :title="props.handoff.deal?.title ?? 'Deal Handoff'" />

    <div class="space-y-4 rounded-[8px] border border-default bg-surface p-5">
        <div class="flex items-center justify-between gap-3">
            <div>
                <Link
                    href="/app/projects/handoffs"
                    class="text-[12px] font-medium text-secondary hover:text-primary"
                >
                    Back to Handoff Queue
                </Link>
                <h1 class="mt-1 text-[16px] font-semibold tracking-[-0.01em] text-primary">
                    {{ props.handoff.deal?.title ?? 'Deal Handoff' }}
                </h1>
                <p class="text-[13px] text-secondary">
                    {{ props.handoff.company?.name ?? 'Client Account' }}
                </p>
            </div>
            <Badge variant="cobalt">{{ props.handoff.status }}</Badge>
        </div>

        <div v-if="props.handoff.provisioned_project" class="rounded-[6px] border border-default bg-subtle p-3 text-[13px]">
            Provisioned Project:
            <span class="font-mono font-medium text-primary">
                {{ props.handoff.provisioned_project.code }}
            </span>
            — {{ props.handoff.provisioned_project.name }}
        </div>
    </div>
</template>
