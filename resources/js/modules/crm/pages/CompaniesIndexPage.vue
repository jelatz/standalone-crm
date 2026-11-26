<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/components/ui/Badge.vue';

const props = defineProps<{
    companies: {
        data: App.Modules.Crm.Companies.Data.CompanyListItemData[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: App.Modules.Crm.Companies.Data.CompanyFilterData;
    filterDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    customFieldDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    assignableUsers: App.Modules.Core.Users.Data.UserSummaryData[];
    lifecycleStages: string[];
    can: {
        create: boolean;
    };
}>();
</script>

<template>
    <Head title="Companies" />

    <div class="rounded-[8px] border border-default bg-surface p-5 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                    Companies
                </h2>
                <p class="text-[13px] text-secondary">
                    Silo-scoped accounts, lifecycle stages, and filterable custom metadata.
                </p>
            </div>
            <Badge variant="cobalt">{{ props.companies.total }} Companies</Badge>
        </div>

        <ul
            v-if="props.companies.data.length > 0"
            class="divide-y divide-default rounded-[6px] border border-default"
        >
            <li
                v-for="company in props.companies.data"
                :key="company.id"
                class="flex items-center justify-between px-3.5 py-2.5 text-[13px]"
            >
                <Link :href="`/app/crm/companies/${company.id}`" class="font-medium text-primary hover:underline">
                    {{ company.name }}
                </Link>
                <span class="font-mono text-[12px] text-secondary">
                    {{ company.domain ?? company.lifecycle_stage }}
                </span>
            </li>
        </ul>
    </div>
</template>
