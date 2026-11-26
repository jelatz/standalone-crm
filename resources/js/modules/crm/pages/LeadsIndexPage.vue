<script setup lang="ts">
import { computed, h, ref, useId, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { type SortingState } from '@tanstack/vue-table';
import { AlertCircle, Crosshair, Plus, RotateCcw, Search as SearchIcon, Upload } from 'lucide-vue-next';
import Badge, { type BadgeVariant } from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Input from '@/components/ui/Input.vue';
import DataTable from '@/components/data-table/DataTable.vue';
import { createDataTableColumnHelper } from '@/components/data-table/tableFeatures';
import CustomFieldsForm from '@/modules/crm/components/CustomFieldsForm.vue';
import ImportLeadsDialog from '@/modules/crm/components/ImportLeadsDialog.vue';
import { crmApi, extractFirstFieldErrors, ValidationRequestError } from '@/modules/crm/api/crmApi';
import { formatEnumLabel, formatMoneyCents, formatShortDate } from '@/modules/crm/utils/formatters';

type LeadRow = App.Modules.Crm.Leads.Data.LeadListItemData;

const props = defineProps<{
    leads: {
        data: LeadRow[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    statusCounts: Record<string, number>;
    filters: App.Modules.Crm.Leads.Data.LeadFilterData;
    filterDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    customFieldDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    assignableUsers: App.Modules.Core.Users.Data.UserSummaryData[];
    statuses: string[];
    sources: string[];
    can: {
        create: boolean;
        convert: boolean;
    };
}>();

const instanceId = useId();
const searchInputId = `leads-search-${instanceId}`;
const sourceSelectId = `leads-source-${instanceId}`;
const ownerSelectId = `leads-owner-${instanceId}`;

const searchDraft = ref<string>(props.filters.search ?? '');
const statusDraft = ref<string>(props.filters.status ?? '');
const sourceDraft = ref<string>(props.filters.source ?? '');
const ownerDraft = ref<string>(props.filters.owner_id ?? '');
const customFilterDraft = ref<Record<string, string>>({
    ...Object.fromEntries(
        Object.entries(props.filters.custom_fields ?? {}).map(([k, v]) => [k, v == null ? '' : String(v)]),
    ),
});
const navigating = ref<boolean>(false);

watch(
    () => props.filters,
    (next) => {
        searchDraft.value = next.search ?? '';
        statusDraft.value = next.status ?? '';
        sourceDraft.value = next.source ?? '';
        ownerDraft.value = next.owner_id ?? '';
        customFilterDraft.value = Object.fromEntries(
            Object.entries(next.custom_fields ?? {}).map(([k, v]) => [k, v == null ? '' : String(v)]),
        );
    },
);

const statusTabs = [
    { key: 'all', value: '', label: 'All' },
    { key: 'new', value: 'new', label: 'New' },
    { key: 'contacted', value: 'contacted', label: 'Contacted' },
    { key: 'working', value: 'working', label: 'Working' },
    { key: 'qualified', value: 'qualified', label: 'Qualified' },
    { key: 'unqualified', value: 'unqualified', label: 'Unqualified' },
    { key: 'converted', value: 'converted', label: 'Converted' },
] as const;

const creatableStatuses = computed(() => props.statuses.filter((status) => status !== 'converted'));

const serverSortFields = new Set([
    'last_name',
    'company_name',
    'status',
    'source',
    'estimated_value_cents',
    'updated_at',
    'created_at',
]);

const tableSorting = computed<SortingState>(() => {
    if (!props.filters.sort_by || !serverSortFields.has(props.filters.sort_by)) {
        return [];
    }
    return [{ id: props.filters.sort_by, desc: props.filters.sort_dir !== 'asc' }];
});

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

function buildFilterParams(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    const cleanCustom: Record<string, string> = {};
    for (const [key, val] of Object.entries(customFilterDraft.value)) {
        if (val !== '' && val !== undefined && val !== null) {
            cleanCustom[key] = val;
        }
    }

    const base: Record<string, unknown> = {
        search: searchDraft.value.trim() || undefined,
        status: statusDraft.value || undefined,
        source: sourceDraft.value || undefined,
        owner_id: ownerDraft.value || undefined,
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
    router.get('/app/crm/leads', buildFilterParams(overrides) as Record<string, any>, {
        preserveState: true,
        replace: true,
        onFinish: () => {
            navigating.value = false;
        },
    });
}

function selectStatusTab(statusValue: string): void {
    statusDraft.value = statusValue;
    applyFilters({ status: statusValue || undefined, page: 1 });
}

function resetFilters(): void {
    searchDraft.value = '';
    statusDraft.value = '';
    sourceDraft.value = '';
    ownerDraft.value = '';
    customFilterDraft.value = {};
    applyFilters({
        search: undefined,
        status: undefined,
        source: undefined,
        owner_id: undefined,
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
        Boolean(statusDraft.value) ||
        Boolean(sourceDraft.value) ||
        Boolean(ownerDraft.value) ||
        Object.values(customFilterDraft.value).some((v) => v !== ''),
);

const columnHelper = createDataTableColumnHelper<LeadRow>();

const columns = [
    columnHelper.accessor('last_name', {
        id: 'last_name',
        header: 'Lead',
        cell: (info) => {
            const row = info.row.original;
            return h('div', { class: 'min-w-0 max-w-[220px]' }, [
                h(
                    Link,
                    {
                        href: `/app/crm/leads/${row.id}`,
                        class: 'block truncate font-semibold text-primary hover:text-accent-primary',
                    },
                    () => row.full_name,
                ),
                row.job_title
                    ? h('span', { class: 'block truncate text-[11px] text-muted' }, row.job_title)
                    : null,
            ]);
        },
    }),
    columnHelper.accessor('company_name', {
        id: 'company_name',
        header: 'Prospect Company',
        cell: (info) => {
            const row = info.row.original;
            return h('div', { class: 'min-w-0 max-w-[190px]' }, [
                h('span', { class: 'block truncate text-[12px] text-primary' }, row.company_name || '—'),
                row.website
                    ? h('span', { class: 'block truncate font-mono text-[11px] text-muted' }, row.website)
                    : null,
            ]);
        },
    }),
    columnHelper.accessor('email', {
        id: 'email',
        header: 'Email / Phone',
        enableSorting: false,
        cell: (info) => {
            const row = info.row.original;
            if (!row.email && !row.phone) {
                return h('span', { class: 'text-[12px] text-muted' }, '—');
            }
            return h('div', { class: 'min-w-0 max-w-[220px]' }, [
                row.email
                    ? h('span', { class: 'block truncate font-mono text-[12px] text-primary' }, row.email)
                    : null,
                row.phone
                    ? h('span', { class: 'block truncate font-mono text-[11px] text-muted' }, row.phone)
                    : null,
            ]);
        },
    }),
    columnHelper.accessor('source', {
        id: 'source',
        header: 'Source',
        cell: (info) =>
            h(
                Badge,
                { variant: 'neutral' },
                () => formatEnumLabel(info.getValue()),
            ),
    }),
    columnHelper.accessor('status', {
        id: 'status',
        header: 'Status',
        cell: (info) =>
            h(
                Badge,
                { variant: leadStatusVariant(info.getValue()) },
                () => formatEnumLabel(info.getValue()),
            ),
    }),
    columnHelper.accessor('estimated_value_cents', {
        id: 'estimated_value_cents',
        header: 'Est. Value',
        meta: { align: 'right', numeric: true },
        cell: (info) => {
            const row = info.row.original;
            return formatMoneyCents(info.getValue(), row.currency);
        },
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

// Create Lead Modal
const createOpen = ref<boolean>(false);
const createSubmitting = ref<boolean>(false);
const createErrorBanner = ref<string>('');
const createFieldErrors = ref<Record<string, string>>({});

const createForm = ref({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    job_title: '',
    company_name: '',
    website: '',
    source: 'inbound_web' as App.Modules.Crm.Leads.Enums.LeadSourceEnum,
    status: 'new' as App.Modules.Crm.Leads.Enums.LeadStatusEnum,
    estimated_value_dollars: '',
    currency: 'USD',
    custom_fields: {} as Record<string, any>,
});

function openCreateModal(): void {
    createErrorBanner.value = '';
    createFieldErrors.value = {};
    createForm.value = {
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        job_title: '',
        company_name: '',
        website: '',
        source: (props.sources[0] as App.Modules.Crm.Leads.Enums.LeadSourceEnum) || 'inbound_web',
        status: (creatableStatuses.value[0] as App.Modules.Crm.Leads.Enums.LeadStatusEnum) || 'new',
        estimated_value_dollars: '',
        currency: 'USD',
        custom_fields: {},
    };
    createOpen.value = true;
}

async function handleCreateSubmit(): Promise<void> {
    if (createSubmitting.value) {
        return;
    }

    createSubmitting.value = true;
    createErrorBanner.value = '';
    createFieldErrors.value = {};

    const cleanCustom: Record<string, any> = {};
    for (const [key, val] of Object.entries(createForm.value.custom_fields)) {
        if (val !== null && val !== undefined && val !== '') {
            cleanCustom[key] = val;
        }
    }

    const valueDollars = createForm.value.estimated_value_dollars.trim();
    const estimatedValueCents =
        valueDollars !== '' && Number.isFinite(Number(valueDollars))
            ? Math.max(0, Math.round(Number(valueDollars) * 100))
            : null;

    const normalizedCurrency = createForm.value.currency.trim().toUpperCase() || 'USD';

    try {
        const created = await crmApi.createLead({
            first_name: createForm.value.first_name.trim(),
            last_name: createForm.value.last_name.trim(),
            email: createForm.value.email.trim() || null,
            phone: createForm.value.phone.trim() || null,
            job_title: createForm.value.job_title.trim() || null,
            company_name: createForm.value.company_name.trim() || null,
            website: createForm.value.website.trim() || null,
            source: createForm.value.source,
            status: createForm.value.status,
            estimated_value_cents: estimatedValueCents,
            currency: normalizedCurrency,
            disqualified_reason: undefined,
            custom_fields: cleanCustom,
        });

        createOpen.value = false;
        router.visit(`/app/crm/leads/${created.id}`);
    } catch (error) {
        if (error instanceof ValidationRequestError) {
            createFieldErrors.value = extractFirstFieldErrors(error.errors);
            createErrorBanner.value = error.message;
        } else {
            createErrorBanner.value = error instanceof Error ? error.message : 'Unable to create lead.';
        }
    } finally {
        createSubmitting.value = false;
    }
}

const importOpen = ref<boolean>(false);

function openImportModal(): void {
    importOpen.value = true;
}

function handleLeadsImported(): void {
    router.reload({ only: ['leads', 'statusCounts'] });
}

const paginationSummary = computed(() => {
    const { current_page, per_page, total } = props.leads;
    if (total === 0) {
        return '0 leads';
    }
    const start = (current_page - 1) * per_page + 1;
    const end = Math.min(current_page * per_page, total);
    return `${start}–${end} of ${total}`;
});
</script>

<template>
    <Head title="Leads" />

    <div class="space-y-4">
        <!-- Header Bar -->
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <Crosshair class="h-4 w-4 shrink-0 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                    <h2 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                        Leads
                    </h2>
                    <Badge variant="neutral">{{ props.leads.total }}</Badge>
                </div>
                <p class="mt-0.5 text-[12px] text-secondary">
                    Unqualified prospect intake, qualification status tracking, and account conversion.
                </p>
            </div>

            <div v-if="props.can.create" class="flex flex-wrap items-center gap-2">
                <Button
                    variant="secondary"
                    size="md"
                    data-testid="import-leads-cta"
                    @click="openImportModal"
                >
                    <Upload class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>Import Leads</span>
                </Button>

                <Button
                    variant="primary"
                    size="md"
                    data-testid="create-lead-cta"
                    @click="openCreateModal"
                >
                    <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>New Lead</span>
                </Button>
            </div>
        </div>

        <!-- Status Filter Tabs -->
        <div
            role="tablist"
            aria-label="Filter leads by status"
            class="flex items-center gap-1 overflow-x-auto border-b border-default"
        >
            <button
                v-for="tab in statusTabs"
                :key="tab.key"
                type="button"
                role="tab"
                :aria-selected="statusDraft === tab.value ? 'true' : 'false'"
                :data-testid="`lead-status-tab-${tab.key}`"
                :class="[
                    'inline-flex h-8 shrink-0 items-center gap-1.5 border-b-2 px-3 text-[12px] transition-colors duration-150 cursor-pointer',
                    statusDraft === tab.value
                        ? 'border-accent-primary font-semibold text-primary'
                        : 'border-transparent font-medium text-secondary hover:text-primary',
                ]"
                @click="selectStatusTab(tab.value)"
            >
                <span>{{ tab.label }}</span>
                <Badge :variant="statusDraft === tab.value ? 'cobalt' : 'neutral'">
                    {{ props.statusCounts[tab.key] ?? 0 }}
                </Badge>
            </button>
        </div>

        <!-- Leads DataTable with Filter Toolbar -->
        <DataTable
            :columns="columns"
            :data="props.leads.data"
            :loading="navigating"
            :manual-sorting="true"
            :sorting="tableSorting"
            :row-key="(row) => row.id"
            empty-title="No matching leads"
            empty-description="No visible leads match your current status, source, or custom field filters."
            test-id="leads-data-table"
            @update:sorting="handleSortingChange"
        >
            <template #toolbar>
                <form
                    role="search"
                    aria-label="Filter leads"
                    class="flex flex-wrap items-end gap-2"
                    @submit.prevent="applyFilters({ page: 1 })"
                >
                        <div class="w-full sm:w-56">
                            <label :for="searchInputId" class="sr-only">Search leads</label>
                            <div class="relative">
                                <SearchIcon
                                    class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted"
                                    :stroke-width="1.75"
                                    aria-hidden="true"
                                />
                                <Input
                                    :id="searchInputId"
                                    v-model="searchDraft"
                                    placeholder="Search lead, email, company..."
                                    class="pl-8"
                                />
                            </div>
                        </div>

                        <div class="w-[calc(50%-0.25rem)] sm:w-40">
                            <label :for="sourceSelectId" class="sr-only">Source</label>
                            <select
                                :id="sourceSelectId"
                                v-model="sourceDraft"
                                class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[12px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                                @change="applyFilters({ page: 1 })"
                            >
                                <option value="">All sources</option>
                                <option
                                    v-for="source in props.sources"
                                    :key="source"
                                    :value="source"
                                >
                                    {{ formatEnumLabel(source) }}
                                </option>
                            </select>
                        </div>

                        <div class="w-[calc(50%-0.25rem)] sm:w-44">
                            <label :for="ownerSelectId" class="sr-only">Owner</label>
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

                        <div
                            v-for="filterDef in props.filterDefinitions"
                            :key="filterDef.id"
                            class="w-full sm:w-40"
                        >
                            <label :for="`cf-filter-${filterDef.key}`" class="sr-only">
                                {{ filterDef.label }}
                            </label>
                            <select
                                v-if="filterDef.field_type === 'select' || filterDef.field_type === 'multi_select'"
                                :id="`cf-filter-${filterDef.key}`"
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
                            <select
                                v-else-if="filterDef.field_type === 'boolean'"
                                :id="`cf-filter-${filterDef.key}`"
                                v-model="customFilterDraft[filterDef.key]"
                                class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[12px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                                @change="applyFilters({ page: 1 })"
                            >
                                <option value="">{{ filterDef.label }}: Any</option>
                                <option value="true">{{ filterDef.label }}: Yes</option>
                                <option value="false">{{ filterDef.label }}: No</option>
                            </select>
                            <Input
                                v-else
                                :id="`cf-filter-${filterDef.key}`"
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
            </template>

            <template #footer>
                <div class="flex items-center justify-between gap-2 text-[12px]">
                    <span class="font-mono tabular-nums text-secondary">
                        {{ paginationSummary }}
                    </span>
                    <div class="flex items-center gap-1.5">
                        <Button
                            variant="secondary"
                            size="sm"
                            :disabled="props.leads.current_page <= 1"
                            @click="applyFilters({ page: props.leads.current_page - 1 })"
                        >
                            Previous
                        </Button>
                        <span class="px-2 font-mono text-[11px] tabular-nums text-secondary">
                            {{ props.leads.current_page }} / {{ props.leads.last_page }}
                        </span>
                        <Button
                            variant="secondary"
                            size="sm"
                            :disabled="props.leads.current_page >= props.leads.last_page"
                            @click="applyFilters({ page: props.leads.current_page + 1 })"
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </template>
        </DataTable>

        <!-- Create Lead Modal -->
        <Dialog
            :open="createOpen"
            title="New Lead"
            description="Capture a prospect lead for qualification and conversion."
            max-width-class="max-w-lg"
            @update:open="createOpen = $event"
        >
            <form class="space-y-3.5" @submit.prevent="handleCreateSubmit">
                <div class="max-h-[70vh] space-y-3.5 overflow-y-auto pr-0.5">
                    <div
                        v-if="createErrorBanner"
                        role="alert"
                        class="flex items-start gap-2 rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                    >
                        <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                        <span>{{ createErrorBanner }}</span>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label :for="`create-lead-first-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                First Name <span class="text-status-danger">*</span>
                            </label>
                            <Input
                                :id="`create-lead-first-${instanceId}`"
                                v-model="createForm.first_name"
                                placeholder="David"
                                required
                                :disabled="createSubmitting"
                                :error-message="createFieldErrors.first_name ?? ''"
                            />
                        </div>

                        <div>
                            <label :for="`create-lead-last-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Last Name <span class="text-status-danger">*</span>
                            </label>
                            <Input
                                :id="`create-lead-last-${instanceId}`"
                                v-model="createForm.last_name"
                                placeholder="Keller"
                                required
                                :disabled="createSubmitting"
                                :error-message="createFieldErrors.last_name ?? ''"
                            />
                        </div>

                        <div>
                            <label :for="`create-lead-email-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Email
                            </label>
                            <Input
                                :id="`create-lead-email-${instanceId}`"
                                v-model="createForm.email"
                                type="email"
                                autocomplete="email"
                                :spellcheck="false"
                                placeholder="d.keller@northwind.internal"
                                :disabled="createSubmitting"
                                :error-message="createFieldErrors.email ?? ''"
                            />
                        </div>

                        <div>
                            <label :for="`create-lead-phone-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Phone
                            </label>
                            <Input
                                :id="`create-lead-phone-${instanceId}`"
                                v-model="createForm.phone"
                                type="tel"
                                autocomplete="tel"
                                placeholder="+1 555-019-2831"
                                :disabled="createSubmitting"
                                :error-message="createFieldErrors.phone ?? ''"
                            />
                        </div>

                        <div>
                            <label :for="`create-lead-title-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Job Title
                            </label>
                            <Input
                                :id="`create-lead-title-${instanceId}`"
                                v-model="createForm.job_title"
                                placeholder="VP Procurement"
                                :disabled="createSubmitting"
                                :error-message="createFieldErrors.job_title ?? ''"
                            />
                        </div>

                        <div>
                            <label :for="`create-lead-company-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Prospect Company
                            </label>
                            <Input
                                :id="`create-lead-company-${instanceId}`"
                                v-model="createForm.company_name"
                                placeholder="Northwind Logistics"
                                :disabled="createSubmitting"
                                :error-message="createFieldErrors.company_name ?? ''"
                            />
                        </div>

                        <div class="sm:col-span-2">
                            <label :for="`create-lead-website-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Website / Domain
                            </label>
                            <Input
                                :id="`create-lead-website-${instanceId}`"
                                v-model="createForm.website"
                                :spellcheck="false"
                                placeholder="northwind.internal"
                                :disabled="createSubmitting"
                                :error-message="createFieldErrors.website ?? ''"
                            />
                        </div>

                        <div>
                            <label :for="`create-lead-source-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Source
                            </label>
                            <select
                                :id="`create-lead-source-${instanceId}`"
                                v-model="createForm.source"
                                :disabled="createSubmitting"
                                :aria-invalid="createFieldErrors.source ? 'true' : undefined"
                                class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                            >
                                <option
                                    v-for="source in props.sources"
                                    :key="source"
                                    :value="source"
                                >
                                    {{ formatEnumLabel(source) }}
                                </option>
                            </select>
                            <p v-if="createFieldErrors.source" role="alert" class="mt-1 text-[12px] text-status-danger">
                                {{ createFieldErrors.source }}
                            </p>
                        </div>

                        <div>
                            <label :for="`create-lead-status-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Initial Status
                            </label>
                            <select
                                :id="`create-lead-status-${instanceId}`"
                                v-model="createForm.status"
                                :disabled="createSubmitting"
                                :aria-invalid="createFieldErrors.status ? 'true' : undefined"
                                class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                            >
                                <option
                                    v-for="status in creatableStatuses"
                                    :key="status"
                                    :value="status"
                                >
                                    {{ formatEnumLabel(status) }}
                                </option>
                            </select>
                            <p v-if="createFieldErrors.status" role="alert" class="mt-1 text-[12px] text-status-danger">
                                {{ createFieldErrors.status }}
                            </p>
                        </div>

                        <div>
                            <label :for="`create-lead-value-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Estimated Value
                            </label>
                            <Input
                                :id="`create-lead-value-${instanceId}`"
                                v-model="createForm.estimated_value_dollars"
                                type="number"
                                min="0"
                                step="0.01"
                                placeholder="125000"
                                class="font-mono tabular-nums"
                                :disabled="createSubmitting"
                                :error-message="createFieldErrors.estimated_value_cents ?? ''"
                            />
                        </div>

                        <div>
                            <label :for="`create-lead-currency-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Currency
                            </label>
                            <Input
                                :id="`create-lead-currency-${instanceId}`"
                                v-model="createForm.currency"
                                maxlength="3"
                                class="font-mono uppercase"
                                placeholder="USD"
                                :disabled="createSubmitting"
                                :error-message="createFieldErrors.currency ?? ''"
                            />
                        </div>
                    </div>

                    <CustomFieldsForm
                        v-if="props.customFieldDefinitions.length > 0"
                        v-model="createForm.custom_fields"
                        :definitions="props.customFieldDefinitions"
                        :assignable-users="props.assignableUsers"
                        :errors="createFieldErrors"
                        :disabled="createSubmitting"
                        id-prefix="create-lead-cf"
                    />
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-default pt-3">
                    <Button variant="secondary" :disabled="createSubmitting" @click="createOpen = false">
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" :loading="createSubmitting">
                        Create Lead
                    </Button>
                </div>
            </form>
        </Dialog>

        <!-- Import Leads Modal -->
        <ImportLeadsDialog
            :open="importOpen"
            @update:open="importOpen = $event"
            @imported="handleLeadsImported"
        />
    </div>
</template>
