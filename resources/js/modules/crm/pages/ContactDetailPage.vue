<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Badge from '@/components/ui/Badge.vue';

const props = defineProps<{
    contact: App.Modules.Crm.Contacts.Data.ContactDetailData;
    company: {
        id: string;
        name: string;
        domain: string | null;
        lifecycle_stage: string;
    } | null;
    deals: App.Modules.Crm.Deals.Data.RelatedDealData[];
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
    collaboratorAccessLevels: string[];
    collaboratorRoleLabels: string[];
    can: {
        update: boolean;
        delete: boolean;
        manageCollaborators: boolean;
    };
}>();
</script>

<template>
    <Head :title="props.contact.full_name" />

    <div class="rounded-[8px] border border-default bg-surface p-5 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                    {{ props.contact.full_name }}
                </h2>
                <p class="text-[13px] text-secondary">
                    {{ props.contact.primary_email ?? 'No primary email' }}
                </p>
            </div>
            <Badge v-if="props.company" variant="cobalt">{{ props.company.name }}</Badge>
        </div>

        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3 text-[12px]">
            <div class="rounded-[6px] border border-default bg-subtle px-3 py-2">
                <dt class="text-secondary">Visible Deals</dt>
                <dd class="font-mono font-medium text-primary">{{ props.deals.length }}</dd>
            </div>
            <div class="rounded-[6px] border border-default bg-subtle px-3 py-2">
                <dt class="text-secondary">Secondary Emails</dt>
                <dd class="font-mono font-medium text-primary">{{ props.contact.secondary_emails.length }}</dd>
            </div>
            <div class="rounded-[6px] border border-default bg-subtle px-3 py-2">
                <dt class="text-secondary">Timeline Events</dt>
                <dd class="font-mono font-medium text-primary">{{ props.timeline.items.length }}</dd>
            </div>
        </dl>
    </div>
</template>
