<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import { AlertCircle, ArrowRightLeft } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Input from '@/components/ui/Input.vue';
import {
    crmApi,
    extractFirstFieldErrors,
    ValidationRequestError,
} from '@/modules/crm/api/crmApi';

const props = defineProps<{
    open: boolean;
    lead: App.Modules.Crm.Leads.Data.LeadDetailData;
    companies: Array<{ id: string; name: string; domain: string | null }>;
    pipelines: App.Modules.Crm.Pipelines.Data.PipelineData[];
    canCreateCompany: boolean;
    canCreateDeal: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    converted: [result: App.Modules.Crm.Leads.Data.ConvertLeadResultData];
}>();

const instanceId = useId();
const submitting = ref<boolean>(false);
const errorBanner = ref<string>('');
const fieldErrors = ref<Record<string, string>>({});

function pickDefaultPipelineId(): string {
    const defaultPipeline = props.pipelines.find((p) => p.is_default) ?? props.pipelines[0];
    return defaultPipeline?.id ?? '';
}

function getOpenStagesForPipeline(pipelineId: string): App.Modules.Crm.Pipelines.Data.PipelineStageData[] {
    const pipeline = props.pipelines.find((p) => p.id === pipelineId) ?? props.pipelines[0];
    if (!pipeline) {
        return [];
    }
    const openOnly = pipeline.stages.filter((s) => s.stage_type === 'open');
    return openOnly.length > 0 ? openOnly : pipeline.stages;
}

function buildInitialForm() {
    const defaultCompanyName = props.lead.company_name?.trim() || props.lead.full_name;
    const defaultPipelineId = pickDefaultPipelineId();
    const defaultStages = getOpenStagesForPipeline(defaultPipelineId);
    const defaultCompanyMode = props.canCreateCompany ? 'create' : 'existing';
    const amountDollars =
        props.lead.estimated_value_cents !== null && props.lead.estimated_value_cents !== undefined
            ? String((props.lead.estimated_value_cents / 100).toFixed(2).replace(/\.00$/, ''))
            : '0';

    return {
        company_mode: defaultCompanyMode as 'create' | 'existing',
        existing_company_id: props.companies[0]?.id ?? '',
        company_name: defaultCompanyName,
        company_domain: props.lead.website ?? '',
        is_primary_contact: true,
        create_deal: props.canCreateDeal,
        deal_title: `${defaultCompanyName} — Initial Opportunity`,
        pipeline_id: defaultPipelineId,
        stage_id: defaultStages[0]?.id ?? '',
        amount_dollars: amountDollars,
        currency: (props.lead.currency || 'USD').toUpperCase(),
        expected_close_date: '',
        win_probability: '',
    };
}

const form = ref(buildInitialForm());

const openStages = computed(() => getOpenStagesForPipeline(form.value.pipeline_id));

const selectedStage = computed(
    () => openStages.value.find((s) => s.id === form.value.stage_id) ?? openStages.value[0] ?? null,
);

const canUseExistingCompany = computed(() => props.companies.length > 0);
const cannotProceedCompany = computed(
    () => !props.canCreateCompany && props.companies.length === 0,
);

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            errorBanner.value = '';
            fieldErrors.value = {};
            form.value = buildInitialForm();
        }
    },
);

watch(
    () => form.value.pipeline_id,
    (nextPipelineId) => {
        const stages = getOpenStagesForPipeline(nextPipelineId);
        if (!stages.some((stage) => stage.id === form.value.stage_id)) {
            form.value.stage_id = stages[0]?.id ?? '';
        }
    },
);

function handleOpenChange(nextOpen: boolean): void {
    if (!nextOpen && submitting.value) {
        return;
    }
    emit('update:open', nextOpen);
}

async function handleSubmit(): Promise<void> {
    if (submitting.value || cannotProceedCompany.value) {
        return;
    }

    submitting.value = true;
    errorBanner.value = '';
    fieldErrors.value = {};

    const amountRaw = form.value.amount_dollars.trim();
    const amountCents =
        amountRaw !== '' && Number.isFinite(Number(amountRaw))
            ? Math.max(0, Math.round(Number(amountRaw) * 100))
            : 0;

    const winProbRaw = form.value.win_probability.trim();
    const winProbability =
        winProbRaw !== '' && Number.isFinite(Number(winProbRaw))
            ? Math.round(Number(winProbRaw))
            : null;

    const createDeal = props.canCreateDeal && form.value.create_deal;

    const payload: App.Modules.Crm.Leads.Data.ConvertLeadData = {
        company_mode: form.value.company_mode,
        existing_company_id:
            form.value.company_mode === 'existing' ? form.value.existing_company_id || null : null,
        company_name:
            form.value.company_mode === 'create' ? form.value.company_name.trim() || null : null,
        company_domain:
            form.value.company_mode === 'create' ? form.value.company_domain.trim() || null : null,
        is_primary_contact: form.value.is_primary_contact,
        create_deal: createDeal,
        deal_title: createDeal ? form.value.deal_title.trim() || null : null,
        pipeline_id: createDeal ? form.value.pipeline_id || null : null,
        stage_id: createDeal ? form.value.stage_id || null : null,
        amount_cents: amountCents,
        currency: form.value.currency.trim().toUpperCase() || 'USD',
        expected_close_date: createDeal ? form.value.expected_close_date.trim() || null : null,
        win_probability: createDeal ? winProbability : null,
    };

    try {
        const result = await crmApi.convertLead(props.lead.id, payload);
        emit('converted', result);
        emit('update:open', false);
    } catch (error) {
        if (error instanceof ValidationRequestError) {
            fieldErrors.value = extractFirstFieldErrors(error.errors);
            errorBanner.value = error.message;
        } else {
            errorBanner.value =
                error instanceof Error ? error.message : 'Unable to convert lead.';
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Dialog
        :open="props.open"
        title="Convert Lead"
        :description="`Convert ${props.lead.full_name} into a Company, Contact, and optional initial Deal.`"
        max-width-class="max-w-xl"
        @update:open="handleOpenChange"
    >
        <form data-testid="convert-lead-form" class="space-y-4" @submit.prevent="handleSubmit">
            <div class="max-h-[72vh] space-y-4 overflow-y-auto pr-0.5">
                <div
                    v-if="errorBanner"
                    role="alert"
                    data-testid="convert-lead-error"
                    class="flex items-start gap-2 rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                >
                    <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                    <span>{{ errorBanner }}</span>
                </div>

                <div
                    v-if="cannotProceedCompany"
                    role="alert"
                    class="rounded-[6px] border border-status-warning/30 bg-status-warning/10 px-3 py-2 text-[12px] text-status-warning"
                >
                    You do not have permission to create a new company and no existing visible companies are available to link.
                </div>

                <!-- Section 1: Account & Company -->
                <section class="space-y-3">
                    <div class="flex items-center justify-between border-b border-default pb-1.5">
                        <h3 class="text-[12px] font-semibold uppercase tracking-wider text-primary">
                            Account &amp; Company
                        </h3>
                        <span class="text-[11px] text-muted">Target organization</span>
                    </div>

                    <fieldset class="space-y-2">
                        <legend class="sr-only">Company destination mode</legend>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <label
                                :for="`convert-mode-create-${instanceId}`"
                                :class="[
                                    'flex items-start gap-2.5 rounded-[6px] border p-2.5 transition-colors duration-150',
                                    !props.canCreateCompany
                                        ? 'cursor-not-allowed border-default bg-subtle/50 opacity-50'
                                        : form.company_mode === 'create'
                                          ? 'cursor-pointer border-accent-primary bg-accent-primary/5'
                                          : 'cursor-pointer border-default bg-surface hover:bg-subtle/60',
                                ]"
                            >
                                <input
                                    :id="`convert-mode-create-${instanceId}`"
                                    v-model="form.company_mode"
                                    type="radio"
                                    name="company_mode"
                                    value="create"
                                    :disabled="submitting || !props.canCreateCompany"
                                    class="mt-0.5 h-3.5 w-3.5 accent-accent-primary"
                                />
                                <span class="min-w-0">
                                    <span class="block text-[12px] font-semibold text-primary">
                                        Create new company
                                    </span>
                                    <span class="block text-[11px] text-secondary">
                                        Provision a prospect company record
                                    </span>
                                </span>
                            </label>

                            <label
                                :for="`convert-mode-existing-${instanceId}`"
                                :class="[
                                    'flex items-start gap-2.5 rounded-[6px] border p-2.5 transition-colors duration-150',
                                    !canUseExistingCompany
                                        ? 'cursor-not-allowed border-default bg-subtle/50 opacity-50'
                                        : form.company_mode === 'existing'
                                          ? 'cursor-pointer border-accent-primary bg-accent-primary/5'
                                          : 'cursor-pointer border-default bg-surface hover:bg-subtle/60',
                                ]"
                            >
                                <input
                                    :id="`convert-mode-existing-${instanceId}`"
                                    v-model="form.company_mode"
                                    type="radio"
                                    name="company_mode"
                                    value="existing"
                                    :disabled="submitting || !canUseExistingCompany"
                                    class="mt-0.5 h-3.5 w-3.5 accent-accent-primary"
                                />
                                <span class="min-w-0">
                                    <span class="block text-[12px] font-semibold text-primary">
                                        Link existing company
                                    </span>
                                    <span class="block text-[11px] text-secondary">
                                        {{
                                            canUseExistingCompany
                                                ? `${props.companies.length} visible companies`
                                                : 'No visible companies available'
                                        }}
                                    </span>
                                </span>
                            </label>
                        </div>
                    </fieldset>

                    <div v-if="form.company_mode === 'create'" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label :for="`convert-company-name-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Company Name <span class="text-status-danger">*</span>
                            </label>
                            <Input
                                :id="`convert-company-name-${instanceId}`"
                                v-model="form.company_name"
                                placeholder="Northwind Logistics"
                                required
                                :disabled="submitting"
                                :error-message="fieldErrors.company_name ?? ''"
                            />
                        </div>

                        <div>
                            <label :for="`convert-company-domain-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Company Domain
                            </label>
                            <Input
                                :id="`convert-company-domain-${instanceId}`"
                                v-model="form.company_domain"
                                :spellcheck="false"
                                placeholder="northwind.internal"
                                class="font-mono"
                                :disabled="submitting"
                                :error-message="fieldErrors.company_domain ?? ''"
                            />
                        </div>
                    </div>

                    <div v-else>
                        <label :for="`convert-existing-company-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Select Existing Company <span class="text-status-danger">*</span>
                        </label>
                        <select
                            :id="`convert-existing-company-${instanceId}`"
                            v-model="form.existing_company_id"
                            required
                            :disabled="submitting || !canUseExistingCompany"
                            :aria-invalid="fieldErrors.existing_company_id ? 'true' : undefined"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                        >
                            <option value="">Choose a company...</option>
                            <option
                                v-for="company in props.companies"
                                :key="company.id"
                                :value="company.id"
                            >
                                {{ company.name }}{{ company.domain ? ` (${company.domain})` : '' }}
                            </option>
                        </select>
                        <p v-if="fieldErrors.existing_company_id" role="alert" class="mt-1 text-[12px] text-status-danger">
                            {{ fieldErrors.existing_company_id }}
                        </p>
                    </div>
                </section>

                <!-- Section 2: Contact Record Summary -->
                <section class="space-y-2.5">
                    <div class="flex items-center justify-between border-b border-default pb-1.5">
                        <h3 class="text-[12px] font-semibold uppercase tracking-wider text-primary">
                            Contact Record
                        </h3>
                        <span class="text-[11px] text-muted">Created or matched by email</span>
                    </div>

                    <div class="rounded-[6px] border border-default bg-subtle/60 px-3 py-2.5 space-y-1">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-[13px] font-semibold text-primary">
                                {{ props.lead.full_name }}
                            </span>
                            <span v-if="props.lead.job_title" class="text-[12px] text-secondary">
                                {{ props.lead.job_title }}
                            </span>
                        </div>
                        <p class="font-mono text-[12px] text-secondary">
                            {{ props.lead.email || 'No email provided' }}
                            <span v-if="props.lead.phone"> · {{ props.lead.phone }}</span>
                        </p>
                    </div>

                    <label
                        :for="`convert-primary-contact-${instanceId}`"
                        class="flex items-center gap-2 text-[12px] font-medium text-primary cursor-pointer"
                    >
                        <input
                            :id="`convert-primary-contact-${instanceId}`"
                            v-model="form.is_primary_contact"
                            type="checkbox"
                            :disabled="submitting"
                            class="h-3.5 w-3.5 rounded border-default accent-accent-primary"
                        />
                        <span>Set as primary company contact</span>
                    </label>
                </section>

                <!-- Section 3: Optional Initial Deal -->
                <section v-if="props.canCreateDeal" class="space-y-3">
                    <div class="flex items-center justify-between border-b border-default pb-1.5">
                        <h3 class="text-[12px] font-semibold uppercase tracking-wider text-primary">
                            Initial Deal
                        </h3>
                        <label
                            :for="`convert-create-deal-${instanceId}`"
                            class="inline-flex items-center gap-2 text-[12px] font-medium text-primary cursor-pointer"
                        >
                            <input
                                :id="`convert-create-deal-${instanceId}`"
                                v-model="form.create_deal"
                                type="checkbox"
                                :disabled="submitting"
                                class="h-3.5 w-3.5 rounded border-default accent-accent-primary"
                            />
                            <span>Create initial deal</span>
                        </label>
                    </div>

                    <div v-if="form.create_deal" class="space-y-3">
                        <div>
                            <label :for="`convert-deal-title-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Deal Title <span class="text-status-danger">*</span>
                            </label>
                            <Input
                                :id="`convert-deal-title-${instanceId}`"
                                v-model="form.deal_title"
                                placeholder="Northwind Logistics — Initial Opportunity"
                                required
                                :disabled="submitting"
                                :error-message="fieldErrors.deal_title ?? ''"
                            />
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label :for="`convert-deal-pipeline-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                    Pipeline
                                </label>
                                <select
                                    :id="`convert-deal-pipeline-${instanceId}`"
                                    v-model="form.pipeline_id"
                                    :disabled="submitting"
                                    :aria-invalid="fieldErrors.pipeline_id ? 'true' : undefined"
                                    class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                                >
                                    <option
                                        v-for="pipeline in props.pipelines"
                                        :key="pipeline.id"
                                        :value="pipeline.id"
                                    >
                                        {{ pipeline.name }}
                                    </option>
                                </select>
                                <p v-if="fieldErrors.pipeline_id" role="alert" class="mt-1 text-[12px] text-status-danger">
                                    {{ fieldErrors.pipeline_id }}
                                </p>
                            </div>

                            <div>
                                <label :for="`convert-deal-stage-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                    Starting Stage
                                </label>
                                <select
                                    :id="`convert-deal-stage-${instanceId}`"
                                    v-model="form.stage_id"
                                    :disabled="submitting"
                                    :aria-invalid="fieldErrors.stage_id ? 'true' : undefined"
                                    class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                                >
                                    <option
                                        v-for="stage in openStages"
                                        :key="stage.id"
                                        :value="stage.id"
                                    >
                                        {{ stage.name }} ({{ stage.win_probability }}%)
                                    </option>
                                </select>
                                <p v-if="fieldErrors.stage_id" role="alert" class="mt-1 text-[12px] text-status-danger">
                                    {{ fieldErrors.stage_id }}
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div>
                                <label :for="`convert-deal-amount-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                    Amount
                                </label>
                                <Input
                                    :id="`convert-deal-amount-${instanceId}`"
                                    v-model="form.amount_dollars"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="font-mono tabular-nums"
                                    :disabled="submitting"
                                    :error-message="fieldErrors.amount_cents ?? ''"
                                />
                            </div>

                            <div>
                                <label :for="`convert-deal-currency-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                    Currency
                                </label>
                                <Input
                                    :id="`convert-deal-currency-${instanceId}`"
                                    v-model="form.currency"
                                    maxlength="3"
                                    class="font-mono uppercase"
                                    placeholder="USD"
                                    :disabled="submitting"
                                    :error-message="fieldErrors.currency ?? ''"
                                />
                            </div>

                            <div>
                                <label :for="`convert-deal-prob-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                    Win Probability (%)
                                </label>
                                <Input
                                    :id="`convert-deal-prob-${instanceId}`"
                                    v-model="form.win_probability"
                                    type="number"
                                    min="0"
                                    max="100"
                                    :placeholder="selectedStage ? `Stage default (${selectedStage.win_probability}%)` : '0–100'"
                                    class="font-mono tabular-nums"
                                    :disabled="submitting"
                                    :error-message="fieldErrors.win_probability ?? ''"
                                />
                            </div>
                        </div>

                        <div>
                            <label :for="`convert-deal-close-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Expected Close Date
                            </label>
                            <Input
                                :id="`convert-deal-close-${instanceId}`"
                                v-model="form.expected_close_date"
                                type="date"
                                class="font-mono tabular-nums"
                                :disabled="submitting"
                                :error-message="fieldErrors.expected_close_date ?? ''"
                            />
                        </div>
                    </div>
                </section>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-default pt-3">
                <Button
                    variant="secondary"
                    :disabled="submitting"
                    @click="handleOpenChange(false)"
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    variant="primary"
                    :loading="submitting"
                    :disabled="cannotProceedCompany"
                    data-testid="confirm-convert-lead"
                >
                    <ArrowRightLeft class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>Convert Lead</span>
                </Button>
            </div>
        </form>
    </Dialog>
</template>
