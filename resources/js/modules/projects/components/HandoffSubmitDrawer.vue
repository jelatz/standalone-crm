<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import {
    AlertCircle,
    AlertTriangle,
    CheckSquare,
    Plus,
    Trash2,
    X,
} from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import RichNoteEditor, { type ProseMirrorDoc } from '@/components/editor/RichNoteEditor.vue';
import {
    extractFirstFieldErrors,
    projectsApi,
    ValidationRequestError,
} from '@/modules/projects/api/projectsApi';
import { hasTipTapContent } from '@/modules/projects/utils/projectFormatters';
import { formatMoneyCents } from '@/modules/crm/utils/formatters';

export interface HandoffDealTarget {
    id: string;
    title: string;
    amount_cents?: number | null;
    currency?: string | null;
    company_name?: string | null;
    account_manager_id?: string | null;
}

interface ChecklistRow {
    id: string;
    label: string;
    checked: boolean;
}

const DEFAULT_CHECKLIST_ITEMS: Array<{ label: string; checked: boolean }> = [
    { label: 'Signed SOW / MSA attached', checked: false },
    { label: 'Billing & technical contacts confirmed', checked: false },
    { label: 'Target delivery scope verified', checked: false },
];

const props = withDefaults(
    defineProps<{
        open: boolean;
        deal: HandoffDealTarget | null;
        existingHandoff?: App.Modules.Projects.Handoffs.Data.DealHandoffDetailData | null;
        assignableAms?: App.Modules.Core.Users.Data.UserSummaryData[];
        portalDisabled?: boolean;
    }>(),
    {
        existingHandoff: null,
        assignableAms: () => [],
        portalDisabled: false,
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
    submitted: [handoff: App.Modules.Projects.Handoffs.Data.DealHandoffDetailData];
}>();

const instanceId = useId();
const kickoffDateId = `handoff-kickoff-${instanceId}`;
const assignedAmId = `handoff-am-${instanceId}`;
const newChecklistInputId = `handoff-new-check-${instanceId}`;

const isResubmitMode = computed(
    () =>
        props.existingHandoff !== null &&
        props.existingHandoff.status === 'changes_requested',
);

const scopeEditorRef = ref<InstanceType<typeof RichNoteEditor> | null>(null);
const initialScopeDoc = ref<ProseMirrorDoc | null>(null);
const scopeNotesDoc = ref<ProseMirrorDoc>({
    type: 'doc',
    content: [{ type: 'paragraph' }],
});
const editorResetKey = ref<number>(0);

const checklist = ref<ChecklistRow[]>([]);
const newChecklistLabel = ref<string>('');
const targetKickoffDate = ref<string>('');
const selectedAmId = ref<string>('');

const submitting = ref<boolean>(false);
const errorBanner = ref<string>('');
const fieldErrors = ref<Record<string, string>>({});

let checklistCounter = 0;

function makeChecklistRow(label: string, checked = false): ChecklistRow {
    checklistCounter += 1;
    return {
        id: `chk-${instanceId}-${checklistCounter}`,
        label,
        checked,
    };
}

function hydrateFromProps(): void {
    errorBanner.value = '';
    fieldErrors.value = {};
    newChecklistLabel.value = '';

    if (props.existingHandoff) {
        initialScopeDoc.value = props.existingHandoff.scope_notes_json;
        scopeNotesDoc.value = props.existingHandoff.scope_notes_json;
        checklist.value =
            props.existingHandoff.handoff_checklist.length > 0
                ? props.existingHandoff.handoff_checklist.map((item) =>
                      makeChecklistRow(item.label, Boolean(item.checked)),
                  )
                : DEFAULT_CHECKLIST_ITEMS.map((item) =>
                      makeChecklistRow(item.label, item.checked),
                  );
        targetKickoffDate.value = props.existingHandoff.target_kickoff_date ?? '';
        selectedAmId.value =
            props.existingHandoff.assigned_am?.id ??
            props.deal?.account_manager_id ??
            '';
    } else {
        const emptyDoc: ProseMirrorDoc = { type: 'doc', content: [{ type: 'paragraph' }] };
        initialScopeDoc.value = emptyDoc;
        scopeNotesDoc.value = emptyDoc;
        checklist.value = DEFAULT_CHECKLIST_ITEMS.map((item) =>
            makeChecklistRow(item.label, item.checked),
        );
        targetKickoffDate.value = '';
        selectedAmId.value = props.deal?.account_manager_id ?? '';
    }

    editorResetKey.value += 1;
}

watch(
    () => [props.open, props.deal?.id, props.existingHandoff?.id] as const,
    ([isOpen]) => {
        if (isOpen) {
            hydrateFromProps();
        }
    },
    { immediate: true },
);

function addChecklistItem(): void {
    const trimmed = newChecklistLabel.value.trim();
    if (!trimmed || checklist.value.length >= 50) {
        return;
    }

    checklist.value = [...checklist.value, makeChecklistRow(trimmed, false)];
    newChecklistLabel.value = '';
}

function removeChecklistItem(id: string): void {
    checklist.value = checklist.value.filter((item) => item.id !== id);
}

function resolveCurrentScopeDoc(): ProseMirrorDoc {
    const fromEditor = scopeEditorRef.value?.getDocumentJson?.();
    if (fromEditor && typeof fromEditor === 'object') {
        return fromEditor;
    }
    return scopeNotesDoc.value;
}

async function handleSubmit(): Promise<void> {
    if (submitting.value || !props.deal) {
        return;
    }

    errorBanner.value = '';
    fieldErrors.value = {};

    const doc = resolveCurrentScopeDoc();
    if (!hasTipTapContent(doc)) {
        fieldErrors.value = {
            scope_notes_json: 'Scope notes are required before submitting a handoff.',
        };
        errorBanner.value = 'Enter delivery scope notes before submitting this handoff.';
        return;
    }

    const cleanedChecklist = checklist.value
        .map((item) => ({
            label: item.label.trim(),
            checked: Boolean(item.checked),
        }))
        .filter((item) => item.label !== '');

    if (cleanedChecklist.length !== checklist.value.length) {
        fieldErrors.value = {
            handoff_checklist: 'Remove or label any empty checklist items.',
        };
        errorBanner.value = 'Every checklist item must include a label.';
        return;
    }

    const payload: App.Modules.Projects.Handoffs.Data.SubmitHandoffData = {
        scope_notes_json: doc,
        handoff_checklist: cleanedChecklist,
        target_kickoff_date: targetKickoffDate.value.trim() || null,
        assigned_am_id: selectedAmId.value.trim() || null,
    };

    submitting.value = true;

    try {
        const result = isResubmitMode.value && props.existingHandoff
            ? await projectsApi.resubmitHandoff(props.existingHandoff.id, payload)
            : await projectsApi.submitHandoff(props.deal.id, payload);

        emit('submitted', result);
        emit('update:open', false);
    } catch (error) {
        if (error instanceof ValidationRequestError) {
            fieldErrors.value = extractFirstFieldErrors(error.errors);
            errorBanner.value = error.message;
        } else {
            errorBanner.value =
                error instanceof Error ? error.message : 'Unable to submit deal handoff.';
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <DialogRoot :open="props.open" @update:open="emit('update:open', $event)">
        <DialogPortal :disabled="props.portalDisabled">
            <DialogOverlay
                class="fixed inset-0 z-50 bg-black/45 transition-opacity duration-150 ease-out"
            />
            <DialogContent
                data-testid="handoff-submit-drawer"
                class="fixed inset-y-0 right-0 z-50 flex w-full max-w-[520px] flex-col border-l border-default bg-surface text-primary shadow-xl focus:outline-none"
            >
                <!-- Drawer Header -->
                <header class="flex items-start justify-between gap-3 border-b border-default px-4 py-3">
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <DialogTitle class="text-[15px] font-semibold tracking-[-0.01em] text-primary">
                                {{ isResubmitMode ? 'Resubmit Deal Handoff' : 'Submit Deal Handoff' }}
                            </DialogTitle>
                            <Badge variant="success">Won Deal</Badge>
                        </div>
                        <DialogDescription class="truncate text-[12px] text-secondary">
                            {{ props.deal?.title ?? 'Deal' }}
                            <template v-if="props.deal?.company_name">
                                · {{ props.deal.company_name }}
                            </template>
                            <template v-if="props.deal?.amount_cents !== undefined && props.deal?.amount_cents !== null">
                                · {{ formatMoneyCents(props.deal.amount_cents, props.deal.currency ?? 'USD') }}
                            </template>
                        </DialogDescription>
                    </div>

                    <DialogClose
                        type="button"
                        aria-label="Close handoff drawer"
                        :disabled="submitting"
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-[6px] text-secondary transition-colors duration-150 hover:bg-subtle hover:text-primary cursor-pointer"
                    >
                        <X class="h-4 w-4" :stroke-width="1.75" aria-hidden="true" />
                    </DialogClose>
                </header>

                <!-- Drawer Body -->
                <form
                    id="handoff-submit-form"
                    data-testid="handoff-submit-form"
                    class="flex-1 overflow-y-auto px-4 py-4 space-y-4"
                    @submit.prevent="handleSubmit"
                >
                    <!-- Previous Reviewer Feedback Banner (Resubmit Mode) -->
                    <div
                        v-if="isResubmitMode && props.existingHandoff?.review_feedback"
                        role="status"
                        data-testid="handoff-resubmit-feedback"
                        class="flex items-start gap-2 rounded-[6px] border border-status-warning/40 bg-status-warning/10 px-3 py-2.5 text-[12px] text-primary"
                    >
                        <AlertTriangle
                            class="mt-0.5 h-4 w-4 shrink-0 text-status-warning"
                            :stroke-width="1.75"
                            aria-hidden="true"
                        />
                        <div>
                            <p class="font-semibold">Changes requested by reviewer</p>
                            <p class="mt-0.5 text-secondary">
                                {{ props.existingHandoff.review_feedback }}
                            </p>
                        </div>
                    </div>

                    <!-- Error Banner -->
                    <div
                        v-if="errorBanner"
                        role="alert"
                        data-testid="handoff-submit-error"
                        class="flex items-start gap-2 rounded-[6px] border border-status-danger/35 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                    >
                        <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                        <span>{{ errorBanner }}</span>
                    </div>

                    <!-- Scope Notes (TipTap RichNoteEditor) -->
                    <div class="space-y-1.5">
                        <label class="block text-[12px] font-medium text-secondary">
                            Delivery Scope &amp; Technical Notes <span class="text-status-danger">*</span>
                        </label>
                        <RichNoteEditor
                            ref="scopeEditorRef"
                            :hide-footer="true"
                            :allow-pin="false"
                            :initial-document="initialScopeDoc"
                            :reset-key="editorResetKey"
                            :disabled="submitting"
                            :error-message="fieldErrors.scope_notes_json ?? ''"
                            placeholder="Outline contracted deliverables, environment constraints, milestones, and client risks..."
                            @update:document="scopeNotesDoc = $event"
                        />
                    </div>

                    <!-- Kickoff Date & Assigned AM -->
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label
                                :for="kickoffDateId"
                                class="mb-1 block text-[12px] font-medium text-secondary"
                            >
                                Target Kickoff Date
                            </label>
                            <Input
                                :id="kickoffDateId"
                                v-model="targetKickoffDate"
                                type="date"
                                class="font-mono tabular-nums"
                                :disabled="submitting"
                                :error-message="fieldErrors.target_kickoff_date ?? ''"
                            />
                        </div>

                        <div>
                            <label
                                :for="assignedAmId"
                                class="mb-1 block text-[12px] font-medium text-secondary"
                            >
                                Assigned Account Manager
                            </label>
                            <select
                                :id="assignedAmId"
                                v-model="selectedAmId"
                                :disabled="submitting"
                                :aria-invalid="fieldErrors.assigned_am_id ? 'true' : undefined"
                                :class="[
                                    'h-8 w-full rounded-[6px] border bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50',
                                    fieldErrors.assigned_am_id ? 'border-status-danger' : 'border-default',
                                ]"
                            >
                                <option value="">Unassigned</option>
                                <option
                                    v-for="user in props.assignableAms"
                                    :key="user.id"
                                    :value="user.id"
                                >
                                    {{ user.name }} ({{ user.employee_code }})
                                </option>
                            </select>
                            <p
                                v-if="fieldErrors.assigned_am_id"
                                role="alert"
                                class="mt-1 text-[12px] text-status-danger"
                            >
                                {{ fieldErrors.assigned_am_id }}
                            </p>
                        </div>
                    </div>

                    <!-- Dynamic Handoff Checklist -->
                    <section class="space-y-2 rounded-[8px] border border-default bg-subtle/40 p-3">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="flex items-center gap-1.5 text-[12px] font-semibold text-primary">
                                <CheckSquare class="h-3.5 w-3.5 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                                <span>Handoff Checklist</span>
                            </h3>
                            <span class="font-mono text-[11px] tabular-nums text-muted">
                                {{ checklist.filter((i) => i.checked).length }} / {{ checklist.length }}
                            </span>
                        </div>

                        <p
                            v-if="fieldErrors.handoff_checklist"
                            role="alert"
                            class="text-[12px] text-status-danger"
                        >
                            {{ fieldErrors.handoff_checklist }}
                        </p>

                        <ul v-if="checklist.length > 0" class="space-y-1.5">
                            <li
                                v-for="(item, idx) in checklist"
                                :key="item.id"
                                class="flex items-center gap-2 rounded-[6px] border border-default bg-surface px-2.5 py-1.5"
                            >
                                <input
                                    :id="`${item.id}-check`"
                                    v-model="item.checked"
                                    type="checkbox"
                                    :disabled="submitting"
                                    :aria-label="`Mark checklist item ${idx + 1} complete`"
                                    class="h-3.5 w-3.5 shrink-0 rounded border-default accent-accent-primary"
                                />
                                <label :for="`${item.id}-label`" class="sr-only">
                                    Checklist item {{ idx + 1 }}
                                </label>
                                <input
                                    :id="`${item.id}-label`"
                                    v-model="item.label"
                                    type="text"
                                    maxlength="255"
                                    :disabled="submitting"
                                    class="min-w-0 flex-1 bg-transparent text-[12px] text-primary placeholder:text-muted focus:outline-none"
                                    placeholder="Checklist requirement..."
                                />
                                <button
                                    type="button"
                                    :aria-label="`Remove checklist item ${item.label || idx + 1}`"
                                    :disabled="submitting"
                                    class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-[4px] text-muted hover:bg-status-danger/10 hover:text-status-danger cursor-pointer disabled:opacity-40"
                                    @click="removeChecklistItem(item.id)"
                                >
                                    <Trash2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                </button>
                            </li>
                        </ul>

                        <div class="flex items-center gap-2 pt-1">
                            <label :for="newChecklistInputId" class="sr-only">
                                Add handoff checklist item
                            </label>
                            <div class="min-w-0 flex-1">
                                <Input
                                    :id="newChecklistInputId"
                                    v-model="newChecklistLabel"
                                    placeholder="Add checklist item (e.g. Security questionnaire cleared)"
                                    :disabled="submitting || checklist.length >= 50"
                                    @keydown.enter.prevent="addChecklistItem"
                                />
                            </div>
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                :disabled="submitting || !newChecklistLabel.trim() || checklist.length >= 50"
                                data-testid="handoff-add-checklist-btn"
                                @click="addChecklistItem"
                            >
                                <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                <span>Add</span>
                            </Button>
                        </div>
                    </section>
                </form>

                <!-- Drawer Footer -->
                <footer class="flex items-center justify-end gap-2 border-t border-default bg-surface px-4 py-3">
                    <Button
                        type="button"
                        variant="secondary"
                        size="md"
                        :disabled="submitting"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        form="handoff-submit-form"
                        variant="primary"
                        size="md"
                        :loading="submitting"
                        data-testid="handoff-submit-btn"
                    >
                        {{ isResubmitMode ? 'Resubmit Handoff' : 'Submit Handoff' }}
                    </Button>
                </footer>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
