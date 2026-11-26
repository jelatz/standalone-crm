<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertCircle,
    AlertTriangle,
    CheckCircle2,
    ChevronRight,
    Download,
    Paperclip,
    Plus,
    RefreshCw,
    Save,
    Trash2,
    UserCheck,
    XCircle,
} from 'lucide-vue-next';
import Badge, { type BadgeVariant } from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Input from '@/components/ui/Input.vue';
import ActivityTimeline from '@/modules/crm/components/ActivityTimeline.vue';
import CollaboratorsPanel from '@/modules/crm/components/CollaboratorsPanel.vue';
import CustomFieldsForm from '@/modules/crm/components/CustomFieldsForm.vue';
import StageGateModal, { type StageGateSubmitPayload } from '@/modules/crm/components/StageGateModal.vue';
import {
    crmApi,
    extractFirstFieldErrors,
    OptimisticLockConflictError,
    StageGateValidationError,
    ValidationRequestError,
} from '@/modules/crm/api/crmApi';
import {
    formatEnumLabel,
    formatFileSize,
    formatMoneyCents,
} from '@/modules/crm/utils/formatters';

const props = defineProps<{
    deal: App.Modules.Crm.Deals.Data.DealDetailData;
    company: {
        id: string;
        name: string;
        domain: string | null;
        lifecycle_stage: string;
    } | null;
    primaryContact: {
        id: string;
        full_name: string;
        primary_email: string | null;
        job_title: string | null;
    } | null;
    pipeline: App.Modules.Crm.Pipelines.Data.PipelineData;
    stakeholders: App.Modules.Crm.Deals.Data.DealStakeholderData[];
    collaborators: App.Modules.Core.Rbac.Data.EntityCollaboratorData[];
    attachments: App.Modules.Core.Attachments.Data.AttachmentSummaryData[];
    customFieldDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    timeline: App.Modules.Crm.Timeline.Data.TimelinePageData;
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
    stakeholderRoles: string[];
    collaboratorAccessLevels: string[];
    collaboratorRoleLabels: string[];
    can: {
        update: boolean;
        delete: boolean;
        moveStage: boolean;
        overrideGates: boolean;
        manageCollaborators: boolean;
    };
}>();

const instanceId = useId();
const localDeal = ref<App.Modules.Crm.Deals.Data.DealDetailData>({
    ...props.deal,
    custom_fields: { ...(props.deal.custom_fields ?? {}) },
});
const localStakeholders = ref<App.Modules.Crm.Deals.Data.DealStakeholderData[]>([
    ...props.stakeholders,
]);

const form = ref({
    title: localDeal.value.title,
    company_id: localDeal.value.company_id ?? '',
    primary_contact_id: localDeal.value.primary_contact_id ?? '',
    account_manager_id: localDeal.value.account_manager_id ?? '',
    amount_dollars: String((localDeal.value.amount_cents / 100).toFixed(2).replace(/\.00$/, '')),
    currency: localDeal.value.currency || 'USD',
    expected_close_date: localDeal.value.expected_close_date ?? '',
    lost_reason: localDeal.value.lost_reason ?? '',
    custom_fields: { ...(localDeal.value.custom_fields ?? {}) } as Record<string, any>,
});

const saving = ref<boolean>(false);
const saveError = ref<string>('');
const saveSuccess = ref<string>('');
const conflictError = ref<string>('');
const fieldErrors = ref<Record<string, string>>({});

// Stage movement & StageGateModal
const movingStage = ref<boolean>(false);
const gateModalState = ref<{
    targetStage: App.Modules.Crm.Pipelines.Data.PipelineStageData;
    missingCoreFields: string[];
    missingCustomFields: string[];
    errors: Record<string, string>;
    errorMessage: string;
    viaUpdate: boolean;
} | null>(null);
const gateSubmitting = ref<boolean>(false);

const wonStage = computed(
    () => props.pipeline.stages.find((s) => s.stage_type === 'won') ?? null,
);
const lostStage = computed(
    () => props.pipeline.stages.find((s) => s.stage_type === 'lost') ?? null,
);

const stageBadgeVariant = computed<BadgeVariant>(() => {
    if (localDeal.value.stage_type === 'won') {
        return 'success';
    }
    if (localDeal.value.stage_type === 'lost') {
        return 'danger';
    }
    return 'cobalt';
});

function syncLocalFromDeal(updated: App.Modules.Crm.Deals.Data.DealDetailData): void {
    localDeal.value = {
        ...updated,
        custom_fields: { ...(updated.custom_fields ?? {}) },
    };
    form.value = {
        title: updated.title,
        company_id: updated.company_id ?? '',
        primary_contact_id: updated.primary_contact_id ?? '',
        account_manager_id: updated.account_manager_id ?? '',
        amount_dollars: String((updated.amount_cents / 100).toFixed(2).replace(/\.00$/, '')),
        currency: updated.currency || 'USD',
        expected_close_date: updated.expected_close_date ?? '',
        lost_reason: updated.lost_reason ?? '',
        custom_fields: { ...(updated.custom_fields ?? {}) },
    };
}

watch(
    () => props.deal,
    (nextDeal) => syncLocalFromDeal(nextDeal),
    { deep: true },
);

watch(
    () => props.stakeholders,
    (next) => {
        localStakeholders.value = [...next];
    },
    { deep: true },
);

async function handleSaveProperties(): Promise<void> {
    if (!props.can.update || saving.value) {
        return;
    }

    saving.value = true;
    saveError.value = '';
    saveSuccess.value = '';
    conflictError.value = '';
    fieldErrors.value = {};

    const amountRaw = form.value.amount_dollars.trim();
    const amountCents =
        amountRaw !== '' && Number.isFinite(Number(amountRaw))
            ? Math.round(Number(amountRaw) * 100)
            : 0;

    const initialCompanyId = localDeal.value.company_id ?? '';
    const companyChanged =
        !localDeal.value.has_masked_company &&
        form.value.company_id !== initialCompanyId &&
        form.value.company_id !== '';

    const initialPrimaryContactId = localDeal.value.primary_contact_id ?? '';
    const primaryContactChanged =
        !localDeal.value.has_masked_primary_contact &&
        form.value.primary_contact_id !== initialPrimaryContactId;

    try {
        const updated = await crmApi.updateDeal(localDeal.value.id, {
            expected_version: localDeal.value.version,
            ...(companyChanged ? { company_id: form.value.company_id } : {}),
            ...(primaryContactChanged
                ? { primary_contact_id: form.value.primary_contact_id || null }
                : {}),
            account_manager_id: form.value.account_manager_id || null,
            title: form.value.title.trim(),
            amount_cents: amountCents,
            currency: form.value.currency.trim().toUpperCase() || 'USD',
            expected_close_date: form.value.expected_close_date || null,
            lost_reason: form.value.lost_reason.trim() || null,
            custom_fields: form.value.custom_fields,
            override_gates: false,
        });

        syncLocalFromDeal(updated);
        saveSuccess.value = `Saved (v${updated.version}).`;
    } catch (error) {
        if (error instanceof OptimisticLockConflictError) {
            conflictError.value = error.message;
        } else if (error instanceof StageGateValidationError) {
            const currentStage =
                props.pipeline.stages.find((s) => s.id === localDeal.value.stage_id) ?? null;
            if (currentStage) {
                gateModalState.value = {
                    targetStage: currentStage,
                    missingCoreFields: error.missingCoreFields,
                    missingCustomFields: error.missingCustomFields,
                    errors: extractFirstFieldErrors(error.errors),
                    errorMessage: error.message,
                    viaUpdate: true,
                };
            } else {
                saveError.value = error.message;
            }
        } else if (error instanceof ValidationRequestError) {
            fieldErrors.value = extractFirstFieldErrors(error.errors);
            saveError.value = error.message;
        } else {
            saveError.value = error instanceof Error ? error.message : 'Unable to save deal.';
        }
    } finally {
        saving.value = false;
    }
}

async function handleStageMove(targetStageId: string): Promise<void> {
    if (!props.can.moveStage || movingStage.value || targetStageId === localDeal.value.stage_id) {
        return;
    }

    const targetStage = props.pipeline.stages.find((s) => s.id === targetStageId);
    if (!targetStage) {
        return;
    }

    movingStage.value = true;
    saveError.value = '';
    saveSuccess.value = '';
    conflictError.value = '';

    try {
        const updated = await crmApi.moveDealStage(localDeal.value.id, {
            target_stage_id: targetStage.id,
            expected_version: localDeal.value.version,
            before_deal_id: null,
            after_deal_id: null,
            core_field_updates: {},
            custom_field_updates: {},
            override_gates: false,
        });

        syncLocalFromDeal(updated);
        saveSuccess.value = `Moved to ${updated.stage_name ?? targetStage.name} (v${updated.version}).`;
    } catch (error) {
        if (error instanceof OptimisticLockConflictError) {
            conflictError.value = error.message;
        } else if (error instanceof StageGateValidationError) {
            gateModalState.value = {
                targetStage,
                missingCoreFields: error.missingCoreFields,
                missingCustomFields: error.missingCustomFields,
                errors: extractFirstFieldErrors(error.errors),
                errorMessage: error.message,
                viaUpdate: false,
            };
        } else {
            saveError.value = error instanceof Error ? error.message : 'Unable to move deal stage.';
        }
    } finally {
        movingStage.value = false;
    }
}

async function handleGateSubmit(payload: StageGateSubmitPayload): Promise<void> {
    const state = gateModalState.value;
    if (!state || gateSubmitting.value) {
        return;
    }

    gateSubmitting.value = true;
    state.errors = {};
    state.errorMessage = '';

    try {
        const updated = await crmApi.moveDealStage(localDeal.value.id, {
            target_stage_id: state.targetStage.id,
            expected_version: localDeal.value.version,
            before_deal_id: null,
            after_deal_id: null,
            core_field_updates: payload.core_field_updates,
            custom_field_updates: payload.custom_field_updates,
            override_gates: payload.override_gates,
        });

        syncLocalFromDeal(updated);
        gateModalState.value = null;
        saveSuccess.value = `Updated & moved to ${updated.stage_name ?? state.targetStage.name} (v${updated.version}).`;
    } catch (error) {
        if (error instanceof StageGateValidationError) {
            state.missingCoreFields = error.missingCoreFields;
            state.missingCustomFields = error.missingCustomFields;
            state.errors = extractFirstFieldErrors(error.errors);
            state.errorMessage = error.message;
        } else if (error instanceof ValidationRequestError) {
            state.errors = extractFirstFieldErrors(error.errors);
            state.errorMessage = error.message;
        } else if (error instanceof OptimisticLockConflictError) {
            gateModalState.value = null;
            conflictError.value = error.message;
        } else {
            state.errorMessage = error instanceof Error ? error.message : 'Failed to satisfy stage gate.';
        }
    } finally {
        gateSubmitting.value = false;
    }
}

function refreshDealFromServer(): void {
    router.reload({
        onSuccess: () => {
            syncLocalFromDeal(props.deal);
            localStakeholders.value = [...props.stakeholders];
            conflictError.value = '';
            saveError.value = '';
        },
    });
}

// Stakeholders Editor (SyncDealContactsData)
const newStakeholderContactId = ref<string>('');
const newStakeholderRole = ref<string>(props.stakeholderRoles[0] ?? 'decision_maker');
const syncingStakeholders = ref<boolean>(false);
const stakeholderError = ref<string>('');

const availableStakeholderContacts = computed(() => {
    const existingIds = new Set(localStakeholders.value.map((s) => s.contact_id));
    return props.contacts.filter((c) => !existingIds.has(c.id));
});

async function persistStakeholders(
    nextList: Array<{ contact_id: string; stakeholder_role: App.Modules.Crm.Deals.Enums.DealStakeholderRoleEnum }>,
): Promise<void> {
    if (!props.can.update || syncingStakeholders.value) {
        return;
    }

    syncingStakeholders.value = true;
    stakeholderError.value = '';

    try {
        const result = await crmApi.syncDealContacts(localDeal.value.id, {
            contacts: nextList,
        });
        localStakeholders.value = result.contacts;
    } catch (error) {
        stakeholderError.value =
            error instanceof Error ? error.message : 'Unable to sync deal stakeholders.';
    } finally {
        syncingStakeholders.value = false;
    }
}

async function addStakeholder(): Promise<void> {
    if (!newStakeholderContactId.value) {
        return;
    }

    const nextPayload = [
        ...localStakeholders.value.map((s) => ({
            contact_id: s.contact_id,
            stakeholder_role: s.stakeholder_role,
        })),
        {
            contact_id: newStakeholderContactId.value,
            stakeholder_role: newStakeholderRole.value as App.Modules.Crm.Deals.Enums.DealStakeholderRoleEnum,
        },
    ];

    await persistStakeholders(nextPayload);
    if (!stakeholderError.value) {
        newStakeholderContactId.value = '';
    }
}

async function updateStakeholderRole(contactId: string, role: string): Promise<void> {
    const nextPayload = localStakeholders.value.map((s) => ({
        contact_id: s.contact_id,
        stakeholder_role: (s.contact_id === contactId
            ? role
            : s.stakeholder_role) as App.Modules.Crm.Deals.Enums.DealStakeholderRoleEnum,
    }));
    await persistStakeholders(nextPayload);
}

async function removeStakeholder(contactId: string): Promise<void> {
    const nextPayload = localStakeholders.value
        .filter((s) => s.contact_id !== contactId)
        .map((s) => ({
            contact_id: s.contact_id,
            stakeholder_role: s.stakeholder_role,
        }));
    await persistStakeholders(nextPayload);
}

// Delete Deal Modal
const deleteDialogOpen = ref<boolean>(false);
const deleting = ref<boolean>(false);
const deleteError = ref<string>('');

async function handleDeleteDeal(): Promise<void> {
    if (!props.can.delete || deleting.value) {
        return;
    }

    deleting.value = true;
    deleteError.value = '';

    try {
        await crmApi.deleteDeal(localDeal.value.id);
        deleteDialogOpen.value = false;
        router.visit('/app/crm/deals');
    } catch (error) {
        deleteError.value = error instanceof Error ? error.message : 'Unable to delete deal.';
    } finally {
        deleting.value = false;
    }
}
</script>

<template>
    <Head :title="localDeal.title" />

    <div class="space-y-4">
        <!-- Deal Header -->
        <header class="flex flex-col gap-3 rounded-[8px] border border-default bg-surface px-4 py-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0 space-y-1">
                <nav aria-label="Breadcrumb" class="flex flex-wrap items-center gap-1 text-[12px] text-secondary">
                    <Link href="/app/crm/deals" class="hover:text-primary">Deals</Link>
                    <ChevronRight class="h-3.5 w-3.5 text-muted" :stroke-width="1.75" aria-hidden="true" />
                    <Link
                        :href="`/app/crm/deals?pipeline_id=${props.pipeline.id}`"
                        class="hover:text-primary"
                    >
                        {{ props.pipeline.name }}
                    </Link>
                    <ChevronRight class="h-3.5 w-3.5 text-muted" :stroke-width="1.75" aria-hidden="true" />
                    <span class="truncate font-medium text-primary">{{ localDeal.title }}</span>
                </nav>

                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="truncate text-[18px] font-semibold tracking-[-0.02em] text-primary">
                        {{ localDeal.title }}
                    </h2>
                    <Badge :variant="stageBadgeVariant">
                        {{ localDeal.stage_name ?? 'Stage' }}
                    </Badge>
                    <Badge variant="neutral" data-testid="deal-version-badge">
                        v{{ localDeal.version }}
                    </Badge>
                    <span class="font-mono text-[14px] font-semibold tabular-nums text-primary">
                        {{ formatMoneyCents(localDeal.amount_cents, localDeal.currency) }}
                    </span>
                </div>
            </div>

            <!-- Quick Stage Controls & Delete -->
            <div class="flex flex-wrap shrink-0 items-center gap-2">
                <div v-if="props.can.moveStage" class="flex flex-wrap items-center gap-2">
                    <label :for="`deal-quick-stage-${instanceId}`" class="sr-only">
                        Move deal stage
                    </label>
                    <select
                        :id="`deal-quick-stage-${instanceId}`"
                        :value="localDeal.stage_id"
                        :disabled="movingStage"
                        data-testid="deal-detail-stage-select"
                        class="h-8 rounded-[6px] border border-default bg-surface px-2.5 text-[12px] font-medium text-primary focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50"
                        @change="handleStageMove(($event.target as HTMLSelectElement).value)"
                    >
                        <option
                            v-for="stageOption in props.pipeline.stages"
                            :key="stageOption.id"
                            :value="stageOption.id"
                        >
                            {{ stageOption.name }} ({{ stageOption.win_probability }}%)
                        </option>
                    </select>

                    <Button
                        v-if="wonStage && localDeal.stage_id !== wonStage.id"
                        variant="secondary"
                        size="sm"
                        :loading="movingStage"
                        class="text-status-success"
                        @click="handleStageMove(wonStage.id)"
                    >
                        <CheckCircle2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <span>Mark Won</span>
                    </Button>

                    <Button
                        v-if="lostStage && localDeal.stage_id !== lostStage.id"
                        variant="secondary"
                        size="sm"
                        :loading="movingStage"
                        class="text-status-danger"
                        @click="handleStageMove(lostStage.id)"
                    >
                        <XCircle class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <span>Mark Lost</span>
                    </Button>
                </div>

                <Button
                    v-if="props.can.delete"
                    variant="ghost"
                    size="sm"
                    class="text-status-danger hover:bg-status-danger/10 hover:text-status-danger"
                    @click="deleteDialogOpen = true"
                >
                    <Trash2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>Delete</span>
                </Button>
            </div>
        </header>

        <!-- 409 Optimistic Lock Conflict Banner -->
        <div
            v-if="conflictError"
            role="alert"
            data-testid="deal-detail-conflict-banner"
            class="flex flex-col gap-2 rounded-[8px] border border-status-warning/40 bg-status-warning/10 px-4 py-3 text-[13px] text-primary sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex items-start gap-2">
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-status-warning" :stroke-width="1.75" aria-hidden="true" />
                <div>
                    <p class="font-semibold">Version Conflict (409)</p>
                    <p class="text-[12px] text-secondary">{{ conflictError }}</p>
                </div>
            </div>
            <Button
                variant="secondary"
                size="sm"
                data-testid="deal-detail-reload-latest"
                @click="refreshDealFromServer"
            >
                <RefreshCw class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                <span>Reload Latest</span>
            </Button>
        </div>

        <!-- 3-Pane Workspace Matching Stitch Screen c624b7c5799d41d5b8e5e2e0d13f5b46 -->
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
            <!-- Left 280px Pane: Deal Properties + Typed Custom Fields -->
            <aside aria-label="Deal properties" class="w-full shrink-0 rounded-[8px] border border-default bg-surface p-3.5 space-y-3 lg:w-[280px]">
                <div class="flex items-center justify-between border-b border-default pb-2">
                    <h3 class="text-[13px] font-semibold text-primary">Deal Properties</h3>
                    <span class="font-mono text-[11px] tabular-nums text-muted">
                        {{ localDeal.stage_win_probability ?? 0 }}% prob
                    </span>
                </div>

                <div
                    v-if="saveError"
                    role="alert"
                    class="flex items-start gap-2 rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-2.5 py-2 text-[12px] text-status-danger"
                >
                    <AlertCircle class="mt-0.5 h-3.5 w-3.5 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                    <span>{{ saveError }}</span>
                </div>

                <div
                    v-if="saveSuccess"
                    role="status"
                    class="flex items-center gap-2 rounded-[6px] border border-status-success/30 bg-status-success/10 px-2.5 py-2 text-[12px] text-status-success"
                >
                    <CheckCircle2 class="h-3.5 w-3.5 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                    <span>{{ saveSuccess }}</span>
                </div>

                <form class="space-y-3" @submit.prevent="handleSaveProperties">
                    <div>
                        <label :for="`deal-title-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Deal Title
                        </label>
                        <Input
                            :id="`deal-title-${instanceId}`"
                            v-model="form.title"
                            :disabled="!props.can.update || saving"
                            :error-message="fieldErrors.title ?? ''"
                        />
                    </div>

                    <div>
                        <template v-if="localDeal.has_masked_company">
                            <span class="mb-1 block text-[12px] font-medium text-secondary">
                                Company
                            </span>
                            <div
                                data-testid="deal-masked-company"
                                class="rounded-[6px] border border-default bg-subtle px-2.5 py-1.5 text-[12px] text-secondary"
                            >
                                Restricted (outside your silo)
                            </div>
                        </template>
                        <template v-else-if="props.can.update && props.companies.length > 0">
                            <label :for="`deal-company-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Company
                            </label>
                            <select
                                :id="`deal-company-${instanceId}`"
                                v-model="form.company_id"
                                :disabled="saving"
                                class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                            >
                                <option
                                    v-for="companyOption in props.companies"
                                    :key="companyOption.id"
                                    :value="companyOption.id"
                                >
                                    {{ companyOption.name }}
                                </option>
                            </select>
                        </template>
                        <template v-else>
                            <span class="mb-1 block text-[12px] font-medium text-secondary">
                                Company
                            </span>
                            <div class="text-[13px] text-primary">
                                <Link
                                    v-if="props.company"
                                    :href="`/app/crm/companies/${props.company.id}`"
                                    class="font-medium text-accent-primary hover:underline"
                                >
                                    {{ props.company.name }}
                                </Link>
                                <span v-else class="text-muted">Restricted account</span>
                            </div>
                        </template>
                    </div>

                    <div>
                        <template v-if="localDeal.has_masked_primary_contact">
                            <span class="mb-1 block text-[12px] font-medium text-secondary">
                                Primary Contact
                            </span>
                            <div
                                data-testid="deal-masked-primary-contact"
                                class="rounded-[6px] border border-default bg-subtle px-2.5 py-1.5 text-[12px] text-secondary"
                            >
                                Restricted (outside your silo)
                            </div>
                        </template>
                        <template v-else-if="props.can.update">
                            <label :for="`deal-primary-contact-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Primary Contact
                            </label>
                            <select
                                :id="`deal-primary-contact-${instanceId}`"
                                v-model="form.primary_contact_id"
                                :disabled="saving"
                                class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                            >
                                <option value="">None</option>
                                <option
                                    v-for="contactOption in props.contacts"
                                    :key="contactOption.id"
                                    :value="contactOption.id"
                                >
                                    {{ contactOption.full_name }}
                                </option>
                            </select>
                        </template>
                        <template v-else>
                            <span class="mb-1 block text-[12px] font-medium text-secondary">
                                Primary Contact
                            </span>
                            <div class="text-[13px] text-primary">
                                <Link
                                    v-if="props.primaryContact"
                                    :href="`/app/crm/contacts/${props.primaryContact.id}`"
                                    class="font-medium text-accent-primary hover:underline"
                                >
                                    {{ props.primaryContact.full_name }}
                                </Link>
                                <span v-else class="text-muted">None</span>
                            </div>
                        </template>
                    </div>

                    <div class="grid grid-cols-3 gap-2">
                        <div class="col-span-2">
                            <label :for="`deal-amount-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Amount
                            </label>
                            <Input
                                :id="`deal-amount-${instanceId}`"
                                v-model="form.amount_dollars"
                                type="number"
                                class="font-mono tabular-nums"
                                :disabled="!props.can.update || saving"
                                :error-message="fieldErrors.amount_cents ?? ''"
                            />
                        </div>
                        <div>
                            <label :for="`deal-currency-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Currency
                            </label>
                            <Input
                                :id="`deal-currency-${instanceId}`"
                                v-model="form.currency"
                                maxlength="3"
                                class="font-mono uppercase"
                                :disabled="!props.can.update || saving"
                                :error-message="fieldErrors.currency ?? ''"
                            />
                        </div>
                    </div>

                    <div>
                        <label :for="`deal-close-date-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Expected Close Date
                        </label>
                        <Input
                            :id="`deal-close-date-${instanceId}`"
                            v-model="form.expected_close_date"
                            type="date"
                            class="font-mono tabular-nums"
                            :disabled="!props.can.update || saving"
                            :error-message="fieldErrors.expected_close_date ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`deal-am-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Account Manager
                        </label>
                        <select
                            :id="`deal-am-${instanceId}`"
                            v-model="form.account_manager_id"
                            :disabled="!props.can.update || saving"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50"
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

                    <div v-if="localDeal.stage_type === 'lost' || form.lost_reason">
                        <label :for="`deal-lost-reason-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Lost Reason
                        </label>
                        <Input
                            :id="`deal-lost-reason-${instanceId}`"
                            v-model="form.lost_reason"
                            :disabled="!props.can.update || saving"
                            :error-message="fieldErrors.lost_reason ?? ''"
                        />
                    </div>

                    <div v-if="props.customFieldDefinitions.length > 0" class="border-t border-default pt-3">
                        <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-secondary">
                            Custom Fields
                        </p>
                        <CustomFieldsForm
                            v-model="form.custom_fields"
                            :definitions="props.customFieldDefinitions"
                            :assignable-users="props.assignableUsers"
                            :errors="fieldErrors"
                            :disabled="!props.can.update || saving"
                            id-prefix="deal-detail-cf"
                        />
                    </div>

                    <Button
                        v-if="props.can.update"
                        type="submit"
                        variant="primary"
                        size="md"
                        class="w-full"
                        :loading="saving"
                    >
                        <Save class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <span>Save Properties</span>
                    </Button>
                </form>
            </aside>

            <!-- Center Pane: Unified Activity Timeline & Embedded TipTap Composer -->
            <div class="min-w-0 flex-1">
                <ActivityTimeline
                    linkable-type="deal"
                    :linkable-id="localDeal.id"
                    :initial-page="props.timeline"
                    :can-create-note="props.can.update"
                />
            </div>

            <!-- Right 280px Pane: Stakeholders, Collaborators & Attachments -->
            <aside aria-label="Stakeholders, collaborators, and attachments" class="w-full shrink-0 space-y-4 lg:w-[280px]">
                <!-- Stakeholders Panel -->
                <section
                    aria-label="Deal stakeholders"
                    class="rounded-[8px] border border-default bg-surface p-3.5 space-y-3"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="flex items-center gap-1.5 text-[13px] font-semibold text-primary">
                            <UserCheck class="h-3.5 w-3.5 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                            <span>Stakeholders</span>
                        </h3>
                        <Badge variant="neutral">{{ localStakeholders.length }}</Badge>
                    </div>

                    <div
                        v-if="stakeholderError"
                        role="alert"
                        class="rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-2.5 py-2 text-[12px] text-status-danger"
                    >
                        {{ stakeholderError }}
                    </div>

                    <p v-if="localStakeholders.length === 0" class="rounded-[6px] border border-dashed border-default px-3 py-3 text-[12px] text-muted">
                        No deal stakeholders linked yet.
                    </p>

                    <ul v-else class="divide-y divide-default rounded-[6px] border border-default">
                        <li
                            v-for="stakeholder in localStakeholders"
                            :key="stakeholder.contact_id"
                            class="space-y-1.5 px-2.5 py-2 text-[12px]"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <Link
                                        :href="`/app/crm/contacts/${stakeholder.contact_id}`"
                                        class="block truncate font-medium text-primary hover:text-accent-primary"
                                    >
                                        {{ stakeholder.full_name }}
                                    </Link>
                                    <p v-if="stakeholder.job_title" class="truncate text-[11px] text-secondary">
                                        {{ stakeholder.job_title }}
                                    </p>
                                </div>
                                <button
                                    v-if="props.can.update"
                                    type="button"
                                    :aria-label="`Remove stakeholder ${stakeholder.full_name}`"
                                    :disabled="syncingStakeholders"
                                    class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-[4px] text-secondary hover:bg-status-danger/10 hover:text-status-danger cursor-pointer disabled:opacity-40"
                                    @click="removeStakeholder(stakeholder.contact_id)"
                                >
                                    <Trash2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                </button>
                            </div>

                            <div v-if="props.can.update">
                                <label :for="`sh-role-${stakeholder.contact_id}`" class="sr-only">
                                    Stakeholder role for {{ stakeholder.full_name }}
                                </label>
                                <select
                                    :id="`sh-role-${stakeholder.contact_id}`"
                                    :value="stakeholder.stakeholder_role"
                                    :disabled="syncingStakeholders"
                                    class="h-6 w-full rounded-[4px] border border-default bg-subtle px-1.5 text-[11px] text-primary"
                                    @change="updateStakeholderRole(stakeholder.contact_id, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option
                                        v-for="role in props.stakeholderRoles"
                                        :key="role"
                                        :value="role"
                                    >
                                        {{ formatEnumLabel(role) }}
                                    </option>
                                </select>
                            </div>
                            <Badge v-else variant="neutral">
                                {{ formatEnumLabel(stakeholder.stakeholder_role) }}
                            </Badge>
                        </li>
                    </ul>

                    <form
                        v-if="props.can.update"
                        class="space-y-2 border-t border-default pt-2.5"
                        @submit.prevent="addStakeholder"
                    >
                        <div>
                            <label :for="`sh-add-contact-${instanceId}`" class="mb-1 block text-[11px] font-medium text-secondary">
                                Add stakeholder
                            </label>
                            <select
                                :id="`sh-add-contact-${instanceId}`"
                                v-model="newStakeholderContactId"
                                :disabled="syncingStakeholders"
                                class="h-8 w-full rounded-[6px] border border-default bg-surface px-2 text-[12px] text-primary"
                            >
                                <option value="">Select contact...</option>
                                <option
                                    v-for="contact in availableStakeholderContacts"
                                    :key="contact.id"
                                    :value="contact.id"
                                >
                                    {{ contact.full_name }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label :for="`sh-add-role-${instanceId}`" class="sr-only">
                                Stakeholder role
                            </label>
                            <select
                                :id="`sh-add-role-${instanceId}`"
                                v-model="newStakeholderRole"
                                :disabled="syncingStakeholders"
                                class="h-7 w-full rounded-[6px] border border-default bg-surface px-2 text-[11px] text-primary"
                            >
                                <option
                                    v-for="role in props.stakeholderRoles"
                                    :key="role"
                                    :value="role"
                                >
                                    {{ formatEnumLabel(role) }}
                                </option>
                            </select>
                        </div>

                        <Button
                            type="submit"
                            variant="secondary"
                            size="sm"
                            class="w-full"
                            :loading="syncingStakeholders"
                            :disabled="!newStakeholderContactId"
                        >
                            <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                            <span>Add Stakeholder</span>
                        </Button>
                    </form>
                </section>

                <!-- Collaborators Panel -->
                <CollaboratorsPanel
                    entity-type="deal"
                    :entity-id="localDeal.id"
                    :collaborators="props.collaborators"
                    :assignable-users="props.assignableUsers"
                    :access-levels="props.collaboratorAccessLevels"
                    :role-labels="props.collaboratorRoleLabels"
                    :can-manage="props.can.manageCollaborators"
                />

                <!-- Clean Attachments -->
                <section
                    aria-label="Clean attachments"
                    class="rounded-[8px] border border-default bg-surface p-3.5 space-y-2.5"
                >
                    <div class="flex items-center justify-between">
                        <h3 class="flex items-center gap-1.5 text-[13px] font-semibold text-primary">
                            <Paperclip class="h-3.5 w-3.5 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                            <span>Attachments</span>
                        </h3>
                        <Badge variant="neutral">{{ props.attachments.length }}</Badge>
                    </div>

                    <p v-if="props.attachments.length === 0" class="rounded-[6px] border border-dashed border-default px-3 py-3 text-[12px] text-muted">
                        No clean scanned attachments linked.
                    </p>

                    <ul v-else class="divide-y divide-default rounded-[6px] border border-default">
                        <li
                            v-for="file in props.attachments"
                            :key="file.id"
                            class="flex items-center justify-between gap-2 px-2.5 py-2 text-[12px]"
                        >
                            <div class="min-w-0">
                                <p class="truncate font-medium text-primary">{{ file.original_filename }}</p>
                                <p class="font-mono text-[10px] tabular-nums text-muted">
                                    {{ formatFileSize(file.size_bytes) }}
                                </p>
                            </div>
                            <a
                                :href="file.download_url"
                                class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-[4px] border border-default bg-subtle text-secondary hover:text-primary"
                                :aria-label="`Download ${file.original_filename}`"
                            >
                                <Download class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                            </a>
                        </li>
                    </ul>
                </section>
            </aside>
        </div>

        <!-- Stage Gate Modal -->
        <StageGateModal
            :open="gateModalState !== null"
            :deal-title="localDeal.title"
            :target-stage="gateModalState?.targetStage ?? null"
            :current-deal="{
                amount_cents: localDeal.amount_cents,
                currency: localDeal.currency,
                expected_close_date: localDeal.expected_close_date,
                primary_contact_id: localDeal.primary_contact_id,
                lost_reason: localDeal.lost_reason,
                custom_fields: localDeal.custom_fields,
            }"
            :missing-core-fields="gateModalState?.missingCoreFields ?? []"
            :missing-custom-fields="gateModalState?.missingCustomFields ?? []"
            :custom-field-definitions="props.customFieldDefinitions"
            :assignable-users="props.assignableUsers"
            :contacts="props.contacts"
            :server-errors="gateModalState?.errors ?? {}"
            :error-message="gateModalState?.errorMessage ?? ''"
            :can-override-gates="props.can.overrideGates"
            :submitting="gateSubmitting"
            @update:open="!$event ? (gateModalState = null) : undefined"
            @submit="handleGateSubmit"
        />

        <!-- Delete Deal Confirmation Dialog -->
        <Dialog
            :open="deleteDialogOpen"
            title="Delete Deal"
            :description="`Are you sure you want to delete ${localDeal.title}?`"
            max-width-class="max-w-md"
            @update:open="deleteDialogOpen = $event"
        >
            <div class="space-y-4">
                <div
                    v-if="deleteError"
                    role="alert"
                    class="rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                >
                    {{ deleteError }}
                </div>
                <div class="flex items-center justify-end gap-2">
                    <Button variant="secondary" :disabled="deleting" @click="deleteDialogOpen = false">
                        Cancel
                    </Button>
                    <Button variant="danger" :loading="deleting" @click="handleDeleteDeal">
                        Confirm Delete
                    </Button>
                </div>
            </div>
        </Dialog>
    </div>
</template>
