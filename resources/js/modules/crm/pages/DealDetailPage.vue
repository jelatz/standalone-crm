<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Badge from '@/components/ui/Badge.vue';

const props = defineProps<{
    deal: App.Modules.Crm.Deals.Data.DealDetailData;
    company: {
        id: string;
        name: string;
        domain: string | null;
        lifecycle_stage: string;
    } | null;
    primaryContact: {
        id: string;
        full_name: string;
        primary_email: string | null;
        job_title: string | null;
    } | null;
    pipeline: App.Modules.Crm.Pipelines.Data.PipelineData;
    stakeholders: App.Modules.Crm.Deals.Data.DealStakeholderData[];
    collaborators: App.Modules.Core.Rbac.Data.EntityCollaboratorData[];
    attachments: App.Modules.Core.Attachments.Data.AttachmentSummaryData[];
    customFieldDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    timeline: App.Modules.Crm.Timeline.Data.TimelinePageData;
    assignableUsers: App.Modules.Core.Users.Data.UserSummaryData[];
    companies: Array<{
        id: string;
        name: string;
        domain: string | null;
    }>;
    contacts: Array<{
        id: string;
        full_name: string;
        primary_email: string | null;
        company_id: string | null;
    }>;
    stakeholderRoles: string[];
    collaboratorAccessLevels: string[];
    collaboratorRoleLabels: string[];
    can: {
        update: boolean;
        delete: boolean;
        moveStage: boolean;
        overrideGates: boolean;
        manageCollaborators: boolean;
    };
}>();
</script>

<template>
    <Head :title="props.deal.title" />

    <div class="rounded-[8px] border border-default bg-surface p-5 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                    {{ props.deal.title }}
                </h2>
                <p class="text-[13px] text-secondary">
                    {{ props.deal.stage_name ?? 'Stage' }} · v{{ props.deal.version }}
                </p>
            </div>
            <Badge variant="cobalt">{{ props.deal.currency }} {{ (props.deal.amount_cents / 100).toFixed(2) }}</Badge>
        </div>

        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3 text-[12px]">
            <div class="rounded-[6px] border border-default bg-subtle px-3 py-2">
                <dt class="text-secondary">Stakeholders</dt>
                <dd class="font-mono font-medium text-primary">{{ props.stakeholders.length }}</dd>
            </div>
            <div class="rounded-[6px] border border-default bg-subtle px-3 py-2">
                <dt class="text-secondary">Collaborators</dt>
                <dd class="font-mono font-medium text-primary">{{ props.collaborators.length }}</dd>
            </div>
            <div class="rounded-[6px] border border-default bg-subtle px-3 py-2">
                <dt class="text-secondary">Timeline Events</dt>
                <dd class="font-mono font-medium text-primary">{{ props.timeline.items.length }}</dd>
            </div>
        </dl>
    </div>
</template>
