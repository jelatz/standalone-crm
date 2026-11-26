<script setup lang="ts">
import { computed, h, ref, useId, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { type SortingState } from '@tanstack/vue-table';
import {
    AlertCircle,
    Kanban,
    LayoutList,
    Plus,
    RotateCcw,
    Search,
} from 'lucide-vue-next';
import Badge, { type BadgeVariant } from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Input from '@/components/ui/Input.vue';
import DataTable from '@/components/data-table/DataTable.vue';
import { createDataTableColumnHelper } from '@/components/data-table/tableFeatures';
import CustomFieldsForm from '@/modules/crm/components/CustomFieldsForm.vue';
import DealKanbanBoard from '@/modules/crm/components/DealKanbanBoard.vue';
import {
    crmApi,
    extractFirstFieldErrors,
    StageGateValidationError,
    ValidationRequestError,
} from '@/modules/crm/api/crmApi';
import { useAuthStore } from '@/stores/auth.store';
import { formatMoneyCents, formatShortDate } from '@/modules/crm/utils/formatters';

type DealRow = App.Modules.Crm.Deals.Data.DealCardData;

const props = defineProps<{
    view: 'kanban' | 'table';
    board: App.Modules.Crm.Deals.Data.DealKanbanBoardData | null;
    tableDeals: {
        data: DealRow[];
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
    assignableAms?: App.Modules.Core.Users.Data.UserSummaryData[];
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
        submitHandoff?: boolean;
    };
}>();

const authStore = useAuthStore();
const page = usePage<{ auth?: { user?: App.Modules.Core.Auth.Data.AuthenticatedUserData | null } }>();

const activeUser = computed(() => authStore.user ?? page.props?.auth?.user ?? null);
const canMoveDeals = computed(() =>
    activeUser.value ? activeUser.value.permissions.includes('deals.move_stage') : false,
);
const canOverrideGates = computed(() =>
    activeUser.value ? activeUser.value.permissions.includes('deals.override_gates') : false,
);
const canSubmitHandoff = computed(
    () =>
        props.can.submitHandoff ??
        (activeUser.value ? activeUser.value.permissions.includes('handoffs.submit') : false),
);

const instanceId = useId();
const pipelineSelectId = `deals-pipeline-${instanceId}`;
const searchInputId = `deals-search-${instanceId}`;
const ownerSelectId = `deals-owner-${instanceId}`;
const companySelectId = `deals-company-${instanceId}`;
const stageSelectId = `deals-stage-${instanceId}`;

const selectedPipelineId = ref<string>(
    props.filters.pipeline_id ?? props.board?.pipeline.id ?? props.pipelines[0]?.id ?? '',
);
const searchDraft = ref<string>(props.filters.search ?? '');
const ownerDraft = ref<string>(props.filters.owner_id ?? '');
const companyDraft = ref<string>(props.filters.company_id ?? '');
const stageDraft = ref<string>(props.filters.stage_id ?? '');
const customFilterDraft = ref<Record<string, string>>({
    ...Object.fromEntries(
        Object.entries(props.filters.custom_fields ?? {}).map(([k, v]) => [k, v == null ? '' : String(v)]),
    ),
});
const navigating = ref<boolean>(false);

watch(
    () => props.filters,
    (next) => {
        selectedPipelineId.value =
            next.pipeline_id ?? props.board?.pipeline.id ?? props.pipelines[0]?.id ?? '';
        searchDraft.value = next.search ?? '';
        ownerDraft.value = next.owner_id ?? '';
        companyDraft.value = next.company_id ?? '';
        stageDraft.value = next.stage_id ?? '';
    },
);

const activePipeline = computed(
    () =>
        props.pipelines.find((p) => p.id === selectedPipelineId.value) ??
        props.board?.pipeline ??
        props.pipelines[0] ??
        null,
);

const serverSortFields = new Set([
    'board_position',
    'title',
    'amount_cents',
    'expected_close_date',
    'stage_entered_at',
    'updated_at',
    'created_at',
]);

const tableSorting = computed<SortingState>(() => {
    if (!props.filters.sort_by || !serverSortFields.has(props.filters.sort_by)) {
        return [];
    }
    return [{ id: props.filters.sort_by, desc: props.filters.sort_dir !== 'asc' }];
});

function buildFilterParams(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    const cleanCustom: Record<string, string> = {};
    for (const [key, val] of Object.entries(customFilterDraft.value)) {
        if (val !== '' && val !== undefined && val !== null) {
            cleanCustom[key] = val;
        }
    }

    const base: Record<string, unknown> = {
        view: props.view,
        pipeline_id: selectedPipelineId.value || undefined,
        stage_id: stageDraft.value || undefined,
        owner_id: ownerDraft.value || undefined,
        company_id: companyDraft.value || undefined,
        search: searchDraft.value.trim() || undefined,
        custom_fields: Object.keys(cleanCustom).length > 0 ? cleanCustom : undefined,
        sort_by: props.filters.sort_by ?? 'updated_at',
        sort_dir: props.filters.sort_dir ?? 'desc',
        page: 1,
        ...overrides,
    };

    return Object.fromEntries(Object.entries(base).filter(([, v]) => v !== undefined));
}

function applyFilters(overrides: Record<string, unknown> = {}): void {
    navigating.value = true;
    router.get('/app/crm/deals', buildFilterParams(overrides) as Record<string, any>, {
        preserveState: true,
        replace: true,
        onFinish: () => {
            navigating.value = false;
        },
    });
}

function switchView(nextView: 'kanban' | 'table'): void {
    if (props.view === nextView) {
        return;
    }
    applyFilters({
        view: nextView,
        page: 1,
        sort_by: nextView === 'kanban' ? 'board_position' : 'updated_at',
        sort_dir: nextView === 'kanban' ? 'asc' : 'desc',
    });
}

function handlePipelineChange(): void {
    stageDraft.value = '';
    applyFilters({ pipeline_id: selectedPipelineId.value || undefined, stage_id: undefined, page: 1 });
}

function resetFilters(): void {
    searchDraft.value = '';
    ownerDraft.value = '';
    companyDraft.value = '';
    stageDraft.value = '';
    customFilterDraft.value = {};
    applyFilters({
        search: undefined,
        owner_id: undefined,
        company_id: undefined,
        stage_id: undefined,
        custom_fields: undefined,
        page: 1,
    });
}

function handleSortingChange(next: SortingState): void {
    const first = next[0];
    if (!first || !serverSortFields.has(first.id)) {
        applyFilters({ sort_by: 'updated_at', sort_dir: 'desc', page: 1 });
        return;
    }
    applyFilters({
        sort_by: first.id,
        sort_dir: first.desc ? 'desc' : 'asc',
        page: 1,
    });
}

const hasActiveFilters = computed(
    () =>
        Boolean(searchDraft.value.trim()) ||
        Boolean(ownerDraft.value) ||
        Boolean(companyDraft.value) ||
        Boolean(stageDraft.value) ||
        Object.values(customFilterDraft.value).some((v) => v !== ''),
);

function stageBadgeVariant(stageType: App.Modules.Crm.Pipelines.Enums.PipelineStageTypeEnum | null): BadgeVariant {
    if (stageType === 'won') {
        return 'success';
    }
    if (stageType === 'lost') {
        return 'danger';
    }
    return 'cobalt';
}

const columnHelper = createDataTableColumnHelper<DealRow>();

const tableColumns = [
    columnHelper.accessor('title', {
        id: 'title',
        header: 'Deal',
        cell: (info) => {
            const row = info.row.original;
            return h('div', { class: 'min-w-0 max-w-[260px]' }, [
                h(
                    Link,
                    {
                        href: `/app/crm/deals/${row.id}`,
                        class: 'block truncate font-semibold text-primary hover:text-accent-primary',
                    },
                    () => row.title,
                ),
                h(
                    'span',
                    { class: 'block truncate text-[11px] text-secondary' },
                    row.company_name ?? 'Restricted account',
                ),
            ]);
        },
    }),
    columnHelper.accessor('stage_name', {
        id: 'stage_name',
        header: 'Stage',
        enableSorting: false,
        cell: (info) => {
            const row = info.row.original;
            return h('div', { class: 'flex items-center gap-1.5' }, [
                h(Badge, { variant: stageBadgeVariant(row.stage_type) }, () => info.getValue() ?? 'Stage'),
                row.is_rotting ? h(Badge, { variant: 'warning' }, () => 'Rotting') : null,
            ]);
        },
    }),
    columnHelper.accessor('amount_cents', {
        id: 'amount_cents',
        header: 'Amount',
        meta: { align: 'right', numeric: true },
        cell: (info) => formatMoneyCents(info.getValue(), info.row.original.currency),
    }),
    columnHelper.accessor('expected_close_date', {
        id: 'expected_close_date',
        header: 'Close Date',
        meta: { align: 'right', numeric: true },
        cell: (info) => formatShortDate(info.getValue()),
    }),
    columnHelper.accessor('owner_name', {
        id: 'owner_name',
        header: 'Owner',
        enableSorting: false,
        cell: (info) =>
            h('span', { class: 'block max-w-[140px] truncate text-[12px] text-secondary' }, info.getValue() || '—'),
    }),
    columnHelper.accessor('updated_at', {
        id: 'updated_at',
        header: 'Updated',
        meta: { align: 'right', numeric: true },
        cell: (info) => formatShortDate(info.getValue()),
    }),
];

const paginationSummary = computed(() => {
    if (!props.tableDeals) {
        return '';
    }
    const { current_page, per_page, total } = props.tableDeals;
    if (total === 0) {
        return '0 deals';
    }
    const start = (current_page - 1) * per_page + 1;
    const end = Math.min(current_page * per_page, total);
    return `${start}–${end} of ${total}`;
});

// Create Deal Modal
const createOpen = ref<boolean>(false);
const createSubmitting = ref<boolean>(false);
const createErrorBanner = ref<string>('');
const createFieldErrors = ref<Record<string, string>>({});

const createForm = ref({
    company_id: '',
    title: '',
    pipeline_id: '',
    stage_id: '',
    primary_contact_id: '',
    account_manager_id: '',
    amount_dollars: '',
    currency: 'USD',
    expected_close_date: '',
    custom_fields: {} as Record<string, any>,
});

const createPipelineStages = computed(() => {
    const targetPipeline =
        props.pipelines.find((p) => p.id === createForm.value.pipeline_id) ?? activePipeline.value;
    return targetPipeline?.stages ?? [];
});

const createFilteredContacts = computed(() => {
    if (!createForm.value.company_id) {
        return props.contacts;
    }
    return props.contacts.filter(
        (c) => c.company_id === null || c.company_id === createForm.value.company_id,
    );
});

function openCreateModal(): void {
    const defaultPipelineId = activePipeline.value?.id ?? props.pipelines[0]?.id ?? '';
    const defaultStageId =
        props.pipelines.find((p) => p.id === defaultPipelineId)?.stages[0]?.id ?? '';

    createErrorBanner.value = '';
    createFieldErrors.value = {};
    createForm.value = {
        company_id: props.companies[0]?.id ?? '',
        title: '',
        pipeline_id: defaultPipelineId,
        stage_id: defaultStageId,
        primary_contact_id: '',
        account_manager_id: '',
        amount_dollars: '',
        currency: 'USD',
        expected_close_date: '',
        custom_fields: {},
    };
    createOpen.value = true;
}

function handleCreatePipelineChange(): void {
    const nextStages = createPipelineStages.value;
    createForm.value.stage_id = nextStages[0]?.id ?? '';
}

async function handleCreateSubmit(): Promise<void> {
    if (createSubmitting.value) {
        return;
    }

    createSubmitting.value = true;
    createErrorBanner.value = '';
    createFieldErrors.value = {};

    const amountRaw = createForm.value.amount_dollars.trim();
    const amountCents =
        amountRaw !== '' && Number.isFinite(Number(amountRaw))
            ? Math.round(Number(amountRaw) * 100)
            : 0;

    const cleanCustom: Record<string, any> = {};
    for (const [key, val] of Object.entries(createForm.value.custom_fields)) {
        if (val !== null && val !== undefined && val !== '') {
            cleanCustom[key] = val;
        }
    }

    try {
        const created = await crmApi.createDeal({
            company_id: createForm.value.company_id,
            title: createForm.value.title.trim(),
            pipeline_id: createForm.value.pipeline_id || null,
            stage_id: createForm.value.stage_id || null,
            primary_contact_id: createForm.value.primary_contact_id || null,
            account_manager_id: createForm.value.account_manager_id || null,
            amount_cents: amountCents,
            currency: createForm.value.currency.trim().toUpperCase() || 'USD',
            expected_close_date: createForm.value.expected_close_date || null,
            lost_reason: null,
            custom_fields: cleanCustom,
            stakeholders: null,
        });

        createOpen.value = false;
        router.visit(`/app/crm/deals/${created.id}`);
    } catch (error) {
        if (error instanceof StageGateValidationError || error instanceof ValidationRequestError) {
            createFieldErrors.value = extractFirstFieldErrors(error.errors);
            createErrorBanner.value = error.message;
        } else {
            createErrorBanner.value = error instanceof Error ? error.message : 'Unable to create deal.';
        }
    } finally {
        createSubmitting.value = false;
    }
}
</script>

<template>
    <Head title="Deals" />

    <div class="space-y-4">
        <!-- Header Bar: Pipeline Switcher, Segmented Kanban/Table Toggle, + New Deal -->
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap items-center gap-3 min-w-0">
                <h2 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                    Deals Pipeline
                </h2>

                <div class="w-48">
                    <label :for="pipelineSelectId" class="sr-only">Active pipeline</label>
                    <select
                        :id="pipelineSelectId"
                        v-model="selectedPipelineId"
                        data-testid="deals-pipeline-select"
                        class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[12px] font-medium text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                        @change="handlePipelineChange"
                    >
                        <option
                            v-for="pipeline in props.pipelines"
                            :key="pipeline.id"
                            :value="pipeline.id"
                        >
                            {{ pipeline.name }}
                        </option>
                    </select>
                </div>

                <!-- Segmented Kanban / Table Toggle -->
                <div
                    role="group"
                    aria-label="Deals view mode"
                    class="inline-flex h-8 items-center rounded-[6px] border border-default bg-subtle p-0.5"
                >
                    <button
                        type="button"
                        :aria-pressed="props.view === 'kanban' ? 'true' : 'false'"
                        data-testid="deals-view-kanban"
                        :class="[
                            'inline-flex h-7 items-center gap-1.5 rounded-[4px] px-2.5 text-[12px] font-medium transition-colors duration-150 cursor-pointer',
                            props.view === 'kanban'
                                ? 'bg-surface text-primary border border-default'
                                : 'text-secondary hover:text-primary',
                        ]"
                        @click="switchView('kanban')"
                    >
                        <Kanban class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <span>Kanban</span>
                    </button>
                    <button
                        type="button"
                        :aria-pressed="props.view === 'table' ? 'true' : 'false'"
                        data-testid="deals-view-table"
                        :class="[
                            'inline-flex h-7 items-center gap-1.5 rounded-[4px] px-2.5 text-[12px] font-medium transition-colors duration-150 cursor-pointer',
                            props.view === 'table'
                                ? 'bg-surface text-primary border border-default'
                                : 'text-secondary hover:text-primary',
                        ]"
                        @click="switchView('table')"
                    >
                        <LayoutList class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <span>Table</span>
                    </button>
                </div>
            </div>

            <Button
                v-if="props.can.create"
                variant="primary"
                size="md"
                data-testid="create-deal-cta"
                @click="openCreateModal"
            >
                <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                <span>New Deal</span>
            </Button>
        </div>

        <!-- Search & Filter Bar -->
        <form
            class="flex flex-wrap items-end gap-2 rounded-[8px] border border-default bg-surface p-3"
            @submit.prevent="applyFilters({ page: 1 })"
        >
            <div class="w-full sm:w-56">
                <label :for="searchInputId" class="sr-only">Search deals</label>
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted"
                        :stroke-width="1.75"
                        aria-hidden="true"
                    />
                    <Input
                        :id="searchInputId"
                        v-model="searchDraft"
                        placeholder="Search deal title..."
                        class="pl-8"
                    />
                </div>
            </div>

            <div class="w-[calc(50%-0.25rem)] sm:w-40">
                <label :for="ownerSelectId" class="sr-only">Deal owner</label>
                <select
                    :id="ownerSelectId"
                    v-model="ownerDraft"
                    class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[12px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                    @change="applyFilters({ page: 1 })"
                >
                    <option value="">All owners</option>
                    <option
                        v-for="user in props.assignableUsers"
                        :key="user.id"
                        :value="user.id"
                    >
                        {{ user.name }}
                    </option>
                </select>
            </div>

            <div v-if="props.companies.length > 0" class="w-[calc(50%-0.25rem)] sm:w-44">
                <label :for="companySelectId" class="sr-only">Company</label>
                <select
                    :id="companySelectId"
                    v-model="companyDraft"
                    class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[12px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                    @change="applyFilters({ page: 1 })"
                >
                    <option value="">All companies</option>
                    <option
                        v-for="company in props.companies"
                        :key="company.id"
                        :value="company.id"
                    >
                        {{ company.name }}
                    </option>
                </select>
            </div>

            <div v-if="props.view === 'table' && activePipeline" class="w-[calc(50%-0.25rem)] sm:w-40">
                <label :for="stageSelectId" class="sr-only">Stage</label>
                <select
                    :id="stageSelectId"
                    v-model="stageDraft"
                    class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[12px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                    @change="applyFilters({ page: 1 })"
                >
                    <option value="">All stages</option>
                    <option
                        v-for="stage in activePipeline.stages"
                        :key="stage.id"
                        :value="stage.id"
                    >
                        {{ stage.name }}
                    </option>
                </select>
            </div>

            <div
                v-for="filterDef in props.filterDefinitions"
                :key="filterDef.id"
                class="w-full sm:w-40"
            >
                <label :for="`deal-cf-filter-${filterDef.key}`" class="sr-only">
                    {{ filterDef.label }}
                </label>
                <select
                    v-if="filterDef.field_type === 'select' || filterDef.field_type === 'multi_select'"
                    :id="`deal-cf-filter-${filterDef.key}`"
                    v-model="customFilterDraft[filterDef.key]"
                    class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[12px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                    @change="applyFilters({ page: 1 })"
                >
                    <option value="">{{ filterDef.label }}: Any</option>
                    <option
                        v-for="opt in (filterDef.options ?? [])"
                        :key="typeof opt === 'string' ? opt : String(opt.value)"
                        :value="typeof opt === 'string' ? opt : String(opt.value)"
                    >
                        {{ typeof opt === 'string' ? opt : String(opt.label ?? opt.value) }}
                    </option>
                </select>
                <Input
                    v-else
                    :id="`deal-cf-filter-${filterDef.key}`"
                    v-model="customFilterDraft[filterDef.key]"
                    :placeholder="filterDef.label"
                />
            </div>

            <Button type="submit" variant="secondary" size="md">
                Apply
            </Button>

            <Button
                v-if="hasActiveFilters"
                variant="ghost"
                size="md"
                @click="resetFilters"
            >
                <RotateCcw class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                <span>Reset</span>
            </Button>
        </form>

        <!-- Kanban View -->
        <DealKanbanBoard
            v-if="props.view === 'kanban' && props.board"
            :board="props.board"
            :custom-field-definitions="props.customFieldDefinitions"
            :assignable-users="props.assignableUsers"
            :assignable-ams="props.assignableAms ?? props.assignableUsers"
            :contacts="props.contacts"
            :can-move-deals="canMoveDeals"
            :can-override-gates="canOverrideGates"
            :can-submit-handoff="canSubmitHandoff"
        />

        <!-- Table View -->
        <DataTable
            v-else-if="props.view === 'table' && props.tableDeals"
            :columns="tableColumns"
            :data="props.tableDeals.data"
            :loading="navigating"
            :manual-sorting="true"
            :sorting="tableSorting"
            :row-key="(row) => row.id"
            empty-title="No matching deals"
            empty-description="Adjust your pipeline or filter criteria to inspect open, won, or lost deals."
            test-id="deals-data-table"
            @update:sorting="handleSortingChange"
        >
            <template #footer>
                <div class="flex items-center justify-between gap-2 text-[12px]">
                    <span class="font-mono tabular-nums text-secondary">
                        {{ paginationSummary }}
                    </span>
                    <div class="flex items-center gap-1.5">
                        <Button
                            variant="secondary"
                            size="sm"
                            :disabled="props.tableDeals.current_page <= 1"
                            @click="applyFilters({ page: props.tableDeals.current_page - 1 })"
                        >
                            Previous
                        </Button>
                        <span class="px-2 font-mono text-[11px] tabular-nums text-secondary">
                            {{ props.tableDeals.current_page }} / {{ props.tableDeals.last_page }}
                        </span>
                        <Button
                            variant="secondary"
                            size="sm"
                            :disabled="props.tableDeals.current_page >= props.tableDeals.last_page"
                            @click="applyFilters({ page: props.tableDeals.current_page + 1 })"
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </template>
        </DataTable>

        <!-- Create Deal Modal -->
        <Dialog
            :open="createOpen"
            title="New Deal"
            description="Create a pipeline opportunity with company, primary contact, and deal value."
            max-width-class="max-w-lg"
            @update:open="createOpen = $event"
        >
            <form class="max-h-[75vh] overflow-y-auto space-y-3.5 pr-1" @submit.prevent="handleCreateSubmit">
                <div
                    v-if="createErrorBanner"
                    role="alert"
                    class="flex items-start gap-2 rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                >
                    <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                    <span>{{ createErrorBanner }}</span>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label :for="`create-deal-title-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Deal Title <span class="text-status-danger">*</span>
                        </label>
                        <Input
                            :id="`create-deal-title-${instanceId}`"
                            v-model="createForm.title"
                            placeholder="Northwind Regional WMS Rollout"
                            :disabled="createSubmitting"
                            :error-message="createFieldErrors.title ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`create-deal-company-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Company <span class="text-status-danger">*</span>
                        </label>
                        <select
                            :id="`create-deal-company-${instanceId}`"
                            v-model="createForm.company_id"
                            :disabled="createSubmitting"
                            :aria-invalid="createFieldErrors.company_id ? 'true' : undefined"
                            :class="[
                                'h-8 w-full rounded-[6px] border bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary',
                                createFieldErrors.company_id ? 'border-status-danger' : 'border-default',
                            ]"
                        >
                            <option value="">Select company...</option>
                            <option
                                v-for="company in props.companies"
                                :key="company.id"
                                :value="company.id"
                            >
                                {{ company.name }}
                            </option>
                        </select>
                        <p v-if="createFieldErrors.company_id" role="alert" class="mt-1 text-[12px] text-status-danger">
                            {{ createFieldErrors.company_id }}
                        </p>
                    </div>

                    <div>
                        <label :for="`create-deal-contact-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Primary Contact
                        </label>
                        <select
                            :id="`create-deal-contact-${instanceId}`"
                            v-model="createForm.primary_contact_id"
                            :disabled="createSubmitting"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                        >
                            <option value="">None selected</option>
                            <option
                                v-for="contact in createFilteredContacts"
                                :key="contact.id"
                                :value="contact.id"
                            >
                                {{ contact.full_name }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label :for="`create-deal-pipeline-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Pipeline
                        </label>
                        <select
                            :id="`create-deal-pipeline-${instanceId}`"
                            v-model="createForm.pipeline_id"
                            :disabled="createSubmitting"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                            @change="handleCreatePipelineChange"
                        >
                            <option
                                v-for="pipeline in props.pipelines"
                                :key="pipeline.id"
                                :value="pipeline.id"
                            >
                                {{ pipeline.name }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label :for="`create-deal-stage-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Initial Stage
                        </label>
                        <select
                            :id="`create-deal-stage-${instanceId}`"
                            v-model="createForm.stage_id"
                            :disabled="createSubmitting"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                        >
                            <option
                                v-for="stage in createPipelineStages"
                                :key="stage.id"
                                :value="stage.id"
                            >
                                {{ stage.name }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label :for="`create-deal-amount-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Deal Amount (USD)
                        </label>
                        <Input
                            :id="`create-deal-amount-${instanceId}`"
                            v-model="createForm.amount_dollars"
                            type="number"
                            placeholder="125000"
                            class="font-mono tabular-nums"
                            :disabled="createSubmitting"
                            :error-message="createFieldErrors.amount_cents ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`create-deal-close-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Expected Close Date
                        </label>
                        <Input
                            :id="`create-deal-close-${instanceId}`"
                            v-model="createForm.expected_close_date"
                            type="date"
                            class="font-mono tabular-nums"
                            :disabled="createSubmitting"
                            :error-message="createFieldErrors.expected_close_date ?? ''"
                        />
                    </div>

                    <div class="sm:col-span-2">
                        <label :for="`create-deal-am-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Account Manager
                        </label>
                        <select
                            :id="`create-deal-am-${instanceId}`"
                            v-model="createForm.account_manager_id"
                            :disabled="createSubmitting"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                        >
                            <option value="">Unassigned</option>
                            <option
                                v-for="user in props.assignableUsers"
                                :key="user.id"
                                :value="user.id"
                            >
                                {{ user.name }}
                            </option>
                        </select>
                    </div>
                </div>

                <CustomFieldsForm
                    v-if="props.customFieldDefinitions.length > 0"
                    v-model="createForm.custom_fields"
                    :definitions="props.customFieldDefinitions"
                    :assignable-users="props.assignableUsers"
                    :errors="createFieldErrors"
                    :disabled="createSubmitting"
                    id-prefix="create-deal-cf"
                />

                <div class="flex items-center justify-end gap-2 border-t border-default pt-3">
                    <Button variant="secondary" :disabled="createSubmitting" @click="createOpen = false">
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" :loading="createSubmitting">
                        Create Deal
                    </Button>
                </div>
            </form>
        </Dialog>
    </div>
</template>
