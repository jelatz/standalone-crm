<script setup lang="ts">
import { computed, h, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowUpRight,
    ClipboardCheck,
    RefreshCw,
    WifiOff,
} from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/data-table/DataTable.vue';
import { createDataTableColumnHelper } from '@/components/data-table/tableFeatures';
import HandoffReviewPanel from '@/modules/projects/components/HandoffReviewPanel.vue';
import {
    handoffStatusLabel,
    handoffStatusVariant,
    toHandoffSummaryFromDetail,
} from '@/modules/projects/utils/projectFormatters';
import {
    formatDateTime,
    formatEnumLabel,
    formatMoneyCents,
    formatShortDate,
} from '@/modules/crm/utils/formatters';
import { useRealtimeStore } from '@/stores/realtime.store';

type HandoffRow = App.Modules.Projects.Handoffs.Data.DealHandoffSummaryData;

const props = withDefaults(
    defineProps<{
        handoffs: HandoffRow[];
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
        initialError?: string | null;
    }>(),
    {
        initialError: null,
    },
);

const realtimeStore = useRealtimeStore();

const localHandoffs = ref<HandoffRow[]>([...props.handoffs]);
const localCounts = ref<Record<string, number>>({ ...props.statusCounts });
const selectedHandoffId = ref<string | null>(props.handoffs[0]?.id ?? null);
const navigating = ref<boolean>(false);
const pageError = ref<string | null>(props.initialError);

watch(
    () => props.handoffs,
    (next) => {
        localHandoffs.value = [...next];
        if (!selectedHandoffId.value || !next.some((item) => item.id === selectedHandoffId.value)) {
            selectedHandoffId.value = next[0]?.id ?? null;
        }
    },
    { deep: true },
);

watch(
    () => props.statusCounts,
    (next) => {
        localCounts.value = { ...next };
    },
    { deep: true },
);

const totalQueueCount = computed(() =>
    Object.values(localCounts.value).reduce((sum, val) => sum + Number(val || 0), 0),
);

const pendingTotalCents = computed(() =>
    localHandoffs.value
        .filter((item) => item.status === 'pending_review')
        .reduce((sum, item) => sum + Number(item.deal_amount_cents ?? 0), 0),
);

const statusTabs = computed(() => [
    { key: null, label: 'All', count: totalQueueCount.value },
    { key: 'pending_review', label: 'Pending Review', count: localCounts.value.pending_review ?? 0 },
    {
        key: 'changes_requested',
        label: 'Changes Requested',
        count: localCounts.value.changes_requested ?? 0,
    },
    { key: 'approved', label: 'Approved', count: localCounts.value.approved ?? 0 },
    { key: 'provisioned', label: 'Provisioned', count: localCounts.value.provisioned ?? 0 },
    { key: 'rejected', label: 'Rejected', count: localCounts.value.rejected ?? 0 },
]);

const selectedHandoff = computed<HandoffRow | null>(
    () => localHandoffs.value.find((item) => item.id === selectedHandoffId.value) ?? null,
);

function selectStatusTab(statusKey: string | null): void {
    navigating.value = true;
    pageError.value = null;
    router.get(
        '/app/projects/handoffs',
        statusKey ? { status: statusKey } : {},
        {
            preserveState: true,
            replace: true,
            onError: () => {
                pageError.value = 'Unable to load handoff intake queue.';
            },
            onFinish: () => {
                navigating.value = false;
            },
        },
    );
}

function refreshQueue(): void {
    navigating.value = true;
    pageError.value = null;
    router.reload({
        only: ['handoffs', 'statusCounts', 'unreadCounts'],
        onError: () => {
            pageError.value = 'Unable to refresh handoff intake queue.';
        },
        onFinish: () => {
            navigating.value = false;
        },
    });
}

function handleHandoffUpdated(detail: App.Modules.Projects.Handoffs.Data.DealHandoffDetailData): void {
    const summary = toHandoffSummaryFromDetail(detail);
    localHandoffs.value = localHandoffs.value.map((item) =>
        item.id === summary.id ? summary : item,
    );
    if (typeof router?.reload === 'function') {
        router.reload({ only: ['handoffs', 'statusCounts', 'unreadCounts'] });
    }
}

const columnHelper = createDataTableColumnHelper<HandoffRow>();

const columns = [
    columnHelper.accessor('deal_title', {
        id: 'deal_title',
        header: 'Deal & Account',
        cell: (info) => {
            const row = info.row.original;
            return h('div', { class: 'min-w-0 max-w-[240px]' }, [
                h(
                    'button',
                    {
                        type: 'button',
                        class: 'block w-full truncate text-left font-medium text-primary hover:text-accent-primary cursor-pointer',
                        onClick: (event: Event) => {
                            event.stopPropagation();
                            selectedHandoffId.value = row.id;
                        },
                    },
                    row.deal_title ?? 'Won Deal',
                ),
                h(
                    'span',
                    { class: 'block truncate text-[11px] text-secondary' },
                    row.company_name ?? 'Client organization',
                ),
            ]);
        },
    }),
    columnHelper.accessor('deal_amount_cents', {
        id: 'deal_amount_cents',
        header: 'Value',
        meta: { align: 'right', numeric: true },
        cell: (info) =>
            formatMoneyCents(info.getValue(), info.row.original.deal_currency ?? 'USD'),
    }),
    columnHelper.accessor('status', {
        id: 'status',
        header: 'Status',
        cell: (info) =>
            h(
                Badge,
                { variant: handoffStatusVariant(info.getValue()) },
                () => handoffStatusLabel(info.getValue()),
            ),
    }),
    columnHelper.accessor('target_kickoff_date', {
        id: 'target_kickoff_date',
        header: 'Kickoff',
        meta: { align: 'right', numeric: true },
        cell: (info) => formatShortDate(info.getValue()),
    }),
    columnHelper.accessor('submitted_by', {
        id: 'submitted_by',
        header: 'Submitted By',
        enableSorting: false,
        cell: (info) =>
            h(
                'span',
                { class: 'block max-w-[120px] truncate text-[12px] text-secondary' },
                info.getValue()?.name ?? '—',
            ),
    }),
    columnHelper.accessor('submitted_at', {
        id: 'submitted_at',
        header: 'Submitted',
        meta: { align: 'right', numeric: true },
        cell: (info) => formatDateTime(info.getValue()),
    }),
    columnHelper.display({
        id: 'open',
        header: '',
        cell: (info) =>
            h(
                Link,
                {
                    href: `/app/projects/handoffs/${info.row.original.id}`,
                    class: 'inline-flex h-6 w-6 items-center justify-center rounded-[4px] border border-default bg-subtle text-secondary hover:text-primary',
                    'aria-label': `Open handoff ${info.row.original.deal_title ?? info.row.original.id}`,
                    onClick: (event: Event) => event.stopPropagation(),
                },
                () => h(ArrowUpRight, { class: 'h-3.5 w-3.5', strokeWidth: 1.75 }),
            ),
    }),
];
</script>

<template>
    <Head title="Handoff Intake" />

    <div class="space-y-4">
        <!-- Offline / Reconnecting Banner -->
        <div
            v-if="realtimeStore.status === 'disconnected' && realtimeStore.lastError"
            role="status"
            data-testid="handoff-intake-offline-banner"
            class="flex items-center justify-between gap-2 rounded-[8px] border border-status-warning/40 bg-status-warning/10 px-3.5 py-2 text-[12px] text-primary"
        >
            <div class="flex items-center gap-2">
                <WifiOff class="h-4 w-4 shrink-0 text-status-warning" :stroke-width="1.75" aria-hidden="true" />
                <span>Realtime connection unavailable. Use Refresh Queue to sync updates.</span>
            </div>
            <Button variant="secondary" size="sm" @click="refreshQueue">
                Refresh Queue
            </Button>
        </div>

        <!-- Header & Live Queue Metrics Strip -->
        <header class="flex flex-col gap-3 rounded-[8px] border border-default bg-surface px-4 py-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0 space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                    <ClipboardCheck class="h-4 w-4 text-accent-primary" :stroke-width="1.75" aria-hidden="true" />
                    <h2 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                        Handoff Intake Queue
                    </h2>
                    <Badge variant="warning" data-testid="handoff-pending-count-pill">
                        {{ localCounts.pending_review ?? 0 }} Pending Review
                    </Badge>
                </div>
                <div class="flex flex-wrap items-center gap-4 text-[12px] text-secondary">
                    <span>
                        Pending value in view:
                        <strong class="font-mono tabular-nums text-primary">
                            {{ formatMoneyCents(pendingTotalCents, 'USD') }}
                        </strong>
                    </span>
                    <span>
                        Changes requested:
                        <strong class="font-mono tabular-nums text-primary">
                            {{ localCounts.changes_requested ?? 0 }}
                        </strong>
                    </span>
                    <span>
                        Provisioned:
                        <strong class="font-mono tabular-nums text-primary">
                            {{ localCounts.provisioned ?? 0 }}
                        </strong>
                    </span>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <Button
                    variant="secondary"
                    size="sm"
                    :loading="navigating"
                    data-testid="handoff-refresh-btn"
                    @click="refreshQueue"
                >
                    <RefreshCw class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>Refresh Queue</span>
                </Button>
            </div>
        </header>

        <!-- Error Banner -->
        <div
            v-if="pageError"
            role="alert"
            data-testid="handoff-intake-error"
            class="flex items-center justify-between gap-2 rounded-[8px] border border-status-danger/35 bg-status-danger/10 px-3.5 py-2.5 text-[12px] text-status-danger"
        >
            <div class="flex items-center gap-2">
                <AlertCircle class="h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                <span>{{ pageError }}</span>
            </div>
            <Button variant="secondary" size="sm" @click="refreshQueue">Retry</Button>
        </div>

        <!-- Status Filter Tabs -->
        <nav
            aria-label="Handoff queue status filters"
            class="flex items-center gap-1 overflow-x-auto border-b border-default pb-2"
        >
            <button
                v-for="tab in statusTabs"
                :key="tab.key ?? 'all'"
                type="button"
                :aria-current="props.activeStatus === tab.key ? 'page' : undefined"
                :data-testid="`handoff-tab-${tab.key ?? 'all'}`"
                :class="[
                    'inline-flex h-7 shrink-0 items-center gap-1.5 rounded-[6px] border px-2.5 text-[12px] font-medium transition-colors duration-150 cursor-pointer',
                    props.activeStatus === tab.key
                        ? 'border-accent-primary/40 bg-accent-primary/10 text-accent-primary'
                        : 'border-default bg-surface text-secondary hover:text-primary',
                ]"
                @click="selectStatusTab(tab.key)"
            >
                <span>{{ tab.label }}</span>
                <span class="rounded-full bg-subtle px-1.5 py-0.5 font-mono text-[10px] tabular-nums text-primary">
                    {{ tab.count }}
                </span>
            </button>
        </nav>

        <!-- Split Workspace: Queue Table (Left) + Sticky 440px Review Panel (Right) -->
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start">
            <div class="min-w-0 flex-1">
                <DataTable
                    :columns="columns"
                    :data="localHandoffs"
                    :loading="navigating"
                    :error="pageError"
                    :clickable-rows="true"
                    :row-key="(row) => row.id"
                    empty-title="No handoffs match this status"
                    empty-description="Submitted won deals awaiting delivery intake will appear here."
                    test-id="handoffs-queue-table"
                    @row-click="selectedHandoffId = $event.id"
                    @retry="refreshQueue"
                >
                    <template v-if="props.activeStatus" #empty>
                        <div class="space-y-2 py-6 text-center">
                            <p class="text-[13px] font-medium text-primary">
                                No handoffs in {{ formatEnumLabel(props.activeStatus) }}
                            </p>
                            <p class="text-[12px] text-secondary">
                                Switch back to All statuses to inspect other submitted deals.
                            </p>
                            <Button variant="secondary" size="sm" @click="selectStatusTab(null)">
                                View All Handoffs
                            </Button>
                        </div>
                    </template>
                </DataTable>
            </div>

            <div class="w-full shrink-0 xl:sticky xl:top-4 xl:w-[440px]">
                <HandoffReviewPanel
                    :handoff="selectedHandoff"
                    :templates="props.templates"
                    :assignable-pms="props.assignablePms"
                    :assignable-ams="props.assignableAms"
                    :can-approve="props.can.approve"
                    :can-request-changes="props.can.review"
                    :can-reject="props.can.review"
                    @updated="handleHandoffUpdated"
                    @refresh-requested="refreshQueue"
                />
            </div>
        </div>
    </div>
</template>
