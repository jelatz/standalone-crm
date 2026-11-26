<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Badge from '@/components/ui/Badge.vue';

const props = defineProps<{
    company: App.Modules.Crm.Companies.Data.CompanyDetailData;
    contacts: App.Modules.Crm.Contacts.Data.ContactListItemData[];
    deals: App.Modules.Crm.Deals.Data.RelatedDealData[];
    collaborators: App.Modules.Core.Rbac.Data.EntityCollaboratorData[];
    attachments: App.Modules.Core.Attachments.Data.AttachmentSummaryData[];
    customFieldDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    timeline: App.Modules.Crm.Timeline.Data.TimelinePageData;
    assignableUsers: App.Modules.Core.Users.Data.UserSummaryData[];
    lifecycleStages: string[];
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
    <Head :title="props.company.name" />

    <div class="rounded-[8px] border border-default bg-surface p-5 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                    {{ props.company.name }}
                </h2>
                <p class="text-[13px] text-secondary">
                    {{ props.company.domain ?? 'No domain set' }}
                </p>
            </div>
            <Badge variant="cobalt">{{ props.company.lifecycle_stage }}</Badge>
        </div>

        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3 text-[12px]">
            <div class="rounded-[6px] border border-default bg-subtle px-3 py-2">
                <dt class="text-secondary">Contacts</dt>
                <dd class="font-mono font-medium text-primary">{{ props.contacts.length }}</dd>
            </div>
            <div class="rounded-[6px] border border-default bg-subtle px-3 py-2">
                <dt class="text-secondary">Visible Deals</dt>
                <dd class="font-mono font-medium text-primary">{{ props.deals.length }}</dd>
            </div>
            <div class="rounded-[6px] border border-default bg-subtle px-3 py-2">
                <dt class="text-secondary">Timeline Events</dt>
                <dd class="font-mono font-medium text-primary">{{ props.timeline.items.length }}</dd>
            </div>
        </dl>
    </div>
</template>
