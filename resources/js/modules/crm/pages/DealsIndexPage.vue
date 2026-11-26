<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/components/ui/Badge.vue';

const props = defineProps<{
    view: 'kanban' | 'table';
    board: App.Modules.Crm.Deals.Data.DealKanbanBoardData | null;
    tableDeals: {
        data: App.Modules.Crm.Deals.Data.DealCardData[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    } | null;
    filters: App.Modules.Crm.Deals.Data.DealFilterData;
    pipelines: App.Modules.Crm.Pipelines.Data.PipelineData[];
    filterDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    customFieldDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
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
    can: {
        create: boolean;
    };
}>();
</script>

<template>
    <Head title="Deals" />

    <div class="rounded-[8px] border border-default bg-surface p-5 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                    Deals Pipeline
                </h2>
                <p class="text-[13px] text-secondary">
                    Multi-pipeline Kanban &amp; table views with optimistic stage moves and gate validation.
                </p>
            </div>
            <Badge variant="cobalt">
                {{ props.board ? `${props.board.total_deals_count} Deals` : `${props.tableDeals?.total ?? 0} Deals` }}
            </Badge>
        </div>

        <div v-if="props.board" class="grid grid-cols-1 gap-3 md:grid-cols-3">
            <div
                v-for="column in props.board.columns"
                :key="column.stage.id"
                class="rounded-[6px] border border-default bg-subtle p-3 space-y-2"
            >
                <div class="flex items-center justify-between text-[12px] font-medium text-primary">
                    <span>{{ column.stage.name }}</span>
                    <span class="font-mono text-secondary">{{ column.deals_count }}</span>
                </div>
                <ul class="space-y-1.5">
                    <li
                        v-for="deal in column.deals"
                        :key="deal.id"
                        class="rounded-[5px] border border-default bg-surface px-2.5 py-2 text-[12px]"
                    >
                        <Link :href="`/app/crm/deals/${deal.id}`" class="font-medium text-primary hover:underline">
                            {{ deal.title }}
                        </Link>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
