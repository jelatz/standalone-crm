<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/components/ui/Badge.vue';

const props = defineProps<{
    contacts: {
        data: App.Modules.Crm.Contacts.Data.ContactListItemData[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: App.Modules.Crm.Contacts.Data.ContactFilterData;
    filterDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    customFieldDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    assignableUsers: App.Modules.Core.Users.Data.UserSummaryData[];
    companies: Array<{
        id: string;
        name: string;
        domain: string | null;
    }>;
    can: {
        create: boolean;
    };
}>();
</script>

<template>
    <Head title="Contacts" />

    <div class="rounded-[8px] border border-default bg-surface p-5 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                    Contacts
                </h2>
                <p class="text-[13px] text-secondary">
                    Stakeholder directory with multi-address email matching and silo scoping.
                </p>
            </div>
            <Badge variant="cobalt">{{ props.contacts.total }} Contacts</Badge>
        </div>

        <ul
            v-if="props.contacts.data.length > 0"
            class="divide-y divide-default rounded-[6px] border border-default"
        >
            <li
                v-for="contact in props.contacts.data"
                :key="contact.id"
                class="flex items-center justify-between px-3.5 py-2.5 text-[13px]"
            >
                <Link :href="`/app/crm/contacts/${contact.id}`" class="font-medium text-primary hover:underline">
                    {{ contact.full_name }}
                </Link>
                <span class="font-mono text-[12px] text-secondary">
                    {{ contact.primary_email ?? 'No email' }}
                </span>
            </li>
        </ul>
    </div>
</template>
