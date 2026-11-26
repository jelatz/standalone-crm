<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue';
import {
    AlertCircle,
    CheckCircle2,
    ChevronDown,
    Download,
    FileSpreadsheet,
    Loader2,
    Upload,
} from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import {
    crmApi,
    extractFirstFieldErrors,
    leadImportTemplateUrl,
    ValidationRequestError,
} from '@/modules/crm/api/crmApi';
import { formatEnumLabel, formatFileSize, formatMoneyCents } from '@/modules/crm/utils/formatters';

type ImportStep = 'upload' | 'review' | 'done';

const MAX_FILE_BYTES = 5 * 1024 * 1024;

const DEFAULT_MAPPABLE_FIELDS = [
    'first_name',
    'last_name',
    'full_name',
    'email',
    'phone',
    'job_title',
    'company_name',
    'website',
    'source',
    'estimated_value',
    'estimated_value_cents',
    'currency',
] as const;

const FIELD_LABELS: Record<string, string> = {
    first_name: 'First Name',
    last_name: 'Last Name',
    full_name: 'Full Name',
    email: 'Email',
    phone: 'Phone',
    job_title: 'Job Title',
    company_name: 'Company Name',
    website: 'Website',
    source: 'Source',
    estimated_value: 'Est. Value',
    estimated_value_cents: 'Est. Value (Cents)',
    currency: 'Currency',
};

const props = defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    imported: [count: number];
}>();

const instanceId = useId();
const fileInputId = `import-leads-file-${instanceId}`;
const fileHelpId = `import-leads-help-${instanceId}`;
const mappingPanelId = `import-leads-mapping-${instanceId}`;

const fileInputRef = ref<HTMLInputElement | null>(null);
const reviewFocusRef = ref<HTMLElement | null>(null);
const doneFocusRef = ref<HTMLElement | null>(null);

const step = ref<ImportStep>('upload');
const selectedFile = ref<File | null>(null);
const preview = ref<App.Modules.Crm.Leads.Data.ImportLeadsPreviewData | null>(null);
const columnMapping = ref<Record<string, string | null>>({});
const selectedRowIndices = ref<Set<number>>(new Set());
const mappingExpanded = ref<boolean>(true);
const dragActive = ref<boolean>(false);
const dragDepth = ref<number>(0);

const previewing = ref<boolean>(false);
const committing = ref<boolean>(false);
const errorBanner = ref<string>('');
const importResult = ref<App.Modules.Crm.Leads.Data.ImportLeadsResultData | null>(null);

let previewRequestSeq = 0;
let activePreviewController: AbortController | null = null;

function abortActivePreview(): void {
    if (activePreviewController) {
        activePreviewController.abort();
        activePreviewController = null;
    }
}

function resetState(): void {
    abortActivePreview();
    previewRequestSeq += 1;
    step.value = 'upload';
    selectedFile.value = null;
    preview.value = null;
    columnMapping.value = {};
    selectedRowIndices.value = new Set();
    mappingExpanded.value = true;
    dragActive.value = false;
    dragDepth.value = 0;
    previewing.value = false;
    committing.value = false;
    errorBanner.value = '';
    importResult.value = null;
    if (fileInputRef.value) {
        fileInputRef.value.value = '';
    }
}

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            resetState();
        } else {
            abortActivePreview();
            previewRequestSeq += 1;
        }
    },
);

const mappableFields = computed<string[]>(() => {
    const fields = preview.value?.mappable_fields;
    return fields && fields.length > 0 ? fields : [...DEFAULT_MAPPABLE_FIELDS];
});

const mappedFieldCount = computed<number>(() =>
    mappableFields.value.filter((field) => Boolean(columnMapping.value[field])).length,
);

const readyRows = computed<App.Modules.Crm.Leads.Data.ImportLeadRowResultData[]>(() =>
    (preview.value?.rows ?? []).filter((row) => row.outcome === 'ready' && row.payload !== null),
);

const selectedPayloads = computed<App.Modules.Crm.Leads.Data.ImportLeadRowPayloadData[]>(() =>
    readyRows.value
        .filter((row) => selectedRowIndices.value.has(row.row_index))
        .map((row) => row.payload!),
);

const allReadySelected = computed<boolean>(
    () =>
        readyRows.value.length > 0 &&
        readyRows.value.every((row) => selectedRowIndices.value.has(row.row_index)),
);

const someReadySelected = computed<boolean>(() =>
    readyRows.value.some((row) => selectedRowIndices.value.has(row.row_index)),
);

function fieldLabel(field: string): string {
    return FIELD_LABELS[field] ?? formatEnumLabel(field);
}

function mappingSelectId(field: string): string {
    return `import-leads-map-${field}-${instanceId}`;
}

function formatApiError(error: unknown, fallback: string): string {
    if (error instanceof ValidationRequestError) {
        const fieldErrors = extractFirstFieldErrors(error.errors);
        return (
            fieldErrors.file ??
            fieldErrors.column_mapping ??
            fieldErrors.rows ??
            Object.values(fieldErrors)[0] ??
            error.message
        );
    }

    if (error instanceof Error && error.message.trim() !== '') {
        return error.message;
    }

    return fallback;
}

function syncReadySelection(rows: App.Modules.Crm.Leads.Data.ImportLeadRowResultData[]): void {
    selectedRowIndices.value = new Set(
        rows
            .filter((row) => row.outcome === 'ready' && row.payload !== null)
            .map((row) => row.row_index),
    );
}

async function focusElement(target: HTMLElement | null): Promise<void> {
    await nextTick();
    if (props.open && target && typeof target.focus === 'function') {
        target.focus();
    }
}

async function runPreview(
    file: File,
    mappingOverride?: Record<string, string | null>,
): Promise<void> {
    if (file.size > MAX_FILE_BYTES) {
        errorBanner.value = `File size (${formatFileSize(file.size)}) exceeds the 5 MB limit.`;
        return;
    }

    abortActivePreview();
    const controller = new AbortController();
    activePreviewController = controller;

    const requestSeq = ++previewRequestSeq;
    const enteringReview = step.value !== 'review';

    previewing.value = true;
    errorBanner.value = '';

    try {
        const result = await crmApi.previewLeadImport(file, mappingOverride, controller.signal);
        if (requestSeq !== previewRequestSeq || !props.open) {
            return;
        }

        selectedFile.value = file;
        preview.value = result;
        columnMapping.value = { ...result.detected_mapping };
        syncReadySelection(result.rows);
        step.value = 'review';

        if (enteringReview) {
            await focusElement(reviewFocusRef.value);
        }
    } catch (error) {
        if (requestSeq !== previewRequestSeq || !props.open) {
            return;
        }

        // Keep columnMapping aligned with the active preview if a remap failed
        if (mappingOverride && preview.value) {
            columnMapping.value = { ...preview.value.detected_mapping };
        }

        errorBanner.value = formatApiError(
            error,
            'Unable to parse spreadsheet. Verify the headers and try again.',
        );
    } finally {
        if (activePreviewController === controller) {
            activePreviewController = null;
        }
        if (requestSeq === previewRequestSeq) {
            previewing.value = false;
        }
    }
}

function handleFileSelection(file: File | null | undefined): void {
    if (!file || previewing.value || committing.value) {
        return;
    }

    void runPreview(file);
}

function handleFileInputChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    handleFileSelection(file);
    input.value = '';
}

function handleDragEnter(): void {
    if (previewing.value || committing.value) {
        return;
    }
    dragDepth.value += 1;
    dragActive.value = true;
}

function handleDragLeave(): void {
    dragDepth.value = Math.max(0, dragDepth.value - 1);
    if (dragDepth.value === 0) {
        dragActive.value = false;
    }
}

function handleDrop(event: DragEvent): void {
    dragDepth.value = 0;
    dragActive.value = false;
    if (previewing.value || committing.value) {
        return;
    }

    const file = event.dataTransfer?.files?.[0];
    handleFileSelection(file);
}

function handleChangeFile(): void {
    if (previewing.value || committing.value) {
        return;
    }
    abortActivePreview();
    previewRequestSeq += 1;
    step.value = 'upload';
    selectedFile.value = null;
    preview.value = null;
    columnMapping.value = {};
    selectedRowIndices.value = new Set();
    errorBanner.value = '';
    dragActive.value = false;
    dragDepth.value = 0;
}

function handleMappingChange(field: string, event: Event): void {
    if (!selectedFile.value || committing.value || previewing.value) {
        return;
    }

    const target = event.target as HTMLSelectElement;
    const nextMapping: Record<string, string | null> = {
        ...columnMapping.value,
        [field]: target.value === '' ? null : target.value,
    };

    void runPreview(selectedFile.value, nextMapping);
}

function toggleSelectAllReady(event: Event): void {
    const checked = (event.target as HTMLInputElement).checked;
    if (checked) {
        selectedRowIndices.value = new Set(readyRows.value.map((row) => row.row_index));
    } else {
        selectedRowIndices.value = new Set();
    }
}

function toggleRowSelection(rowIndex: number): void {
    const next = new Set(selectedRowIndices.value);
    if (next.has(rowIndex)) {
        next.delete(rowIndex);
    } else {
        next.add(rowIndex);
    }
    selectedRowIndices.value = next;
}

async function handleCommitImport(): Promise<void> {
    if (committing.value || previewing.value || selectedPayloads.value.length === 0) {
        return;
    }

    committing.value = true;
    errorBanner.value = '';

    try {
        const result = await crmApi.commitLeadImport(selectedPayloads.value);
        if (!props.open) {
            return;
        }
        importResult.value = result;
        step.value = 'done';
        if (result.inserted_count > 0) {
            emit('imported', result.inserted_count);
        }
        await focusElement(doneFocusRef.value);
    } catch (error) {
        if (!props.open) {
            return;
        }
        errorBanner.value = formatApiError(
            error,
            'Unable to import selected leads. Review any validation issues and retry.',
        );
    } finally {
        committing.value = false;
    }
}

function handleOpenChange(nextOpen: boolean): void {
    if (!nextOpen && (previewing.value || committing.value)) {
        return;
    }
    emit('update:open', nextOpen);
}
</script>

<template>
    <Dialog
        :open="props.open"
        title="Import Leads from Spreadsheet"
        description="Prospects enter Leads only with status New. Never creates Deals automatically."
        max-width-class="max-w-4xl"
        @update:open="handleOpenChange"
    >
        <!-- Error Banner -->
        <div
            v-if="errorBanner"
            role="alert"
            data-testid="import-leads-error"
            class="mb-3.5 flex items-start gap-2 rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
        >
            <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
            <span class="min-w-0 break-words">{{ errorBanner }}</span>
        </div>

        <!-- Step 1: Upload -->
        <div v-if="step === 'upload'" class="space-y-4">
            <label
                :for="fileInputId"
                :class="[
                    'group relative flex cursor-pointer flex-col items-center justify-center rounded-[8px] border border-dashed px-4 py-8 text-center transition-colors duration-150 ease-out focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-accent-primary sm:px-6 sm:py-10',
                    dragActive
                        ? 'border-accent-primary bg-accent-primary/5'
                        : 'border-default bg-canvas hover:border-accent-primary/60 hover:bg-subtle/60',
                    previewing ? 'cursor-wait opacity-80' : '',
                ]"
                @dragenter.prevent="handleDragEnter"
                @dragover.prevent
                @dragleave.prevent="handleDragLeave"
                @drop.prevent="handleDrop"
            >
                <input
                    :id="fileInputId"
                    ref="fileInputRef"
                    type="file"
                    accept=".csv,.tsv,.xls,.xlsx,.ods"
                    :aria-describedby="fileHelpId"
                    :disabled="previewing"
                    data-testid="import-leads-file-input"
                    class="sr-only"
                    @change="handleFileInputChange"
                />

                <div
                    class="pointer-events-none mb-3 flex h-10 w-10 items-center justify-center rounded-[6px] border border-default bg-surface text-secondary transition-colors duration-150 group-hover:text-primary"
                >
                    <Loader2
                        v-if="previewing"
                        class="h-5 w-5 animate-spin text-accent-primary"
                        :stroke-width="1.75"
                        aria-hidden="true"
                    />
                    <Upload
                        v-else
                        class="h-5 w-5"
                        :stroke-width="1.75"
                        aria-hidden="true"
                    />
                </div>

                <p
                    v-if="previewing"
                    class="pointer-events-none text-[13px] font-medium text-primary"
                >
                    Uploading &amp; checking duplicates...
                </p>
                <template v-else>
                    <p class="pointer-events-none text-[13px] font-medium text-primary">
                        Drop a spreadsheet here, or <span class="text-accent-primary underline underline-offset-2">click to browse</span>
                    </p>
                    <p
                        :id="fileHelpId"
                        class="pointer-events-none mt-1 text-[12px] text-secondary"
                    >
                        Supports .csv, .xls, .xlsx, .ods · Max 5 MB · Up to 1,000 rows
                    </p>
                </template>
            </label>

            <div class="flex flex-col gap-3 border-t border-default pt-3 sm:flex-row sm:items-center sm:justify-between">
                <a
                    :href="leadImportTemplateUrl"
                    download
                    class="inline-flex items-center gap-1.5 text-[12px] font-medium text-accent-primary hover:text-accent-hover hover:underline"
                >
                    <Download class="h-3.5 w-3.5 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                    <span>Download CSV Template</span>
                </a>

                <div class="flex items-center justify-end gap-2">
                    <Button
                        variant="secondary"
                        :disabled="previewing"
                        @click="handleOpenChange(false)"
                    >
                        Cancel
                    </Button>
                </div>
            </div>
        </div>

        <!-- Step 2: Review & Approval Gate -->
        <div v-else-if="step === 'review' && preview" class="space-y-3.5">
            <div class="max-h-[70vh] space-y-3.5 overflow-y-auto pr-0.5">
                <!-- Selected File Bar -->
                <div
                    ref="reviewFocusRef"
                    tabindex="-1"
                    class="flex flex-col gap-2 rounded-[6px] border border-default bg-subtle px-3 py-2 focus:outline-none sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex min-w-0 items-center gap-2.5">
                        <div
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[6px] border border-default bg-surface text-secondary"
                        >
                            <FileSpreadsheet class="h-4 w-4" :stroke-width="1.75" aria-hidden="true" />
                        </div>
                        <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                            <span class="max-w-[260px] truncate text-[13px] font-medium text-primary">
                                {{ selectedFile?.name || 'Selected file' }}
                            </span>
                            <span class="font-mono text-[12px] tabular-nums text-secondary">
                                {{ formatFileSize(selectedFile?.size) }}
                            </span>
                            <span class="font-mono text-[12px] tabular-nums text-secondary">
                                {{ preview.total_rows }} rows
                            </span>
                        </div>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-3">
                        <a
                            :href="leadImportTemplateUrl"
                            download
                            class="inline-flex items-center gap-1 text-[12px] font-medium text-accent-primary hover:text-accent-hover hover:underline"
                        >
                            <Download class="h-3.5 w-3.5 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                            <span>Download CSV Template</span>
                        </a>
                        <Button
                            variant="secondary"
                            size="sm"
                            :disabled="previewing || committing"
                            data-testid="import-leads-change-file"
                            @click="handleChangeFile"
                        >
                            Change File
                        </Button>
                    </div>
                </div>

                <!-- Collapsible Column Mapping -->
                <div class="overflow-hidden rounded-[6px] border border-default bg-surface">
                    <button
                        type="button"
                        :aria-expanded="mappingExpanded ? 'true' : 'false'"
                        :aria-controls="mappingPanelId"
                        class="flex w-full cursor-pointer items-center justify-between gap-2 bg-subtle px-3 py-2 text-left transition-colors duration-150 hover:bg-subtle/80"
                        @click="mappingExpanded = !mappingExpanded"
                    >
                        <div class="flex min-w-0 items-center gap-2">
                            <ChevronDown
                                :class="[
                                    'h-4 w-4 shrink-0 text-secondary transition-transform duration-150 ease-out',
                                    mappingExpanded ? 'rotate-180' : '',
                                ]"
                                :stroke-width="1.75"
                                aria-hidden="true"
                            />
                            <span class="text-[13px] font-semibold text-primary">Column Mapping</span>
                            <Badge variant="neutral">
                                {{ mappedFieldCount }} / {{ mappableFields.length }} mapped
                            </Badge>
                        </div>

                        <div
                            v-if="previewing"
                            class="flex shrink-0 items-center gap-1.5 text-[12px] text-secondary"
                        >
                            <Loader2 class="h-3.5 w-3.5 animate-spin text-accent-primary" :stroke-width="1.75" aria-hidden="true" />
                            <span>Refreshing preview...</span>
                        </div>
                    </button>

                    <div
                        v-if="mappingExpanded"
                        :id="mappingPanelId"
                        class="grid grid-cols-1 gap-2.5 border-t border-default bg-surface p-3 sm:grid-cols-2 lg:grid-cols-4"
                    >
                        <div
                            v-for="field in mappableFields"
                            :key="field"
                            class="min-w-0 rounded-[6px] border border-default bg-canvas p-2"
                        >
                            <label
                                :for="mappingSelectId(field)"
                                class="flex items-center justify-between gap-1 text-[11px] font-medium text-secondary"
                            >
                                <span class="truncate">{{ fieldLabel(field) }}</span>
                                <span class="shrink-0 font-mono text-[10px] text-muted">{{ field }}</span>
                            </label>
                            <select
                                :id="mappingSelectId(field)"
                                :value="columnMapping[field] ?? ''"
                                :disabled="previewing || committing"
                                :data-testid="`import-mapping-${field}`"
                                class="mt-1 h-7 w-full rounded-[6px] border border-default bg-surface px-2 text-[12px] text-primary transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-accent-primary disabled:cursor-not-allowed disabled:opacity-50"
                                @change="handleMappingChange(field, $event)"
                            >
                                <option value="">(Not mapped)</option>
                                <option
                                    v-for="header in preview.headers"
                                    :key="header"
                                    :value="header"
                                >
                                    {{ header }}
                                </option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- 3-Column Hairline Summary Counters -->
                <div
                    class="grid grid-cols-1 divide-y divide-default overflow-hidden rounded-[6px] border border-default bg-surface sm:grid-cols-3 sm:divide-x sm:divide-y-0"
                >
                    <div class="flex items-center justify-between gap-3 px-3.5 py-2.5">
                        <div class="min-w-0">
                            <p class="text-[12px] font-medium text-secondary">Ready to Import</p>
                            <p class="truncate text-[11px] text-muted">Status New, valid channel</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="h-2 w-2 rounded-full bg-accent-primary" aria-hidden="true" />
                            <span class="font-mono text-[18px] font-semibold leading-6 tabular-nums text-primary">
                                {{ preview.ready_count }}
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-3 px-3.5 py-2.5">
                        <div class="min-w-0">
                            <p class="text-[12px] font-medium text-secondary">Duplicates Skipped</p>
                            <p class="truncate text-[11px] text-muted">Existing lead, contact, or domain</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="h-2 w-2 rounded-full bg-status-warning" aria-hidden="true" />
                            <span class="font-mono text-[18px] font-semibold leading-6 tabular-nums text-status-warning">
                                {{ preview.duplicate_count }}
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-3 px-3.5 py-2.5">
                        <div class="min-w-0">
                            <p class="text-[12px] font-medium text-secondary">Invalid Rows</p>
                            <p class="truncate text-[11px] text-muted">Missing required fields</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="h-2 w-2 rounded-full bg-status-danger" aria-hidden="true" />
                            <span class="font-mono text-[18px] font-semibold leading-6 tabular-nums text-status-danger">
                                {{ preview.invalid_count }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Compact 32px Review Table -->
                <div class="overflow-hidden rounded-[6px] border border-default bg-surface">
                    <div
                        tabindex="0"
                        role="region"
                        aria-label="Lead import preview"
                        class="max-h-72 overflow-auto focus-visible:outline-2 focus-visible:outline-accent-primary"
                    >
                        <div
                            v-if="preview.rows.length === 0"
                            class="px-4 py-8 text-center text-[13px] text-secondary"
                        >
                            No spreadsheet rows were found to preview.
                        </div>

                        <table v-else class="w-full border-collapse text-left">
                            <thead class="sticky top-0 z-10 border-b border-default bg-subtle">
                                <tr class="h-8 text-[11px] font-medium text-secondary">
                                    <th scope="col" class="w-9 px-2.5 text-center">
                                        <input
                                            type="checkbox"
                                            aria-label="Select all ready leads"
                                            :checked="allReadySelected"
                                            :indeterminate.prop="someReadySelected && !allReadySelected"
                                            :disabled="readyRows.length === 0 || previewing || committing"
                                            class="h-3.5 w-3.5 cursor-pointer rounded-[3px] border border-default accent-accent-primary disabled:cursor-not-allowed disabled:opacity-50"
                                            @change="toggleSelectAllReady"
                                        />
                                    </th>
                                    <th scope="col" class="w-11 px-2.5 font-mono whitespace-nowrap">#</th>
                                    <th scope="col" class="px-2.5 whitespace-nowrap">Name</th>
                                    <th scope="col" class="px-2.5 whitespace-nowrap">Company</th>
                                    <th scope="col" class="px-2.5 whitespace-nowrap">Email</th>
                                    <th scope="col" class="px-2.5 whitespace-nowrap">Source</th>
                                    <th scope="col" class="px-2.5 text-right whitespace-nowrap">Est. Value</th>
                                    <th scope="col" class="px-2.5 whitespace-nowrap">Status / Reason</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-default text-[13px]">
                                <tr
                                    v-for="row in preview.rows"
                                    :key="row.row_index"
                                    :class="[
                                        'h-8 transition-colors duration-150',
                                        row.outcome === 'ready'
                                            ? 'hover:bg-subtle/60'
                                            : row.outcome === 'invalid'
                                                ? 'bg-status-danger/5'
                                                : 'bg-subtle/40',
                                    ]"
                                >
                                    <td class="px-2.5 py-1 text-center align-middle">
                                        <input
                                            type="checkbox"
                                            :aria-label="`Select row ${row.row_index}: ${row.full_name}`"
                                            :checked="row.outcome === 'ready' && selectedRowIndices.has(row.row_index)"
                                            :disabled="row.outcome !== 'ready' || previewing || committing"
                                            class="h-3.5 w-3.5 cursor-pointer rounded-[3px] border border-default accent-accent-primary disabled:cursor-not-allowed disabled:opacity-45"
                                            @change="toggleRowSelection(row.row_index)"
                                        />
                                    </td>
                                    <td class="px-2.5 py-1 align-middle font-mono text-[12px] tabular-nums text-muted">
                                        {{ row.row_index }}
                                    </td>
                                    <td class="max-w-[160px] px-2.5 py-1 align-middle">
                                        <span class="block truncate font-medium text-primary">
                                            {{ row.full_name }}
                                        </span>
                                    </td>
                                    <td class="max-w-[160px] px-2.5 py-1 align-middle">
                                        <span class="block truncate text-[12px] text-secondary">
                                            {{ row.company_name || '—' }}
                                        </span>
                                    </td>
                                    <td class="max-w-[190px] px-2.5 py-1 align-middle">
                                        <span class="block truncate font-mono text-[12px] text-secondary">
                                            {{ row.email || '—' }}
                                        </span>
                                    </td>
                                    <td class="px-2.5 py-1 align-middle whitespace-nowrap">
                                        <Badge variant="neutral">
                                            {{ formatEnumLabel(row.source) }}
                                        </Badge>
                                    </td>
                                    <td class="px-2.5 py-1 text-right align-middle font-mono text-[12px] tabular-nums whitespace-nowrap text-primary">
                                        {{ formatMoneyCents(row.payload?.estimated_value_cents, row.payload?.currency || 'USD') }}
                                    </td>
                                    <td class="min-w-[200px] px-2.5 py-1 align-middle">
                                        <Badge v-if="row.outcome === 'ready'" variant="cobalt">
                                            Ready
                                        </Badge>
                                        <div
                                            v-else-if="row.outcome === 'duplicate'"
                                            class="flex flex-wrap items-center gap-1.5"
                                        >
                                            <Badge variant="warning">Duplicate</Badge>
                                            <span
                                                v-if="row.reason"
                                                class="text-[11px] leading-4 text-status-warning"
                                            >
                                                {{ row.reason }}
                                            </span>
                                        </div>
                                        <div
                                            v-else
                                            class="flex flex-wrap items-center gap-1.5"
                                        >
                                            <Badge variant="danger">Invalid</Badge>
                                            <span
                                                v-if="row.reason"
                                                class="text-[11px] leading-4 text-status-danger"
                                            >
                                                {{ row.reason }}
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Step 2 Footer -->
            <div class="flex flex-col gap-3 border-t border-default pt-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-wrap items-center gap-2 text-[12px]">
                    <span class="font-mono font-semibold tabular-nums text-primary">
                        {{ selectedPayloads.length }} of {{ preview.total_rows }} rows selected
                    </span>
                    <span class="text-secondary">
                        Only checked ready rows enter Leads with status New.
                    </span>
                </div>

                <div class="flex items-center justify-end gap-2">
                    <Button
                        variant="secondary"
                        :disabled="committing"
                        @click="handleOpenChange(false)"
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        :loading="committing"
                        :disabled="selectedPayloads.length === 0 || committing || previewing"
                        data-testid="confirm-import-leads"
                        @click="handleCommitImport"
                    >
                        Approve &amp; Import {{ selectedPayloads.length }} Leads
                    </Button>
                </div>
            </div>
        </div>

        <!-- Step 3: Imported Confirmation -->
        <div
            v-else-if="step === 'done' && importResult"
            ref="doneFocusRef"
            tabindex="-1"
            class="space-y-4 focus:outline-none"
        >
            <div
                data-testid="import-leads-success"
                :class="[
                    'flex items-start gap-3 rounded-[6px] border px-4 py-3.5',
                    importResult.inserted_count > 0
                        ? 'border-status-success/30 bg-status-success/10'
                        : 'border-status-warning/30 bg-status-warning/10',
                ]"
            >
                <CheckCircle2
                    v-if="importResult.inserted_count > 0"
                    class="mt-0.5 h-5 w-5 shrink-0 text-status-success"
                    :stroke-width="1.75"
                    aria-hidden="true"
                />
                <AlertCircle
                    v-else
                    class="mt-0.5 h-5 w-5 shrink-0 text-status-warning"
                    :stroke-width="1.75"
                    aria-hidden="true"
                />
                <div class="min-w-0 space-y-1">
                    <p
                        v-if="importResult.inserted_count > 0"
                        class="text-[14px] font-semibold text-primary"
                    >
                        <span class="font-mono tabular-nums">{{ importResult.inserted_count }}</span>
                        {{ importResult.inserted_count === 1 ? 'lead' : 'leads' }} added to Leads with status
                        <span class="font-mono">New</span>
                    </p>
                    <p v-else class="text-[14px] font-semibold text-primary">
                        No new leads were inserted
                    </p>
                    <p class="text-[12px] text-secondary">
                        {{
                            importResult.inserted_count > 0
                                ? 'Zero Deals, Companies, or Contacts were created automatically. Qualify and convert prospects manually from each lead record.'
                                : 'All submitted rows were skipped because they matched existing CRM records or failed validation.'
                        }}
                    </p>
                    <p
                        v-if="importResult.duplicate_count > 0 || importResult.invalid_count > 0"
                        class="font-mono text-[11px] tabular-nums text-secondary"
                    >
                        Skipped duplicates: {{ importResult.duplicate_count }} · Invalid rows: {{ importResult.invalid_count }}
                    </p>
                </div>
            </div>

            <div class="flex items-center justify-end border-t border-default pt-3">
                <Button
                    variant="primary"
                    data-testid="import-leads-done"
                    @click="handleOpenChange(false)"
                >
                    Done
                </Button>
            </div>
        </div>
    </Dialog>
</template>
