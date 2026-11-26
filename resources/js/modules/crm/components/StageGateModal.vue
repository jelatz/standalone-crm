<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import { AlertCircle, ShieldAlert } from 'lucide-vue-next';
import Badge, { type BadgeVariant } from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Input from '@/components/ui/Input.vue';
import CustomFieldsForm from './CustomFieldsForm.vue';
import { getCoreFieldLabel } from '@/modules/crm/utils/formatters';

export interface StageGateDealSnapshot {
    amount_cents: number;
    currency: string;
    expected_close_date: string | null;
    primary_contact_id: string | null;
    lost_reason: string | null;
    custom_fields: Record<string, any>;
}

export interface StageGateSubmitPayload {
    core_field_updates: Record<string, any>;
    custom_field_updates: Record<string, any>;
    override_gates: boolean;
}

const props = withDefaults(
    defineProps<{
        open: boolean;
        dealTitle: string;
        targetStage: App.Modules.Crm.Pipelines.Data.PipelineStageData | null;
        currentDeal: StageGateDealSnapshot | null;
        missingCoreFields: string[];
        missingCustomFields: string[];
        customFieldDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
        assignableUsers?: App.Modules.Core.Users.Data.UserSummaryData[];
        contacts?: Array<{
            id: string;
            full_name: string;
            primary_email: string | null;
            company_id: string | null;
        }>;
        serverErrors?: Record<string, string>;
        errorMessage?: string;
        canOverrideGates?: boolean;
        submitting?: boolean;
    }>(),
    {
        assignableUsers: () => [],
        contacts: () => [],
        serverErrors: () => ({}),
        errorMessage: '',
        canOverrideGates: false,
        submitting: false,
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
    submit: [payload: StageGateSubmitPayload];
}>();

const instanceId = useId();
const amountInputId = `gate-amount-${instanceId}`;
const closeDateInputId = `gate-close-date-${instanceId}`;
const primaryContactInputId = `gate-primary-contact-${instanceId}`;
const lostReasonInputId = `gate-lost-reason-${instanceId}`;
const overrideCheckboxId = `gate-override-${instanceId}`;

const amountDollars = ref<string>('');
const expectedCloseDate = ref<string>('');
const primaryContactId = ref<string>('');
const lostReason = ref<string>('');
const customFieldDraft = ref<Record<string, any>>({});
const overrideGates = ref<boolean>(false);

function hydrateDraftFromProps(): void {
    const deal = props.currentDeal;
    amountDollars.value =
        deal && deal.amount_cents > 0 ? String((deal.amount_cents / 100).toFixed(2).replace(/\.00$/, '')) : '';
    expectedCloseDate.value = deal?.expected_close_date ?? '';
    primaryContactId.value = deal?.primary_contact_id ?? '';
    lostReason.value = deal?.lost_reason ?? '';

    const nextCustom: Record<string, any> = {};
    for (const key of props.missingCustomFields) {
        if (deal?.custom_fields && key in deal.custom_fields) {
            nextCustom[key] = deal.custom_fields[key];
        }
    }
    customFieldDraft.value = nextCustom;
    overrideGates.value = false;
}

watch(
    () => [props.open, props.targetStage?.id, props.missingCoreFields.join(','), props.missingCustomFields.join(',')],
    ([isOpen]) => {
        if (isOpen) {
            hydrateDraftFromProps();
        }
    },
    { immediate: true },
);

const missingCoreSet = computed(() => new Set(props.missingCoreFields));

const stageBadgeVariant = computed<BadgeVariant>(() => {
    if (props.targetStage?.stage_type === 'won') {
        return 'success';
    }
    if (props.targetStage?.stage_type === 'lost') {
        return 'danger';
    }
    return 'cobalt';
});

const missingSummaryLabels = computed(() => {
    const coreLabels = props.missingCoreFields.map((key) => getCoreFieldLabel(key));
    const customLabels = props.missingCustomFields.map((key) => {
        const def = props.customFieldDefinitions.find((item) => item.key === key);
        return def?.label ?? key;
    });
    return [...coreLabels, ...customLabels];
});

function handleSubmit(): void {
    if (props.submitting) {
        return;
    }

    const coreUpdates: Record<string, any> = {};
    if (missingCoreSet.value.has('amount_cents')) {
        const parsed = Number(amountDollars.value);
        if (amountDollars.value.trim() !== '' && Number.isFinite(parsed) && parsed >= 0) {
            coreUpdates.amount_cents = Math.round(parsed * 100);
        }
    }
    if (missingCoreSet.value.has('expected_close_date') && expectedCloseDate.value.trim() !== '') {
        coreUpdates.expected_close_date = expectedCloseDate.value.trim();
    }
    if (missingCoreSet.value.has('primary_contact_id') && primaryContactId.value.trim() !== '') {
        coreUpdates.primary_contact_id = primaryContactId.value.trim();
    }
    if (missingCoreSet.value.has('lost_reason') && lostReason.value.trim() !== '') {
        coreUpdates.lost_reason = lostReason.value.trim();
    }

    const customUpdates: Record<string, any> = {};
    for (const key of props.missingCustomFields) {
        const val = customFieldDraft.value[key];
        if (val !== undefined && val !== null && val !== '') {
            customUpdates[key] = val;
        }
    }

    emit('submit', {
        core_field_updates: coreUpdates,
        custom_field_updates: customUpdates,
        override_gates: Boolean(props.canOverrideGates && overrideGates.value),
    });
}
</script>

<template>
    <Dialog
        :open="props.open"
        title="Stage Gate Requirements"
        :description="`Complete required fields for ${props.dealTitle} before advancing.`"
        max-width-class="max-w-lg"
        @update:open="emit('update:open', $event)"
    >
        <form
            data-testid="stage-gate-modal-form"
            class="space-y-4"
            @submit.prevent="handleSubmit"
        >
            <!-- Target Stage & Missing Fields Summary -->
            <div class="rounded-[6px] border border-default bg-subtle px-3 py-2.5 space-y-1.5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-[12px] font-medium text-secondary">Target stage</span>
                    <Badge v-if="props.targetStage" :variant="stageBadgeVariant">
                        {{ props.targetStage.name }}
                    </Badge>
                </div>
                <p v-if="missingSummaryLabels.length > 0" class="text-[12px] text-primary">
                    Required before moving:
                    <span class="font-medium">{{ missingSummaryLabels.join(', ') }}</span>
                </p>
            </div>

            <!-- Inline Error Alert -->
            <div
                v-if="props.errorMessage"
                role="alert"
                data-testid="stage-gate-error-alert"
                class="flex items-start gap-2 rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
            >
                <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                <span>{{ props.errorMessage }}</span>
            </div>

            <!-- Missing Core Fields -->
            <div v-if="props.missingCoreFields.length > 0" class="space-y-3">
                <div v-if="missingCoreSet.has('amount_cents')" class="space-y-1">
                    <label
                        :for="amountInputId"
                        class="block text-[12px] font-medium text-secondary"
                    >
                        Deal Amount ({{ props.currentDeal?.currency ?? 'USD' }}) <span class="text-status-danger">*</span>
                    </label>
                    <Input
                        :id="amountInputId"
                        v-model="amountDollars"
                        type="number"
                        placeholder="25000"
                        class="font-mono tabular-nums"
                        :disabled="props.submitting"
                        :error-message="props.serverErrors.amount_cents ?? ''"
                    />
                </div>

                <div v-if="missingCoreSet.has('expected_close_date')" class="space-y-1">
                    <label
                        :for="closeDateInputId"
                        class="block text-[12px] font-medium text-secondary"
                    >
                        Expected Close Date <span class="text-status-danger">*</span>
                    </label>
                    <Input
                        :id="closeDateInputId"
                        v-model="expectedCloseDate"
                        type="date"
                        class="font-mono tabular-nums"
                        :disabled="props.submitting"
                        :error-message="props.serverErrors.expected_close_date ?? ''"
                    />
                </div>

                <div v-if="missingCoreSet.has('primary_contact_id')" class="space-y-1">
                    <label
                        :for="primaryContactInputId"
                        class="block text-[12px] font-medium text-secondary"
                    >
                        Primary Contact <span class="text-status-danger">*</span>
                    </label>
                    <select
                        :id="primaryContactInputId"
                        v-model="primaryContactId"
                        :disabled="props.submitting"
                        :aria-invalid="props.serverErrors.primary_contact_id ? 'true' : undefined"
                        :class="[
                            'h-8 w-full rounded-[6px] border bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-accent-primary disabled:opacity-50',
                            props.serverErrors.primary_contact_id ? 'border-status-danger' : 'border-default',
                        ]"
                    >
                        <option value="">Select primary contact...</option>
                        <option
                            v-for="contact in props.contacts"
                            :key="contact.id"
                            :value="contact.id"
                        >
                            {{ contact.full_name }}{{ contact.primary_email ? ` (${contact.primary_email})` : '' }}
                        </option>
                    </select>
                    <p
                        v-if="props.serverErrors.primary_contact_id"
                        role="alert"
                        class="text-[12px] text-status-danger"
                    >
                        {{ props.serverErrors.primary_contact_id }}
                    </p>
                </div>

                <div v-if="missingCoreSet.has('lost_reason')" class="space-y-1">
                    <label
                        :for="lostReasonInputId"
                        class="block text-[12px] font-medium text-secondary"
                    >
                        Lost Reason <span class="text-status-danger">*</span>
                    </label>
                    <Input
                        :id="lostReasonInputId"
                        v-model="lostReason"
                        placeholder="Budget frozen until Q3, selected incumbent, etc."
                        :disabled="props.submitting"
                        :error-message="props.serverErrors.lost_reason ?? ''"
                    />
                </div>
            </div>

            <!-- Missing Custom Fields -->
            <CustomFieldsForm
                v-if="props.missingCustomFields.length > 0"
                v-model="customFieldDraft"
                :definitions="props.customFieldDefinitions"
                :only-keys="props.missingCustomFields"
                :highlighted-keys="props.missingCustomFields"
                :assignable-users="props.assignableUsers"
                :errors="props.serverErrors"
                :disabled="props.submitting"
                id-prefix="stage-gate-cf"
            />

            <!-- Director/Admin Gate Override (strictly gated) -->
            <div
                v-if="props.canOverrideGates"
                data-testid="stage-gate-override-box"
                class="rounded-[6px] border border-status-warning/35 bg-status-warning/10 p-3"
            >
                <label
                    :for="overrideCheckboxId"
                    class="flex items-start gap-2.5 cursor-pointer"
                >
                    <input
                        :id="overrideCheckboxId"
                        v-model="overrideGates"
                        type="checkbox"
                        :disabled="props.submitting"
                        class="mt-0.5 h-3.5 w-3.5 rounded border-default accent-accent-primary"
                    />
                    <span class="space-y-0.5">
                        <span class="flex items-center gap-1.5 text-[12px] font-semibold text-primary">
                            <ShieldAlert class="h-3.5 w-3.5 text-status-warning" :stroke-width="1.75" aria-hidden="true" />
                            <span>Director / Admin Gate Override</span>
                        </span>
                        <span class="block text-[11px] text-secondary">
                            Advance deal without completing missing stage gates. This override is recorded on the activity timeline.
                        </span>
                    </span>
                </label>
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-2 border-t border-default pt-3">
                <Button
                    variant="secondary"
                    size="md"
                    :disabled="props.submitting"
                    @click="emit('update:open', false)"
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    variant="primary"
                    size="md"
                    :loading="props.submitting"
                    data-testid="stage-gate-submit"
                >
                    Save &amp; Move Stage
                </Button>
            </div>
        </form>
    </Dialog>
</template>
