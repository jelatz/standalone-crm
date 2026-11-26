<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    AlertCircle,
    Edit3,
    Plus,
    SlidersHorizontal,
    Trash2,
} from 'lucide-vue-next';
import Badge, { type BadgeVariant } from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Input from '@/components/ui/Input.vue';
import { crmApi, extractFirstFieldErrors, ValidationRequestError } from '@/modules/crm/api/crmApi';
import { formatEnumLabel, getCoreFieldLabel } from '@/modules/crm/utils/formatters';

const props = defineProps<{
    pipelines: App.Modules.Crm.Pipelines.Data.PipelineData[];
    customFieldDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    allowedCoreFields: string[];
    canManage: boolean;
}>();

const instanceId = useId();
const localPipelines = ref<App.Modules.Crm.Pipelines.Data.PipelineData[]>(
    props.pipelines.map((p) => ({ ...p, stages: [...p.stages] })),
);
const localFields = ref<App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[]>([
    ...props.customFieldDefinitions,
]);

const activeTab = ref<'pipelines' | 'custom_fields'>('pipelines');
const selectedPipelineId = ref<string>(localPipelines.value[0]?.id ?? '');
const globalError = ref<string>('');

const selectedPipeline = computed(
    () => localPipelines.value.find((p) => p.id === selectedPipelineId.value) ?? localPipelines.value[0] ?? null,
);

const dealCustomFields = computed(() =>
    localFields.value.filter(
        (cf) =>
            cf.entity_type === 'deal' &&
            cf.is_active &&
            (cf.pipeline_id === null || cf.pipeline_id === selectedPipeline.value?.id),
    ),
);

function stageBadgeVariant(stageType: App.Modules.Crm.Pipelines.Enums.PipelineStageTypeEnum): BadgeVariant {
    if (stageType === 'won') {
        return 'success';
    }
    if (stageType === 'lost') {
        return 'danger';
    }
    return 'cobalt';
}

// Pipeline Create / Edit Modal
const pipelineModalOpen = ref<boolean>(false);
const editingPipelineId = ref<string | null>(null);
const pipelineSaving = ref<boolean>(false);
const pipelineError = ref<string>('');
const pipelineFieldErrors = ref<Record<string, string>>({});
const pipelineForm = ref({
    name: '',
    slug: '',
    is_default: false,
    is_active: true,
    sort_order: '0',
});

function openCreatePipeline(): void {
    editingPipelineId.value = null;
    pipelineError.value = '';
    pipelineFieldErrors.value = {};
    pipelineForm.value = {
        name: '',
        slug: '',
        is_default: false,
        is_active: true,
        sort_order: String(localPipelines.value.length),
    };
    pipelineModalOpen.value = true;
}

function openEditPipeline(pipeline: App.Modules.Crm.Pipelines.Data.PipelineData): void {
    editingPipelineId.value = pipeline.id;
    pipelineError.value = '';
    pipelineFieldErrors.value = {};
    pipelineForm.value = {
        name: pipeline.name,
        slug: pipeline.slug,
        is_default: pipeline.is_default,
        is_active: pipeline.is_active,
        sort_order: String(pipeline.sort_order),
    };
    pipelineModalOpen.value = true;
}

async function handleSavePipeline(): Promise<void> {
    if (!props.canManage || pipelineSaving.value) {
        return;
    }

    pipelineSaving.value = true;
    pipelineError.value = '';
    pipelineFieldErrors.value = {};

    const payload: App.Modules.Crm.Pipelines.Data.UpsertPipelineData = {
        name: pipelineForm.value.name.trim(),
        slug: pipelineForm.value.slug.trim() || null,
        department_id: undefined,
        is_default: pipelineForm.value.is_default,
        is_active: pipelineForm.value.is_active,
        sort_order: Number(pipelineForm.value.sort_order) || 0,
    };

    try {
        if (editingPipelineId.value) {
            const updated = await crmApi.updatePipeline(editingPipelineId.value, payload);
            localPipelines.value = localPipelines.value.map((p) => (p.id === updated.id ? updated : p));
        } else {
            const created = await crmApi.createPipeline(payload);
            localPipelines.value = [...localPipelines.value, created];
            selectedPipelineId.value = created.id;
        }
        pipelineModalOpen.value = false;
    } catch (error) {
        if (error instanceof ValidationRequestError) {
            pipelineFieldErrors.value = extractFirstFieldErrors(error.errors);
            pipelineError.value = error.message;
        } else {
            pipelineError.value = error instanceof Error ? error.message : 'Unable to save pipeline.';
        }
    } finally {
        pipelineSaving.value = false;
    }
}

// Stage Create / Edit Modal
const stageModalOpen = ref<boolean>(false);
const editingStageId = ref<string | null>(null);
const stageSaving = ref<boolean>(false);
const stageError = ref<string>('');
const stageFieldErrors = ref<Record<string, string>>({});
const stageForm = ref({
    name: '',
    stage_type: 'open' as App.Modules.Crm.Pipelines.Enums.PipelineStageTypeEnum,
    win_probability: '25',
    rotting_days: '',
    sort_order: '0',
    required_core_fields: [] as string[],
    required_custom_field_ids: [] as string[],
});

function openCreateStage(): void {
    if (!selectedPipeline.value) {
        return;
    }
    editingStageId.value = null;
    stageError.value = '';
    stageFieldErrors.value = {};
    stageForm.value = {
        name: '',
        stage_type: 'open',
        win_probability: '25',
        rotting_days: '',
        sort_order: String(selectedPipeline.value.stages.length),
        required_core_fields: [],
        required_custom_field_ids: [],
    };
    stageModalOpen.value = true;
}

function openEditStage(stage: App.Modules.Crm.Pipelines.Data.PipelineStageData): void {
    editingStageId.value = stage.id;
    stageError.value = '';
    stageFieldErrors.value = {};
    stageForm.value = {
        name: stage.name,
        stage_type: stage.stage_type,
        win_probability: String(stage.win_probability),
        rotting_days: stage.rotting_days !== null ? String(stage.rotting_days) : '',
        sort_order: String(stage.sort_order),
        required_core_fields: [...stage.required_core_fields],
        required_custom_field_ids: [...stage.required_custom_field_ids],
    };
    stageModalOpen.value = true;
}

function toggleArrayItem(list: string[], value: string, checked: boolean): string[] {
    const next = new Set(list);
    if (checked) {
        next.add(value);
    } else {
        next.delete(value);
    }
    return Array.from(next);
}

async function handleSaveStage(): Promise<void> {
    if (!props.canManage || !selectedPipeline.value || stageSaving.value) {
        return;
    }

    stageSaving.value = true;
    stageError.value = '';
    stageFieldErrors.value = {};

    const rottingRaw = stageForm.value.rotting_days.trim();
    const payload: App.Modules.Crm.Pipelines.Data.UpsertPipelineStageData = {
        name: stageForm.value.name.trim(),
        stage_type: stageForm.value.stage_type,
        win_probability: Number(stageForm.value.win_probability) || 0,
        rotting_days: rottingRaw !== '' ? Number(rottingRaw) : null,
        required_core_fields: stageForm.value.required_core_fields,
        required_custom_field_ids: stageForm.value.required_custom_field_ids,
        sort_order: Number(stageForm.value.sort_order) || 0,
    };

    try {
        const savedStage = editingStageId.value
            ? await crmApi.updatePipelineStage(selectedPipeline.value.id, editingStageId.value, payload)
            : await crmApi.createPipelineStage(selectedPipeline.value.id, payload);

        localPipelines.value = localPipelines.value.map((p) => {
            if (p.id !== selectedPipeline.value?.id) {
                return p;
            }
            const nextStages = editingStageId.value
                ? p.stages.map((s) => (s.id === savedStage.id ? savedStage : s))
                : [...p.stages, savedStage];
            nextStages.sort((a, b) => a.sort_order - b.sort_order);
            return { ...p, stages: nextStages };
        });

        stageModalOpen.value = false;
    } catch (error) {
        if (error instanceof ValidationRequestError) {
            stageFieldErrors.value = extractFirstFieldErrors(error.errors);
            stageError.value = error.message;
        } else {
            stageError.value = error instanceof Error ? error.message : 'Unable to save stage.';
        }
    } finally {
        stageSaving.value = false;
    }
}

async function handleDeleteStage(stageId: string): Promise<void> {
    if (!props.canManage || !selectedPipeline.value) {
        return;
    }

    globalError.value = '';
    try {
        await crmApi.deletePipelineStage(selectedPipeline.value.id, stageId);
        localPipelines.value = localPipelines.value.map((p) =>
            p.id === selectedPipeline.value?.id
                ? { ...p, stages: p.stages.filter((s) => s.id !== stageId) }
                : p,
        );
    } catch (error) {
        globalError.value = error instanceof Error ? error.message : 'Unable to delete stage.';
    }
}

// Custom Field Definitions Manager
const entityFilter = ref<string>('all');
const entityTypes: App.Modules.Crm.CustomFields.Enums.CustomFieldEntityTypeEnum[] = [
    'company',
    'contact',
    'deal',
    'project',
];
const fieldTypes: App.Modules.Crm.CustomFields.Enums.CustomFieldTypeEnum[] = [
    'text',
    'textarea',
    'number',
    'currency',
    'boolean',
    'date',
    'select',
    'multi_select',
    'user_id',
    'url',
];

const filteredFields = computed(() => {
    if (entityFilter.value === 'all') {
        return localFields.value;
    }
    return localFields.value.filter((cf) => cf.entity_type === entityFilter.value);
});

const fieldModalOpen = ref<boolean>(false);
const editingFieldId = ref<string | null>(null);
const fieldSaving = ref<boolean>(false);
const fieldModalError = ref<string>('');
const customFieldFormErrors = ref<Record<string, string>>({});

const customFieldForm = ref({
    entity_type: 'deal' as App.Modules.Crm.CustomFields.Enums.CustomFieldEntityTypeEnum,
    pipeline_id: '',
    key: '',
    label: '',
    field_type: 'text' as App.Modules.Crm.CustomFields.Enums.CustomFieldTypeEnum,
    options_text: '',
    required: false,
    is_filterable: true,
    is_active: true,
    sort_order: '0',
});

function openCreateField(): void {
    editingFieldId.value = null;
    fieldModalError.value = '';
    customFieldFormErrors.value = {};
    customFieldForm.value = {
        entity_type: entityFilter.value !== 'all'
            ? (entityFilter.value as App.Modules.Crm.CustomFields.Enums.CustomFieldEntityTypeEnum)
            : 'deal',
        pipeline_id: '',
        key: '',
        label: '',
        field_type: 'text',
        options_text: '',
        required: false,
        is_filterable: true,
        is_active: true,
        sort_order: String(localFields.value.length),
    };
    fieldModalOpen.value = true;
}

function openEditField(def: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData): void {
    editingFieldId.value = def.id;
    fieldModalError.value = '';
    customFieldFormErrors.value = {};

    const optionsStr = Array.isArray(def.options)
        ? def.options
              .map((opt) => (typeof opt === 'string' ? opt : String(opt.value ?? '')))
              .filter(Boolean)
              .join(', ')
        : '';

    customFieldForm.value = {
        entity_type: def.entity_type,
        pipeline_id: def.pipeline_id ?? '',
        key: def.key,
        label: def.label,
        field_type: def.field_type,
        options_text: optionsStr,
        required: def.validation_rules?.required === true,
        is_filterable: def.is_filterable,
        is_active: def.is_active,
        sort_order: String(def.sort_order),
    };
    fieldModalOpen.value = true;
}

const requiresFieldOptions = computed(
    () => customFieldForm.value.field_type === 'select' || customFieldForm.value.field_type === 'multi_select',
);

async function handleSaveCustomField(): Promise<void> {
    if (!props.canManage || fieldSaving.value) {
        return;
    }

    fieldSaving.value = true;
    fieldModalError.value = '';
    customFieldFormErrors.value = {};

    const parsedOptions = requiresFieldOptions.value
        ? customFieldForm.value.options_text
              .split(',')
              .map((item) => item.trim())
              .filter(Boolean)
        : null;

    const payload: App.Modules.Crm.CustomFields.Data.UpsertCustomFieldDefinitionData = {
        entity_type: customFieldForm.value.entity_type,
        pipeline_id:
            customFieldForm.value.entity_type === 'deal'
                ? customFieldForm.value.pipeline_id || null
                : null,
        key: customFieldForm.value.key.trim(),
        label: customFieldForm.value.label.trim(),
        field_type: customFieldForm.value.field_type,
        options: parsedOptions,
        validation_rules: {
            required: customFieldForm.value.required,
        },
        is_filterable: customFieldForm.value.is_filterable,
        is_active: customFieldForm.value.is_active,
        sort_order: Number(customFieldForm.value.sort_order) || 0,
    };

    try {
        const saved = editingFieldId.value
            ? await crmApi.updateCustomField(editingFieldId.value, payload)
            : await crmApi.createCustomField(payload);

        if (editingFieldId.value) {
            localFields.value = localFields.value.map((f) => (f.id === saved.id ? saved : f));
        } else {
            localFields.value = [...localFields.value, saved];
        }
        fieldModalOpen.value = false;
    } catch (error) {
        if (error instanceof ValidationRequestError) {
            customFieldFormErrors.value = extractFirstFieldErrors(error.errors);
            fieldModalError.value = error.message;
        } else {
            fieldModalError.value = error instanceof Error ? error.message : 'Unable to save custom field.';
        }
    } finally {
        fieldSaving.value = false;
    }
}

async function handleDeleteCustomField(fieldId: string): Promise<void> {
    if (!props.canManage) {
        return;
    }

    globalError.value = '';
    try {
        await crmApi.deleteCustomField(fieldId);
        localFields.value = localFields.value.filter((f) => f.id !== fieldId);
    } catch (error) {
        globalError.value = error instanceof Error ? error.message : 'Unable to delete custom field.';
    }
}
</script>

<template>
    <Head title="CRM Settings — Pipelines & Custom Fields" />

    <div class="space-y-4">
        <!-- Header -->
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <SlidersHorizontal class="h-4 w-4 shrink-0 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                    <h2 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                        CRM Pipeline &amp; Custom Field Settings
                    </h2>
                </div>
                <p class="mt-0.5 text-[12px] text-secondary">
                    Configure deal stages, win probabilities, rotting thresholds, stage gates, and entity metadata schemas.
                </p>
            </div>

            <div class="flex items-center gap-1 rounded-[6px] border border-default bg-subtle p-0.5" role="tablist">
                <button
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === 'pipelines' ? 'true' : 'false'"
                    :class="[
                        'h-7 rounded-[4px] px-3 text-[12px] font-medium transition-colors duration-150 cursor-pointer',
                        activeTab === 'pipelines'
                            ? 'bg-surface text-primary border border-default'
                            : 'text-secondary hover:text-primary',
                    ]"
                    @click="activeTab = 'pipelines'"
                >
                    Pipelines &amp; Stages ({{ localPipelines.length }})
                </button>
                <button
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === 'custom_fields' ? 'true' : 'false'"
                    :class="[
                        'h-7 rounded-[4px] px-3 text-[12px] font-medium transition-colors duration-150 cursor-pointer',
                        activeTab === 'custom_fields'
                            ? 'bg-surface text-primary border border-default'
                            : 'text-secondary hover:text-primary',
                    ]"
                    @click="activeTab = 'custom_fields'"
                >
                    Custom Fields ({{ localFields.length }})
                </button>
            </div>
        </div>

        <div
            v-if="globalError"
            role="alert"
            class="flex items-center gap-2 rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
        >
            <AlertCircle class="h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
            <span>{{ globalError }}</span>
        </div>

        <!-- Pipelines & Stages Tab -->
        <div v-if="activeTab === 'pipelines'" class="grid grid-cols-1 gap-4 lg:grid-cols-12">
            <!-- Pipelines List -->
            <aside class="lg:col-span-4 rounded-[8px] border border-default bg-surface overflow-hidden">
                <div class="flex items-center justify-between border-b border-default bg-subtle px-3.5 py-2.5">
                    <h3 class="text-[13px] font-semibold text-primary">Pipelines</h3>
                    <Button
                        v-if="props.canManage"
                        variant="secondary"
                        size="sm"
                        @click="openCreatePipeline"
                    >
                        <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <span>New Pipeline</span>
                    </Button>
                </div>

                <ul class="divide-y divide-default">
                    <li
                        v-for="pipeline in localPipelines"
                        :key="pipeline.id"
                        :class="[
                            'flex items-center justify-between gap-2 px-3.5 py-2.5 text-[13px] transition-colors',
                            selectedPipeline?.id === pipeline.id ? 'bg-accent-primary/5' : 'hover:bg-subtle/60',
                        ]"
                    >
                        <button
                            type="button"
                            class="min-w-0 flex-1 text-left cursor-pointer"
                            @click="selectedPipelineId = pipeline.id"
                        >
                            <div class="flex items-center gap-1.5">
                                <span class="truncate font-medium text-primary">{{ pipeline.name }}</span>
                                <Badge v-if="pipeline.is_default" variant="cobalt">Default</Badge>
                                <Badge v-if="!pipeline.is_active" variant="neutral">Inactive</Badge>
                            </div>
                            <p class="font-mono text-[11px] text-muted">
                                {{ pipeline.slug }} · {{ pipeline.stages.length }} stages
                            </p>
                        </button>

                        <button
                            v-if="props.canManage"
                            type="button"
                            :aria-label="`Edit pipeline ${pipeline.name}`"
                            class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-[4px] text-secondary hover:bg-subtle hover:text-primary cursor-pointer"
                            @click="openEditPipeline(pipeline)"
                        >
                            <Edit3 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        </button>
                    </li>
                </ul>
            </aside>

            <!-- Ordered Stages for Selected Pipeline -->
            <section class="lg:col-span-8 rounded-[8px] border border-default bg-surface overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-default bg-subtle px-4 py-2.5">
                    <div>
                        <h3 class="text-[13px] font-semibold text-primary">
                            {{ selectedPipeline ? `${selectedPipeline.name} — Ordered Stages` : 'Select a pipeline' }}
                        </h3>
                        <p class="text-[11px] text-secondary">
                            Required core &amp; custom fields enforce stage-gate validation on Kanban and deal updates.
                        </p>
                    </div>
                    <Button
                        v-if="props.canManage && selectedPipeline"
                        variant="primary"
                        size="sm"
                        @click="openCreateStage"
                    >
                        <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <span>Add Stage</span>
                    </Button>
                </div>

                <div v-if="!selectedPipeline" class="p-6 text-center text-[13px] text-muted">
                    Create a pipeline to configure deal stages.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full border-collapse text-left">
                        <thead>
                            <tr class="h-8 border-b border-default bg-subtle/60 text-[11px] font-medium text-secondary">
                                <th class="px-3.5 py-1.5 font-medium">Order</th>
                                <th class="px-3 py-1.5 font-medium">Stage</th>
                                <th class="px-3 py-1.5 font-medium">Type</th>
                                <th class="px-3 py-1.5 text-right font-medium">Win %</th>
                                <th class="px-3 py-1.5 text-right font-medium">Rotting</th>
                                <th class="px-3 py-1.5 font-medium">Required Gates</th>
                                <th v-if="props.canManage" class="px-3.5 py-1.5 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-default text-[13px]">
                            <tr
                                v-for="stage in selectedPipeline.stages"
                                :key="stage.id"
                                class="h-9 hover:bg-subtle/50"
                            >
                                <td class="px-3.5 py-1.5 font-mono text-[12px] tabular-nums text-muted">
                                    {{ stage.sort_order }}
                                </td>
                                <td class="px-3 py-1.5 font-medium text-primary">
                                    {{ stage.name }}
                                </td>
                                <td class="px-3 py-1.5">
                                    <Badge :variant="stageBadgeVariant(stage.stage_type)">
                                        {{ stage.stage_type }}
                                    </Badge>
                                </td>
                                <td class="px-3 py-1.5 text-right font-mono text-[12px] tabular-nums text-primary">
                                    {{ stage.win_probability }}%
                                </td>
                                <td class="px-3 py-1.5 text-right font-mono text-[12px] tabular-nums text-secondary">
                                    {{ stage.rotting_days ? `${stage.rotting_days}d` : '—' }}
                                </td>
                                <td class="px-3 py-1.5">
                                    <div class="flex flex-wrap items-center gap-1">
                                        <span
                                            v-for="coreKey in stage.required_core_fields"
                                            :key="coreKey"
                                            class="rounded border border-default bg-subtle px-1.5 py-0.5 text-[10px] font-medium text-secondary"
                                        >
                                            {{ getCoreFieldLabel(coreKey) }}
                                        </span>
                                        <span
                                            v-if="stage.required_custom_field_ids.length > 0"
                                            class="rounded border border-accent-primary/30 bg-accent-primary/10 px-1.5 py-0.5 font-mono text-[10px] text-accent-primary"
                                        >
                                            +{{ stage.required_custom_field_ids.length }} custom
                                        </span>
                                        <span
                                            v-if="stage.required_core_fields.length === 0 && stage.required_custom_field_ids.length === 0"
                                            class="text-[11px] text-muted"
                                        >
                                            None
                                        </span>
                                    </div>
                                </td>
                                <td v-if="props.canManage" class="px-3.5 py-1.5 text-right">
                                    <div class="inline-flex items-center gap-1">
                                        <button
                                            type="button"
                                            :aria-label="`Edit stage ${stage.name}`"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-[4px] text-secondary hover:bg-subtle hover:text-primary cursor-pointer"
                                            @click="openEditStage(stage)"
                                        >
                                            <Edit3 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                        </button>
                                        <button
                                            type="button"
                                            :aria-label="`Delete stage ${stage.name}`"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-[4px] text-secondary hover:bg-status-danger/10 hover:text-status-danger cursor-pointer"
                                            @click="handleDeleteStage(stage.id)"
                                        >
                                            <Trash2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- Custom Field Definitions Tab -->
        <section
            v-else
            class="overflow-hidden rounded-[8px] border border-default bg-surface"
        >
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-default bg-subtle px-4 py-2.5">
                <div class="flex flex-wrap items-center gap-2">
                    <label :for="`cf-entity-filter-${instanceId}`" class="sr-only">Filter by entity type</label>
                    <select
                        :id="`cf-entity-filter-${instanceId}`"
                        v-model="entityFilter"
                        class="h-8 rounded-[6px] border border-default bg-surface px-2.5 text-[12px] text-primary"
                    >
                        <option value="all">All entity types</option>
                        <option
                            v-for="etype in entityTypes"
                            :key="etype"
                            :value="etype"
                        >
                            {{ formatEnumLabel(etype) }}
                        </option>
                    </select>
                </div>

                <Button
                    v-if="props.canManage"
                    variant="primary"
                    size="sm"
                    @click="openCreateField"
                >
                    <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>New Custom Field</span>
                </Button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-left">
                    <thead>
                        <tr class="h-8 border-b border-default bg-subtle/60 text-[11px] font-medium text-secondary">
                            <th class="px-3.5 py-1.5 font-medium">Label</th>
                            <th class="px-3 py-1.5 font-medium">Key</th>
                            <th class="px-3 py-1.5 font-medium">Entity</th>
                            <th class="px-3 py-1.5 font-medium">Type</th>
                            <th class="px-3 py-1.5 font-medium">Flags</th>
                            <th v-if="props.canManage" class="px-3.5 py-1.5 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-default text-[13px]">
                        <tr v-if="filteredFields.length === 0">
                            <td :colspan="props.canManage ? 6 : 5" class="px-4 py-6 text-center text-[12px] text-muted">
                                No custom fields defined for this filter.
                            </td>
                        </tr>
                        <tr
                            v-for="field in filteredFields"
                            :key="field.id"
                            class="h-9 hover:bg-subtle/50"
                        >
                            <td class="px-3.5 py-1.5 font-medium text-primary">
                                {{ field.label }}
                            </td>
                            <td class="px-3 py-1.5 font-mono text-[12px] text-secondary">
                                {{ field.key }}
                            </td>
                            <td class="px-3 py-1.5">
                                <Badge variant="neutral">{{ formatEnumLabel(field.entity_type) }}</Badge>
                            </td>
                            <td class="px-3 py-1.5 font-mono text-[12px] text-secondary">
                                {{ field.field_type }}
                            </td>
                            <td class="px-3 py-1.5">
                                <div class="flex flex-wrap items-center gap-1">
                                    <Badge v-if="field.validation_rules?.required" variant="warning">Required</Badge>
                                    <Badge v-if="field.is_filterable" variant="cobalt">Filterable</Badge>
                                    <Badge v-if="!field.is_active" variant="danger">Inactive</Badge>
                                </div>
                            </td>
                            <td v-if="props.canManage" class="px-3.5 py-1.5 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <button
                                        type="button"
                                        :aria-label="`Edit custom field ${field.label}`"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-[4px] text-secondary hover:bg-subtle hover:text-primary cursor-pointer"
                                        @click="openEditField(field)"
                                    >
                                        <Edit3 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                    </button>
                                    <button
                                        type="button"
                                        :aria-label="`Delete custom field ${field.label}`"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-[4px] text-secondary hover:bg-status-danger/10 hover:text-status-danger cursor-pointer"
                                        @click="handleDeleteCustomField(field.id)"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Pipeline Modal -->
        <Dialog
            :open="pipelineModalOpen"
            :title="editingPipelineId ? 'Edit Pipeline' : 'New Pipeline'"
            max-width-class="max-w-md"
            @update:open="pipelineModalOpen = $event"
        >
            <form class="space-y-3" @submit.prevent="handleSavePipeline">
                <div
                    v-if="pipelineError"
                    role="alert"
                    class="rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                >
                    {{ pipelineError }}
                </div>

                <div>
                    <label :for="`pl-name-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                        Pipeline Name <span class="text-status-danger">*</span>
                    </label>
                    <Input
                        :id="`pl-name-${instanceId}`"
                        v-model="pipelineForm.name"
                        :disabled="pipelineSaving"
                        :error-message="pipelineFieldErrors.name ?? ''"
                    />
                </div>

                <div>
                    <label :for="`pl-slug-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                        Slug (optional)
                    </label>
                    <Input
                        :id="`pl-slug-${instanceId}`"
                        v-model="pipelineForm.slug"
                        placeholder="enterprise-sales"
                        class="font-mono"
                        :disabled="pipelineSaving"
                        :error-message="pipelineFieldErrors.slug ?? ''"
                    />
                </div>

                <div>
                    <label :for="`pl-sort-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                        Sort Order
                    </label>
                    <Input
                        :id="`pl-sort-${instanceId}`"
                        v-model="pipelineForm.sort_order"
                        type="number"
                        class="font-mono tabular-nums"
                        :disabled="pipelineSaving"
                    />
                </div>

                <div class="flex flex-wrap gap-4 pt-1">
                    <label :for="`pl-default-${instanceId}`" class="flex items-center gap-2 text-[12px] text-primary cursor-pointer">
                        <input
                            :id="`pl-default-${instanceId}`"
                            v-model="pipelineForm.is_default"
                            type="checkbox"
                            class="h-3.5 w-3.5 rounded border-default accent-accent-primary"
                        />
                        <span>Default pipeline</span>
                    </label>
                    <label :for="`pl-active-${instanceId}`" class="flex items-center gap-2 text-[12px] text-primary cursor-pointer">
                        <input
                            :id="`pl-active-${instanceId}`"
                            v-model="pipelineForm.is_active"
                            type="checkbox"
                            class="h-3.5 w-3.5 rounded border-default accent-accent-primary"
                        />
                        <span>Active</span>
                    </label>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-default pt-3">
                    <Button variant="secondary" :disabled="pipelineSaving" @click="pipelineModalOpen = false">
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" :loading="pipelineSaving">
                        Save Pipeline
                    </Button>
                </div>
            </form>
        </Dialog>

        <!-- Stage Modal -->
        <Dialog
            :open="stageModalOpen"
            :title="editingStageId ? 'Edit Pipeline Stage' : 'Add Pipeline Stage'"
            max-width-class="max-w-lg"
            @update:open="stageModalOpen = $event"
        >
            <form class="max-h-[75vh] overflow-y-auto space-y-3 pr-1" @submit.prevent="handleSaveStage">
                <div
                    v-if="stageError"
                    role="alert"
                    class="rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                >
                    {{ stageError }}
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label :for="`st-name-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Stage Name <span class="text-status-danger">*</span>
                        </label>
                        <Input
                            :id="`st-name-${instanceId}`"
                            v-model="stageForm.name"
                            placeholder="Proposal & Legal Review"
                            :disabled="stageSaving"
                            :error-message="stageFieldErrors.name ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`st-type-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Stage Type
                        </label>
                        <select
                            :id="`st-type-${instanceId}`"
                            v-model="stageForm.stage_type"
                            :disabled="stageSaving"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary"
                        >
                            <option value="open">Open</option>
                            <option value="won">Won</option>
                            <option value="lost">Lost</option>
                        </select>
                    </div>

                    <div>
                        <label :for="`st-prob-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Win Probability (%)
                        </label>
                        <Input
                            :id="`st-prob-${instanceId}`"
                            v-model="stageForm.win_probability"
                            type="number"
                            class="font-mono tabular-nums"
                            :disabled="stageSaving"
                            :error-message="stageFieldErrors.win_probability ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`st-rot-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Rotting Threshold (days)
                        </label>
                        <Input
                            :id="`st-rot-${instanceId}`"
                            v-model="stageForm.rotting_days"
                            type="number"
                            placeholder="14"
                            class="font-mono tabular-nums"
                            :disabled="stageSaving"
                        />
                    </div>

                    <div>
                        <label :for="`st-order-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Sort Order
                        </label>
                        <Input
                            :id="`st-order-${instanceId}`"
                            v-model="stageForm.sort_order"
                            type="number"
                            class="font-mono tabular-nums"
                            :disabled="stageSaving"
                        />
                    </div>
                </div>

                <!-- Required Core Fields -->
                <fieldset class="space-y-1.5 border-t border-default pt-3">
                    <legend class="text-[12px] font-medium text-secondary">
                        Required Core Stage-Gate Fields
                    </legend>
                    <div class="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                        <label
                            v-for="coreKey in props.allowedCoreFields"
                            :key="coreKey"
                            :for="`st-core-${instanceId}-${coreKey}`"
                            class="flex items-center gap-2 rounded-[5px] border border-default bg-subtle/50 px-2.5 py-1.5 text-[12px] text-primary cursor-pointer"
                        >
                            <input
                                :id="`st-core-${instanceId}-${coreKey}`"
                                type="checkbox"
                                :checked="stageForm.required_core_fields.includes(coreKey)"
                                class="h-3.5 w-3.5 rounded border-default accent-accent-primary"
                                @change="
                                    stageForm.required_core_fields = toggleArrayItem(
                                        stageForm.required_core_fields,
                                        coreKey,
                                        ($event.target as HTMLInputElement).checked,
                                    )
                                "
                            />
                            <span>{{ getCoreFieldLabel(coreKey) }}</span>
                        </label>
                    </div>
                </fieldset>

                <!-- Required Custom Fields -->
                <fieldset v-if="dealCustomFields.length > 0" class="space-y-1.5 border-t border-default pt-3">
                    <legend class="text-[12px] font-medium text-secondary">
                        Required Custom Stage-Gate Fields
                    </legend>
                    <div class="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                        <label
                            v-for="cf in dealCustomFields"
                            :key="cf.id"
                            :for="`st-cf-${instanceId}-${cf.id}`"
                            class="flex items-center gap-2 rounded-[5px] border border-default bg-subtle/50 px-2.5 py-1.5 text-[12px] text-primary cursor-pointer"
                        >
                            <input
                                :id="`st-cf-${instanceId}-${cf.id}`"
                                type="checkbox"
                                :checked="stageForm.required_custom_field_ids.includes(cf.id)"
                                class="h-3.5 w-3.5 rounded border-default accent-accent-primary"
                                @change="
                                    stageForm.required_custom_field_ids = toggleArrayItem(
                                        stageForm.required_custom_field_ids,
                                        cf.id,
                                        ($event.target as HTMLInputElement).checked,
                                    )
                                "
                            />
                            <span class="truncate">{{ cf.label }}</span>
                        </label>
                    </div>
                </fieldset>

                <div class="flex items-center justify-end gap-2 border-t border-default pt-3">
                    <Button variant="secondary" :disabled="stageSaving" @click="stageModalOpen = false">
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" :loading="stageSaving">
                        Save Stage
                    </Button>
                </div>
            </form>
        </Dialog>

        <!-- Custom Field Definition Modal -->
        <Dialog
            :open="fieldModalOpen"
            :title="editingFieldId ? 'Edit Custom Field' : 'New Custom Field'"
            max-width-class="max-w-lg"
            @update:open="fieldModalOpen = $event"
        >
            <form class="space-y-3" @submit.prevent="handleSaveCustomField">
                <div
                    v-if="fieldModalError"
                    role="alert"
                    class="rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                >
                    {{ fieldModalError }}
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label :for="`cf-entity-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Entity Type
                        </label>
                        <select
                            :id="`cf-entity-${instanceId}`"
                            v-model="customFieldForm.entity_type"
                            :disabled="fieldSaving || Boolean(editingFieldId)"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary"
                        >
                            <option v-for="etype in entityTypes" :key="etype" :value="etype">
                                {{ formatEnumLabel(etype) }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label :for="`cf-type-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Field Type
                        </label>
                        <select
                            :id="`cf-type-${instanceId}`"
                            v-model="customFieldForm.field_type"
                            :disabled="fieldSaving"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary"
                        >
                            <option v-for="ftype in fieldTypes" :key="ftype" :value="ftype">
                                {{ ftype }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label :for="`cf-label-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Label <span class="text-status-danger">*</span>
                        </label>
                        <Input
                            :id="`cf-label-${instanceId}`"
                            v-model="customFieldForm.label"
                            placeholder="Deployment Region"
                            :disabled="fieldSaving"
                            :error-message="customFieldFormErrors.label ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`cf-key-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Machine Key <span class="text-status-danger">*</span>
                        </label>
                        <Input
                            :id="`cf-key-${instanceId}`"
                            v-model="customFieldForm.key"
                            placeholder="deployment_region"
                            class="font-mono"
                            :disabled="fieldSaving"
                            :error-message="customFieldFormErrors.key ?? ''"
                        />
                    </div>

                    <div v-if="customFieldForm.entity_type === 'deal'" class="sm:col-span-2">
                        <label :for="`cf-pipeline-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Pipeline Scope (optional)
                        </label>
                        <select
                            :id="`cf-pipeline-${instanceId}`"
                            v-model="customFieldForm.pipeline_id"
                            :disabled="fieldSaving"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary"
                        >
                            <option value="">All Deal Pipelines</option>
                            <option v-for="pipeline in localPipelines" :key="pipeline.id" :value="pipeline.id">
                                {{ pipeline.name }}
                            </option>
                        </select>
                    </div>

                    <div v-if="requiresFieldOptions" class="sm:col-span-2">
                        <label :for="`cf-options-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Options (comma-separated) <span class="text-status-danger">*</span>
                        </label>
                        <Input
                            :id="`cf-options-${instanceId}`"
                            v-model="customFieldForm.options_text"
                            placeholder="us_east, us_west, eu_central"
                            :disabled="fieldSaving"
                            :error-message="customFieldFormErrors.options ?? ''"
                        />
                    </div>
                </div>

                <div class="flex flex-wrap gap-4 pt-1">
                    <label :for="`cf-req-${instanceId}`" class="flex items-center gap-2 text-[12px] text-primary cursor-pointer">
                        <input
                            :id="`cf-req-${instanceId}`"
                            v-model="customFieldForm.required"
                            type="checkbox"
                            class="h-3.5 w-3.5 rounded border-default accent-accent-primary"
                        />
                        <span>Required</span>
                    </label>
                    <label :for="`cf-filterable-${instanceId}`" class="flex items-center gap-2 text-[12px] text-primary cursor-pointer">
                        <input
                            :id="`cf-filterable-${instanceId}`"
                            v-model="customFieldForm.is_filterable"
                            type="checkbox"
                            class="h-3.5 w-3.5 rounded border-default accent-accent-primary"
                        />
                        <span>Filterable</span>
                    </label>
                    <label :for="`cf-active-${instanceId}`" class="flex items-center gap-2 text-[12px] text-primary cursor-pointer">
                        <input
                            :id="`cf-active-${instanceId}`"
                            v-model="customFieldForm.is_active"
                            type="checkbox"
                            class="h-3.5 w-3.5 rounded border-default accent-accent-primary"
                        />
                        <span>Active</span>
                    </label>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-default pt-3">
                    <Button variant="secondary" :disabled="fieldSaving" @click="fieldModalOpen = false">
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" :loading="fieldSaving">
                        Save Field
                    </Button>
                </div>
            </form>
        </Dialog>
    </div>
</template>
