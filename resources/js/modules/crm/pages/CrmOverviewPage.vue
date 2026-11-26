<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertCircle,
    Award,
    Crosshair,
    DollarSign,
    Kanban,
    RefreshCw,
    TrendingUp,
} from 'lucide-vue-next';
import Badge, { type BadgeVariant } from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import { formatEnumLabel, formatMoneyCents, formatShortDate } from '@/modules/crm/utils/formatters';

const props = withDefaults(
    defineProps<{
        overview: App.Modules.Crm.Overview.Data.CrmOverviewData;
        can: {
            viewLeads: boolean;
            createLead: boolean;
            viewCompanies: boolean;
            viewContacts: boolean;
            viewDeals: boolean;
            createDeal: boolean;
        };
        initialState?: 'ready' | 'loading' | 'error';
        initialError?: string;
    }>(),
    {
        initialState: 'ready',
        initialError: '',
    },
);

const retrying = ref(false);

const totalStageVolumeCents = computed(() =>
    props.overview.stage_breakdown.reduce((sum, stage) => sum + Math.max(0, stage.total_amount_cents), 0),
);

function leadStatusVariant(status: App.Modules.Crm.Leads.Enums.LeadStatusEnum): BadgeVariant {
    switch (status) {
        case 'qualified':
        case 'converted':
            return 'success';
        case 'contacted':
        case 'working':
            return 'warning';
        case 'unqualified':
            return 'danger';
        default:
            return 'cobalt';
    }
}

function stageTypeVariant(stageType: string): BadgeVariant {
    if (stageType === 'won') {
        return 'success';
    }
    if (stageType === 'lost') {
        return 'danger';
    }
    return 'cobalt';
}

function stageBarSegmentClass(stageType: string): string {
    if (stageType === 'won') {
        return 'bg-status-success';
    }
    if (stageType === 'lost') {
        return 'bg-status-danger/60';
    }
    return 'bg-accent-primary';
}

function stageSharePercent(amountCents: number): number {
    if (totalStageVolumeCents.value <= 0 || amountCents <= 0) {
        return 0;
    }
    return Math.max(2, Math.round((amountCents / totalStageVolumeCents.value) * 100));
}

function handleRetry(): void {
    if (retrying.value) {
        return;
    }
    retrying.value = true;
    router.reload({
        onFinish: () => {
            retrying.value = false;
        },
    });
}
</script>

<template>
    <Head title="CRM Overview" />

    <div class="space-y-4">
        <!-- Header Bar -->
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <h2 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                    Revenue &amp; Lead Operations
                </h2>
                <p class="mt-0.5 text-[12px] text-secondary">
                    Silo-scoped prospect intake, active deal pipeline, and monthly closed revenue.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <Link
                    v-if="props.can.viewLeads"
                    href="/app/crm/leads"
                    class="inline-flex h-8 items-center gap-1.5 rounded-[6px] border border-default bg-surface px-3 text-[13px] font-medium text-primary transition-colors duration-150 hover:bg-subtle"
                >
                    <Crosshair class="h-3.5 w-3.5 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                    <span>Leads</span>
                </Link>
                <Link
                    v-if="props.can.viewDeals"
                    href="/app/crm/deals"
                    class="inline-flex h-8 items-center gap-1.5 rounded-[6px] border border-transparent bg-accent-primary px-3 text-[13px] font-medium text-accent-contrast transition-colors duration-150 hover:bg-accent-hover"
                >
                    <Kanban class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>Deals Board</span>
                </Link>
            </div>
        </div>

        <!-- Loading Skeleton State -->
        <div
            v-if="props.initialState === 'loading'"
            data-testid="overview-loading"
            aria-busy="true"
            aria-label="Loading CRM overview"
            class="space-y-4"
        >
            <div class="grid grid-cols-1 divide-y divide-default rounded-[8px] border border-default bg-surface lg:grid-cols-4 lg:divide-x lg:divide-y-0">
                <div v-for="idx in 4" :key="idx" class="p-4 space-y-2">
                    <div class="h-3 w-24 rounded bg-subtle" />
                    <div class="h-6 w-32 rounded bg-subtle" />
                    <div class="h-3 w-28 rounded bg-subtle" />
                </div>
            </div>
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div v-for="panelIdx in 2" :key="panelIdx" class="rounded-[8px] border border-default bg-surface p-4 space-y-2.5">
                    <div class="h-4 w-40 rounded bg-subtle" />
                    <div v-for="rowIdx in 4" :key="rowIdx" class="h-8 rounded bg-subtle" />
                </div>
            </div>
        </div>

        <!-- Error State -->
        <div
            v-else-if="props.initialState === 'error'"
            role="alert"
            data-testid="overview-error"
            class="flex flex-col gap-3 rounded-[8px] border border-status-danger/30 bg-status-danger/10 px-4 py-3 text-[13px] text-status-danger sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex items-start gap-2 min-w-0">
                <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                <div class="min-w-0">
                    <p class="font-medium">Unable to load CRM overview metrics</p>
                    <p class="text-[12px] opacity-90 break-words">
                        {{ props.initialError || 'An unexpected error prevented the overview summary from loading.' }}
                    </p>
                </div>
            </div>
            <Button
                variant="secondary"
                size="sm"
                :loading="retrying"
                data-testid="overview-retry"
                @click="handleRetry"
            >
                <RefreshCw class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                <span>Retry</span>
            </Button>
        </div>

        <!-- Ready Dashboard -->
        <template v-else>
            <!-- 4-Column Hairline KPI Strip -->
            <section
                aria-label="Key CRM metrics"
                data-testid="overview-kpi-strip"
                class="grid grid-cols-1 divide-y divide-default overflow-hidden rounded-[8px] border border-default bg-surface lg:grid-cols-4 lg:divide-x lg:divide-y-0"
            >
                <div class="p-3.5 space-y-1.5">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[12px] font-medium text-secondary">Open Leads</span>
                        <Crosshair class="h-4 w-4 shrink-0 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                    </div>
                    <p class="font-mono text-[22px] font-semibold leading-7 tracking-[-0.02em] tabular-nums text-primary">
                        {{ props.overview.open_leads_count }}
                    </p>
                    <p class="font-mono text-[12px] tabular-nums text-secondary">
                        {{ props.overview.qualified_leads_count }} qualified
                    </p>
                </div>

                <div class="p-3.5 space-y-1.5">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[12px] font-medium text-secondary">Open Pipeline</span>
                        <DollarSign class="h-4 w-4 shrink-0 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                    </div>
                    <p class="font-mono text-[22px] font-semibold leading-7 tracking-[-0.02em] tabular-nums text-primary">
                        {{ formatMoneyCents(props.overview.open_pipeline_cents) }}
                    </p>
                    <p class="font-mono text-[12px] tabular-nums text-secondary">
                        {{ props.overview.open_deals_count }} active deals
                    </p>
                </div>

                <div class="p-3.5 space-y-1.5">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[12px] font-medium text-secondary">Weighted Forecast</span>
                        <TrendingUp class="h-4 w-4 shrink-0 text-accent-primary" :stroke-width="1.75" aria-hidden="true" />
                    </div>
                    <p class="font-mono text-[22px] font-semibold leading-7 tracking-[-0.02em] tabular-nums text-accent-primary">
                        {{ formatMoneyCents(props.overview.weighted_forecast_cents) }}
                    </p>
                    <p class="font-mono text-[12px] tabular-nums text-secondary">
                        {{ props.overview.companies_count }} companies · {{ props.overview.contacts_count }} contacts
                    </p>
                </div>

                <div class="p-3.5 space-y-1.5">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[12px] font-medium text-secondary">Won This Month</span>
                        <Award class="h-4 w-4 shrink-0 text-status-success" :stroke-width="1.75" aria-hidden="true" />
                    </div>
                    <p class="font-mono text-[22px] font-semibold leading-7 tracking-[-0.02em] tabular-nums text-primary">
                        {{ formatMoneyCents(props.overview.won_this_month_cents) }}
                    </p>
                    <p class="font-mono text-[12px] tabular-nums text-status-success">
                        {{ props.overview.won_this_month_count }} closed won
                    </p>
                </div>
            </section>

            <!-- Pipeline Stage Breakdown -->
            <section
                v-if="props.overview.stage_breakdown.length > 0"
                aria-label="Pipeline stage breakdown"
                data-testid="overview-stage-breakdown"
                class="overflow-hidden rounded-[8px] border border-default bg-surface"
            >
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-default px-3.5 py-2.5">
                    <div class="flex items-center gap-2 min-w-0">
                        <h3 class="truncate text-[14px] font-semibold text-primary">
                            {{ props.overview.pipeline_name ?? 'Active Pipeline' }}
                        </h3>
                        <Badge variant="neutral">
                            {{ props.overview.stage_breakdown.length }} stages
                        </Badge>
                    </div>
                    <Link
                        v-if="props.can.viewDeals"
                        href="/app/crm/deals"
                        class="text-[12px] font-medium text-accent-primary transition-colors duration-150 hover:text-accent-hover"
                    >
                        Open pipeline
                    </Link>
                </div>

                <!-- Slim Proportional Volume Strip -->
                <div
                    v-if="totalStageVolumeCents > 0"
                    class="flex h-1.5 w-full overflow-hidden border-b border-default bg-subtle"
                    aria-hidden="true"
                >
                    <div
                        v-for="stage in props.overview.stage_breakdown"
                        :key="`bar-${stage.stage_id}`"
                        :class="['h-full border-r border-surface last:border-r-0', stageBarSegmentClass(stage.stage_type)]"
                        :style="{ width: `${stageSharePercent(stage.total_amount_cents)}%` }"
                    />
                </div>

                <div
                    tabindex="0"
                    role="region"
                    aria-label="Pipeline stage metrics"
                    class="overflow-x-auto"
                >
                    <table class="w-full border-collapse text-left">
                        <thead>
                            <tr class="h-8 border-b border-default bg-subtle text-[11px] font-medium text-secondary">
                                <th scope="col" class="px-3.5 py-1.5 whitespace-nowrap">Stage</th>
                                <th scope="col" class="px-3 py-1.5 text-right whitespace-nowrap">Win Prob.</th>
                                <th scope="col" class="px-3 py-1.5 text-right whitespace-nowrap">Deals</th>
                                <th scope="col" class="px-3 py-1.5 text-right whitespace-nowrap">Total Value</th>
                                <th scope="col" class="px-3.5 py-1.5 text-right whitespace-nowrap">Weighted</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-default text-[13px]">
                            <tr
                                v-for="stage in props.overview.stage_breakdown"
                                :key="stage.stage_id"
                                class="h-8 transition-colors duration-150 hover:bg-subtle/60"
                            >
                                <td class="px-3.5 py-1.5">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span class="truncate font-medium text-primary">{{ stage.stage_name }}</span>
                                        <Badge :variant="stageTypeVariant(stage.stage_type)">
                                            {{ formatEnumLabel(stage.stage_type) }}
                                        </Badge>
                                    </div>
                                </td>
                                <td class="px-3 py-1.5 text-right font-mono text-[12px] tabular-nums text-secondary whitespace-nowrap">
                                    {{ stage.win_probability }}%
                                </td>
                                <td class="px-3 py-1.5 text-right font-mono text-[12px] tabular-nums text-primary whitespace-nowrap">
                                    {{ stage.deals_count }}
                                </td>
                                <td class="px-3 py-1.5 text-right font-mono text-[12px] tabular-nums text-primary whitespace-nowrap">
                                    {{ formatMoneyCents(stage.total_amount_cents) }}
                                </td>
                                <td class="px-3.5 py-1.5 text-right font-mono text-[12px] font-medium tabular-nums text-secondary whitespace-nowrap">
                                    {{ formatMoneyCents(stage.weighted_amount_cents) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Two-Column 32px Density Tables -->
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <!-- Left: Recent Open Leads -->
                <section
                    aria-label="Recent open leads"
                    data-testid="overview-recent-leads"
                    class="overflow-hidden rounded-[8px] border border-default bg-surface"
                >
                    <div class="flex h-10 items-center justify-between gap-2 border-b border-default px-3.5">
                        <div class="flex items-center gap-2 min-w-0">
                            <h3 class="truncate text-[14px] font-semibold text-primary">
                                Recent Open Leads
                            </h3>
                            <Badge variant="neutral">{{ props.overview.recent_leads.length }}</Badge>
                        </div>
                        <Link
                            v-if="props.can.viewLeads"
                            href="/app/crm/leads"
                            class="shrink-0 text-[12px] font-medium text-accent-primary transition-colors duration-150 hover:text-accent-hover"
                        >
                            View all
                        </Link>
                    </div>

                    <div
                        v-if="props.overview.recent_leads.length === 0"
                        data-testid="overview-recent-leads-empty"
                        class="px-4 py-8 text-center space-y-2"
                    >
                        <p class="text-[13px] font-medium text-primary">
                            No open leads in your queue
                        </p>
                        <p class="text-[12px] text-secondary">
                            Capture or qualify inbound prospects from the Leads directory.
                        </p>
                        <Link
                            v-if="props.can.viewLeads"
                            href="/app/crm/leads"
                            class="inline-flex h-7 items-center rounded-[6px] border border-default bg-surface px-2.5 text-[12px] font-medium text-primary hover:bg-subtle"
                        >
                            Go to Leads
                        </Link>
                    </div>

                    <div
                        v-else
                        tabindex="0"
                        role="region"
                        aria-label="Recent unqualified leads list"
                        class="overflow-x-auto"
                    >
                        <table class="w-full border-collapse text-left">
                            <thead>
                                <tr class="h-8 border-b border-default bg-subtle text-[11px] font-medium text-secondary">
                                    <th scope="col" class="px-3 py-1.5 whitespace-nowrap">Lead</th>
                                    <th scope="col" class="px-3 py-1.5 whitespace-nowrap">Status</th>
                                    <th scope="col" class="px-3 py-1.5 whitespace-nowrap">Source</th>
                                    <th scope="col" class="px-3 py-1.5 text-right whitespace-nowrap">Est. Value</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-default text-[13px]">
                                <tr
                                    v-for="lead in props.overview.recent_leads"
                                    :key="lead.id"
                                    class="h-8 transition-colors duration-150 hover:bg-subtle/60"
                                >
                                    <td class="px-3 py-1.5 max-w-[200px]">
                                        <div class="min-w-0">
                                            <Link
                                                :href="`/app/crm/leads/${lead.id}`"
                                                class="block truncate font-semibold text-primary hover:text-accent-primary"
                                            >
                                                {{ lead.full_name }}
                                            </Link>
                                            <p v-if="lead.company_name" class="truncate text-[11px] text-secondary">
                                                {{ lead.company_name }}
                                            </p>
                                        </div>
                                    </td>
                                    <td class="px-3 py-1.5 whitespace-nowrap">
                                        <Badge :variant="leadStatusVariant(lead.status)">
                                            {{ formatEnumLabel(lead.status) }}
                                        </Badge>
                                    </td>
                                    <td class="px-3 py-1.5 text-[12px] text-secondary whitespace-nowrap">
                                        {{ formatEnumLabel(lead.source) }}
                                    </td>
                                    <td class="px-3 py-1.5 text-right font-mono text-[12px] tabular-nums text-primary whitespace-nowrap">
                                        {{ formatMoneyCents(lead.estimated_value_cents, lead.currency) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Right: Deals Closing Soon -->
                <section
                    aria-label="Deals closing soon"
                    data-testid="overview-closing-soon-deals"
                    class="overflow-hidden rounded-[8px] border border-default bg-surface"
                >
                    <div class="flex h-10 items-center justify-between gap-2 border-b border-default px-3.5">
                        <div class="flex items-center gap-2 min-w-0">
                            <h3 class="truncate text-[14px] font-semibold text-primary">
                                Deals Closing Soon
                            </h3>
                            <Badge variant="neutral">{{ props.overview.closing_soon_deals.length }}</Badge>
                        </div>
                        <Link
                            v-if="props.can.viewDeals"
                            href="/app/crm/deals"
                            class="shrink-0 text-[12px] font-medium text-accent-primary transition-colors duration-150 hover:text-accent-hover"
                        >
                            View pipeline
                        </Link>
                    </div>

                    <div
                        v-if="props.overview.closing_soon_deals.length === 0"
                        data-testid="overview-closing-soon-empty"
                        class="px-4 py-8 text-center space-y-2"
                    >
                        <p class="text-[13px] font-medium text-primary">
                            No open deals scheduled to close
                        </p>
                        <p class="text-[12px] text-secondary">
                            Active opportunities with expected close dates will surface here automatically.
                        </p>
                        <Link
                            v-if="props.can.viewDeals"
                            href="/app/crm/deals"
                            class="inline-flex h-7 items-center rounded-[6px] border border-default bg-surface px-2.5 text-[12px] font-medium text-primary hover:bg-subtle"
                        >
                            Open Deals
                        </Link>
                    </div>

                    <div
                        v-else
                        tabindex="0"
                        role="region"
                        aria-label="Deals closing soon list"
                        class="overflow-x-auto"
                    >
                        <table class="w-full border-collapse text-left">
                            <thead>
                                <tr class="h-8 border-b border-default bg-subtle text-[11px] font-medium text-secondary">
                                    <th scope="col" class="px-3 py-1.5 whitespace-nowrap">Deal</th>
                                    <th scope="col" class="px-3 py-1.5 whitespace-nowrap">Stage</th>
                                    <th scope="col" class="px-3 py-1.5 whitespace-nowrap">Target Close</th>
                                    <th scope="col" class="px-3 py-1.5 text-right whitespace-nowrap">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-default text-[13px]">
                                <tr
                                    v-for="deal in props.overview.closing_soon_deals"
                                    :key="deal.id"
                                    class="h-8 transition-colors duration-150 hover:bg-subtle/60"
                                >
                                    <td class="px-3 py-1.5 max-w-[200px]">
                                        <div class="min-w-0">
                                            <Link
                                                :href="`/app/crm/deals/${deal.id}`"
                                                class="block truncate font-semibold text-primary hover:text-accent-primary"
                                            >
                                                {{ deal.title }}
                                            </Link>
                                            <p v-if="deal.company_name" class="truncate text-[11px] text-secondary">
                                                {{ deal.company_name }}
                                            </p>
                                        </div>
                                    </td>
                                    <td class="px-3 py-1.5 whitespace-nowrap">
                                        <div class="flex items-center gap-1.5">
                                            <Badge :variant="stageTypeVariant(deal.stage_type ?? 'open')">
                                                {{ deal.stage_name ?? 'Open' }}
                                            </Badge>
                                            <span class="font-mono text-[11px] tabular-nums text-secondary">
                                                {{ deal.effective_win_probability }}%
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-1.5 font-mono text-[12px] tabular-nums text-secondary whitespace-nowrap">
                                        {{ formatShortDate(deal.expected_close_date) }}
                                    </td>
                                    <td class="px-3 py-1.5 text-right font-mono text-[12px] font-medium tabular-nums text-primary whitespace-nowrap">
                                        {{ formatMoneyCents(deal.amount_cents, deal.currency) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </template>
    </div>
</template>
