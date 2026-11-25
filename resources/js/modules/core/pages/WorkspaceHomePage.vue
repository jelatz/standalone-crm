<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { AlertCircle, ClipboardCheck, Inbox, Plus, RefreshCw } from 'lucide-vue-next';
import Badge, { type BadgeVariant } from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import { useAuthStore } from '@/stores/auth.store';
import { useDraftsStore } from '@/stores/drafts.store';
import { useRealtimeStore } from '@/stores/realtime.store';
import { useUnreadsStore } from '@/stores/unreads.store';

type QueueCategory = 'all' | 'handoffs' | 'deals' | 'triage' | 'archived';
type ViewState = 'ready' | 'loading' | 'error';

interface OperationalItem {
    id: string;
    code: string;
    account: string;
    subject: string;
    category: 'handoffs' | 'deals' | 'triage';
    owner: string;
    valueLabel: string;
    statusLabel: string;
    statusVariant: BadgeVariant;
    updatedAt: string;
}

const props = withDefaults(
    defineProps<{
        initialState?: ViewState;
        initialError?: string;
    }>(),
    {
        initialState: 'ready',
        initialError: '',
    },
);

const authStore = useAuthStore();
const realtimeStore = useRealtimeStore();
const unreadsStore = useUnreadsStore();
const draftsStore = useDraftsStore();

const viewState = ref<ViewState>(props.initialState);
const loadError = ref<string>(props.initialError);
const selectedCategory = ref<QueueCategory>('all');

const sampleQueue: OperationalItem[] = [
    {
        id: 'op-1',
        code: 'HND-2026-0118',
        account: 'Northwind Logistics',
        subject: 'Regional WMS onboarding handoff & warehouse EDI provisioning',
        category: 'handoffs',
        owner: 'Operations Desk',
        valueLabel: '$284,000',
        statusLabel: 'pending_review',
        statusVariant: 'warning',
        updatedAt: '09:42',
    },
    {
        id: 'op-2',
        code: 'PRJ-2026-0042',
        account: 'Pacific Meridian Health',
        subject: 'Clinical portal SSO rollout and HIPAA audit checklist',
        category: 'deals',
        owner: 'Enterprise Delivery',
        valueLabel: '$512,000',
        statusLabel: 'on_track',
        statusVariant: 'success',
        updatedAt: '09:18',
    },
    {
        id: 'op-3',
        code: 'INB-2026-0891',
        account: 'Cascadia Cold Storage Group',
        subject: 'Inbound billing contact update and secondary MX verification',
        category: 'triage',
        owner: 'Support Triage',
        valueLabel: 'SLA 2h',
        statusLabel: 'unassigned',
        statusVariant: 'cobalt',
        updatedAt: '08:54',
    },
    {
        id: 'op-4',
        code: 'HND-2026-0117',
        account: 'Harborview Municipal Transit Authority',
        subject: 'Fleet telemetry ingestion contract awaiting PM sign-off',
        category: 'handoffs',
        owner: 'PMO Intake',
        valueLabel: '$396,500',
        statusLabel: 'at_risk',
        statusVariant: 'warning',
        updatedAt: 'Yesterday',
    },
    {
        id: 'op-5',
        code: 'DL-2026-0309',
        account: 'Redwood Precision Components',
        subject: 'Multi-site procurement expansion renewal stage gate',
        category: 'deals',
        owner: 'Account Management',
        valueLabel: '$236,000',
        statusLabel: 'proposal',
        statusVariant: 'neutral',
        updatedAt: 'Yesterday',
    },
];

const categoryTabs: { key: QueueCategory; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'handoffs', label: 'Handoffs' },
    { key: 'deals', label: 'Deals' },
    { key: 'triage', label: 'Triage' },
    { key: 'archived', label: 'Escalated' },
];

const filteredQueue = computed(() => {
    if (selectedCategory.value === 'all') {
        return sampleQueue;
    }

    return sampleQueue.filter((item) => item.category === selectedCategory.value);
});

const pendingHandoffsDisplay = computed(() =>
    unreadsStore.handoffs > 0 ? unreadsStore.handoffs : 2,
);

const unreadTriageDisplay = computed(
    () => unreadsStore.chat + unreadsStore.sharedInbox,
);

const connectionBadgeVariant = computed<BadgeVariant>(() => {
    switch (realtimeStore.status) {
        case 'connected':
            return 'success';
        case 'reconnecting':
            return 'warning';
        default:
            return 'danger';
    }
});

const connectionText = computed(() => {
    switch (realtimeStore.status) {
        case 'connected':
            return 'Live';
        case 'reconnecting':
            return 'Reconnecting';
        default:
            return 'Offline';
    }
});

function handleRefresh(): void {
    viewState.value = 'loading';
    loadError.value = '';

    setTimeout(() => {
        viewState.value = 'ready';
    }, 120);
}

function handleRetry(): void {
    handleRefresh();
}

function startOperationalDraft(item?: OperationalItem): void {
    draftsStore.openDraft({
        kind: 'email',
        title: item ? `${item.code} ${item.account}` : 'Operational handoff note',
        targetLabel: item ? 'operations@company.internal' : 'delivery-lead@company.internal',
        bodyText: item ? `Following up on ${item.subject}.` : '',
    });
}
</script>

<template>
    <Head title="Operational Overview" />

    <div class="space-y-4">
        <!-- Context & Refresh Header -->
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <p class="text-[13px] text-secondary truncate">
                    Unified operational workbench across CRM pipelines, approval-gated handoffs, and live triage queues.
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <span class="font-mono text-[11px] text-muted">
                    Preview fixtures
                </span>
                <Button
                    variant="secondary"
                    size="sm"
                    :loading="viewState === 'loading'"
                    @click="handleRefresh"
                >
                    <RefreshCw class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>Refresh</span>
                </Button>
                <Button
                    variant="primary"
                    size="sm"
                    @click="startOperationalDraft()"
                >
                    <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>Draft Note</span>
                </Button>
            </div>
        </div>

        <!-- 4-Column Hairline KPI Strip -->
        <section
            aria-label="Operational summary metrics"
            class="grid grid-cols-1 divide-y divide-default overflow-hidden rounded-[8px] border border-default bg-surface sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-4 lg:divide-x"
        >
            <div class="p-3.5 space-y-1 sm:border-b sm:border-r lg:border-b-0 lg:border-r-0 border-default">
                <p class="text-[12px] font-medium text-secondary">
                    Active Pipeline
                </p>
                <p class="font-mono text-[18px] font-semibold tracking-[-0.02em] tabular-nums text-primary">
                    $1,428,500
                </p>
                <p class="text-[11px] text-muted">
                    14 open deals in preview
                </p>
            </div>

            <div class="p-3.5 space-y-1 sm:border-b lg:border-b-0 border-default">
                <p class="text-[12px] font-medium text-secondary">
                    Pending Handoffs
                </p>
                <p class="font-mono text-[18px] font-semibold tracking-[-0.02em] tabular-nums text-primary">
                    {{ pendingHandoffsDisplay }}
                </p>
                <p class="text-[11px] text-muted">
                    Awaiting Operations sign-off
                </p>
            </div>

            <div class="p-3.5 space-y-1 sm:border-r lg:border-r-0 border-default">
                <p class="text-[12px] font-medium text-secondary">
                    Active Projects
                </p>
                <p class="font-mono text-[18px] font-semibold tracking-[-0.02em] tabular-nums text-primary">
                    18
                </p>
                <p class="text-[11px] text-muted">
                    2 flagged for scope check
                </p>
            </div>

            <div class="p-3.5 space-y-1">
                <p class="text-[12px] font-medium text-secondary">
                    Unread Messaging
                </p>
                <p class="font-mono text-[18px] font-semibold tracking-[-0.02em] tabular-nums text-primary">
                    {{ unreadTriageDisplay }}
                </p>
                <p class="text-[11px] text-muted tabular-nums">
                    {{ unreadsStore.chat }} chat, {{ unreadsStore.sharedInbox }} shared mail
                </p>
            </div>
        </section>

        <!-- Split Operational Workbench -->
        <section
            aria-label="Operational workbench"
            class="grid grid-cols-1 overflow-hidden rounded-[8px] border border-default bg-surface lg:grid-cols-12"
        >
            <!-- Left Pane: 32px Density Queue -->
            <div class="min-w-0 lg:col-span-8 lg:border-r lg:border-default">
                <div class="flex flex-col gap-2 border-b border-default px-4 py-2.5 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-[14px] font-semibold text-primary">
                        Active Operations Queue
                    </h2>

                    <div class="flex flex-wrap items-center gap-1" role="tablist" aria-label="Queue filter">
                        <button
                            v-for="tab in categoryTabs"
                            :key="tab.key"
                            type="button"
                            role="tab"
                            :aria-selected="selectedCategory === tab.key ? 'true' : 'false'"
                            :class="[
                                'h-6 rounded-[4px] px-2 text-[11px] font-medium transition-colors duration-150 cursor-pointer',
                                selectedCategory === tab.key
                                    ? 'bg-accent-primary text-accent-contrast'
                                    : 'bg-subtle text-secondary hover:text-primary',
                            ]"
                            @click="selectedCategory = tab.key"
                        >
                            {{ tab.label }}
                        </button>
                    </div>
                </div>

                <!-- Loading State -->
                <div
                    v-if="viewState === 'loading'"
                    data-testid="overview-loading-state"
                    class="divide-y divide-default"
                >
                    <div
                        v-for="row in 4"
                        :key="row"
                        class="flex h-8 items-center justify-between gap-4 px-4"
                    >
                        <div class="h-3 w-28 rounded bg-subtle" />
                        <div class="h-3 flex-1 rounded bg-subtle" />
                        <div class="h-3 w-16 rounded bg-subtle" />
                    </div>
                </div>

                <!-- Error State -->
                <div
                    v-else-if="viewState === 'error'"
                    role="alert"
                    data-testid="overview-error-state"
                    class="m-4 flex items-start justify-between gap-3 rounded-[6px] border border-status-danger/30 bg-status-danger/10 p-3 text-[13px] text-status-danger"
                >
                    <div class="flex items-start gap-2">
                        <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                        <div>
                            <p class="font-medium">Unable to load operational feed</p>
                            <p class="text-[12px] opacity-90">
                                {{ loadError || 'The workspace API timed out while fetching recent activity.' }}
                            </p>
                        </div>
                    </div>
                    <Button variant="secondary" size="sm" @click="handleRetry">
                        Retry
                    </Button>
                </div>

                <!-- Empty State -->
                <div
                    v-else-if="filteredQueue.length === 0"
                    data-testid="overview-empty-state"
                    class="px-4 py-8 text-center space-y-2"
                >
                    <p class="text-[13px] font-medium text-primary">
                        No items match this filter
                    </p>
                    <p class="text-[12px] text-secondary">
                        Clear the active category filter to inspect pending handoffs, open deals, and inbound triage.
                    </p>
                    <Button variant="secondary" size="sm" @click="selectedCategory = 'all'">
                        Reset filter
                    </Button>
                </div>

                <!-- Ready Table (32px row density) -->
                <div v-else class="overflow-x-auto">
                    <table class="w-full border-collapse text-left">
                        <thead>
                            <tr class="h-8 border-b border-default bg-subtle text-[11px] font-medium text-secondary">
                                <th class="px-4 py-1.5 font-medium whitespace-nowrap">Code</th>
                                <th class="px-3 py-1.5 font-medium">Account &amp; Item</th>
                                <th class="px-3 py-1.5 font-medium whitespace-nowrap">Status</th>
                                <th class="px-3 py-1.5 text-right font-medium whitespace-nowrap">Value / SLA</th>
                                <th class="px-4 py-1.5 text-right font-medium whitespace-nowrap">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-default text-[13px]">
                            <tr
                                v-for="item in filteredQueue"
                                :key="item.id"
                                class="h-8 transition-colors duration-150 hover:bg-subtle/60"
                            >
                                <td class="px-4 py-1.5 font-mono text-[12px] tabular-nums text-secondary whitespace-nowrap">
                                    {{ item.code }}
                                </td>
                                <td class="px-3 py-1.5 min-w-0 max-w-[260px]">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span class="font-medium text-primary truncate">
                                            {{ item.account }}
                                        </span>
                                        <span class="hidden sm:inline text-[12px] text-secondary truncate">
                                            {{ item.subject }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-3 py-1.5 whitespace-nowrap">
                                    <Badge :variant="item.statusVariant">
                                        {{ item.statusLabel }}
                                    </Badge>
                                </td>
                                <td class="px-3 py-1.5 text-right font-mono text-[12px] tabular-nums text-primary whitespace-nowrap">
                                    {{ item.valueLabel }}
                                </td>
                                <td class="px-4 py-1.5 text-right whitespace-nowrap">
                                    <button
                                        type="button"
                                        class="text-[12px] font-medium text-accent-primary hover:text-accent-hover cursor-pointer"
                                        @click="startOperationalDraft(item)"
                                    >
                                        Draft note
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right Pane: Session & Triage Summary -->
            <aside aria-label="Workspace telemetry summary" class="min-w-0 p-4 space-y-4 lg:col-span-4">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-[14px] font-semibold text-primary">
                        System &amp; Session
                    </h2>
                    <Badge :variant="connectionBadgeVariant">
                        {{ connectionText }}
                    </Badge>
                </div>

                <dl class="divide-y divide-default rounded-[6px] border border-default bg-subtle text-[12px]">
                    <div class="flex items-center justify-between gap-2 px-3 py-2">
                        <dt class="text-secondary">Operator</dt>
                        <dd class="truncate font-medium text-primary">
                            {{ authStore.user?.name ?? 'Authenticated User' }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-2 px-3 py-2">
                        <dt class="text-secondary">Employee Code</dt>
                        <dd class="font-mono tabular-nums text-primary">
                            {{ authStore.user?.employee_code ?? 'EMP-LOCAL' }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-2 px-3 py-2">
                        <dt class="text-secondary">Open Drafts</dt>
                        <dd class="font-mono tabular-nums text-primary">
                            {{ draftsStore.draftCount }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-2 px-3 py-2">
                        <dt class="text-secondary">Total Unreads</dt>
                        <dd class="font-mono tabular-nums text-primary">
                            {{ unreadsStore.totalUnread }}
                        </dd>
                    </div>
                </dl>

                <div class="space-y-2 pt-1">
                    <p class="text-[11px] font-medium uppercase tracking-wider text-secondary">
                        Module Entrypoints
                    </p>
                    <div class="space-y-1.5">
                        <Link
                            href="/app/projects/handoffs"
                            class="flex h-8 items-center justify-between rounded-[6px] border border-default bg-surface px-3 text-[12px] font-medium text-primary transition-colors duration-150 hover:bg-subtle"
                        >
                            <span class="flex items-center gap-2">
                                <ClipboardCheck class="h-3.5 w-3.5 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                                <span>Handoff Intake Queue</span>
                            </span>
                            <Badge variant="warning">{{ pendingHandoffsDisplay }}</Badge>
                        </Link>
                        <Link
                            href="/app/email"
                            class="flex h-8 items-center justify-between rounded-[6px] border border-default bg-surface px-3 text-[12px] font-medium text-primary transition-colors duration-150 hover:bg-subtle"
                        >
                            <span class="flex items-center gap-2">
                                <Inbox class="h-3.5 w-3.5 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                                <span>Shared &amp; Personal Inbox</span>
                            </span>
                            <Badge variant="neutral">{{ unreadsStore.sharedInbox }}</Badge>
                        </Link>
                    </div>
                </div>
            </aside>
        </section>
    </div>
</template>
