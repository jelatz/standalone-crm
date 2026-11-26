<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    AlertCircle,
    AlertTriangle,
    Bold,
    BookOpen,
    Check,
    CheckCircle2,
    Code,
    Code2,
    Copy,
    FileCode2,
    FileText,
    FolderPlus,
    Heading2,
    Italic,
    Link2,
    List,
    ListOrdered,
    Plus,
    Quote,
    RotateCcw,
    Search,
    Table,
} from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Input from '@/components/ui/Input.vue';
import MarkdownGuideDialog from '@/modules/core/components/MarkdownGuideDialog.vue';
import MarkdownGuideReference from '@/modules/core/components/MarkdownGuideReference.vue';
import {
    extractFirstFieldErrors,
    SopConflictError,
    sopsApi,
} from '@/modules/core/api/sopsApi';
import { ValidationRequestError } from '@/modules/crm/api/crmApi';
import {
    applyMarkdownFormat,
    type MarkdownFormatId,
} from '@/modules/core/utils/markdownFormatting';
import { parseMarkdownBlocks, type InlineToken } from '@/modules/core/utils/markdownPreview';
import { useToolbarFocusgroup } from '@/modules/core/utils/useToolbarFocusgroup';
import { useAccessibleFormValidation } from '@/utils/formAccessibility';

const NEW_MODULE_OPTION = '__new__';

const props = withDefaults(
    defineProps<{
        modules?: App.Modules.Core.Sops.Data.SopModuleGroupData[];
        initialDocument?: App.Modules.Core.Sops.Data.SopDocumentData | null;
        initialState?: 'ready' | 'loading' | 'error';
        initialError?: string;
    }>(),
    {
        modules: () => [],
        initialDocument: null,
        initialState: 'ready',
        initialError: '',
    },
);

const localModules = ref<App.Modules.Core.Sops.Data.SopModuleGroupData[]>(
    props.modules.map((group) => ({ ...group, sops: [...group.sops] })),
);
const activeDocument = ref<App.Modules.Core.Sops.Data.SopDocumentData | null>(
    props.initialDocument ? { ...props.initialDocument } : null,
);
const draftContent = ref<string>(props.initialDocument?.content ?? '');
const editorTab = ref<'write' | 'preview'>('write');
const searchQuery = ref<string>('');
const editorToolbarRef = ref<HTMLElement | null>(null);
const editorTextareaRef = ref<HTMLTextAreaElement | null>(null);
const markdownGuideOpen = ref<boolean>(false);

useToolbarFocusgroup(editorToolbarRef);

const loadingDocument = ref<boolean>(false);
const saving = ref<boolean>(false);
const statusMessage = ref<string>('');
const editorError = ref<string>('');
const conflictState = ref<{
    message: string;
    latestDocument: App.Modules.Core.Sops.Data.SopDocumentData;
} | null>(null);
const copiedPath = ref<boolean>(false);
let copyResetTimer: ReturnType<typeof setTimeout> | undefined;

// Unsaved changes guard dialog
const unsavedDialogOpen = ref<boolean>(false);
const pendingSelection = ref<{ module: string; slug: string } | null>(null);

// Create SOP dialog state
const newSopDialogOpen = ref<boolean>(false);
const creatingSop = ref<boolean>(false);
const createGlobalError = ref<string>('');
const createFieldErrors = ref<Record<string, string>>({});
const newSopFormRef = ref<HTMLFormElement | null>(null);
const newSopTextareaRef = ref<HTMLTextAreaElement | null>(null);
const showNewSopGuide = ref<boolean>(false);
const moduleSelectMode = ref<string>(localModules.value[0]?.module ?? NEW_MODULE_OPTION);
const customModuleName = ref<string>('');
const newSopName = ref<string>('');
const newSopContent = ref<string>('');

useAccessibleFormValidation(newSopFormRef);

watch(
    () => props.modules,
    (nextModules) => {
        localModules.value = nextModules.map((group) => ({ ...group, sops: [...group.sops] }));
    },
);

watch(
    () => props.initialDocument,
    (nextDocument) => {
        activeDocument.value = nextDocument ? { ...nextDocument } : null;
        draftContent.value = nextDocument?.content ?? '';
        editorError.value = '';
        conflictState.value = null;
    },
);

const totalSopCount = computed(() =>
    localModules.value.reduce((total, group) => total + group.sops.length, 0),
);

const isDirty = computed(
    () => activeDocument.value !== null && draftContent.value !== activeDocument.value.content,
);

const filteredModules = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();
    if (query === '') {
        return localModules.value;
    }

    return localModules.value
        .map((group) => {
            const moduleMatches =
                group.label.toLowerCase().includes(query) ||
                group.module.toLowerCase().includes(query);

            const matchingSops = moduleMatches
                ? group.sops
                : group.sops.filter(
                      (sop) =>
                          sop.title.toLowerCase().includes(query) ||
                          sop.slug.toLowerCase().includes(query) ||
                          sop.filename.toLowerCase().includes(query),
                  );

            return {
                ...group,
                sops: matchingSops,
            };
        })
        .filter(
            (group) =>
                group.sops.length > 0 ||
                group.label.toLowerCase().includes(query) ||
                group.module.toLowerCase().includes(query),
        );
});

const previewBlocks = computed(() => parseMarkdownBlocks(draftContent.value));

const lineCount = computed(() => {
    if (draftContent.value === '') {
        return 0;
    }
    return draftContent.value.split('\n').length;
});

const wordCount = computed(() => {
    const trimmed = draftContent.value.trim();
    if (trimmed === '') {
        return 0;
    }
    return trimmed.split(/\s+/).length;
});

const charCount = computed(() => draftContent.value.length);

const activeModuleLabel = computed(() => {
    if (!activeDocument.value) {
        return '';
    }
    const found = localModules.value.find((group) => group.module === activeDocument.value?.module);
    return found?.label ?? formatSlugHeadline(activeDocument.value.module);
});

const resolvedNewModuleSlug = computed(() => {
    if (moduleSelectMode.value !== NEW_MODULE_OPTION) {
        return moduleSelectMode.value;
    }
    return toKebabSlug(customModuleName.value) || 'new-module';
});

const resolvedNewSopSlug = computed(() => toKebabSlug(newSopName.value) || 'new-sop');

const newSopPathPreview = computed(
    () => `context/sops/${resolvedNewModuleSlug.value}/${resolvedNewSopSlug.value}.md`,
);

function toKebabSlug(value: string): string {
    return value
        .trim()
        .replace(/\.md$/i, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function formatSlugHeadline(slug: string): string {
    return slug
        .split('-')
        .filter(Boolean)
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

function formatTimestamp(isoString: string): string {
    const parsed = new Date(isoString);
    if (Number.isNaN(parsed.getTime())) {
        return isoString;
    }
    return parsed.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function toSummary(doc: App.Modules.Core.Sops.Data.SopDocumentData): App.Modules.Core.Sops.Data.SopSummaryData {
    return {
        module: doc.module,
        slug: doc.slug,
        filename: doc.filename,
        relative_path: doc.relative_path,
        title: doc.title,
        size_bytes: doc.size_bytes,
        updated_at: doc.updated_at,
        sha256: doc.sha256,
    };
}

function upsertSopInDirectory(doc: App.Modules.Core.Sops.Data.SopDocumentData): void {
    const summary = toSummary(doc);
    const existingGroup = localModules.value.find((group) => group.module === doc.module);

    if (existingGroup) {
        const exists = existingGroup.sops.some((sop) => sop.slug === doc.slug);
        const nextSops = exists
            ? existingGroup.sops.map((sop) => (sop.slug === doc.slug ? summary : sop))
            : [...existingGroup.sops, summary].sort((a, b) => a.slug.localeCompare(b.slug));

        localModules.value = localModules.value.map((group) =>
            group.module === doc.module ? { ...group, sops: nextSops } : group,
        );
        return;
    }

    localModules.value = [
        ...localModules.value,
        {
            module: doc.module,
            label: formatSlugHeadline(doc.module),
            sops: [summary],
        },
    ];
}

function isSopSelected(module: string, slug: string): boolean {
    return activeDocument.value?.module === module && activeDocument.value?.slug === slug;
}

async function selectSop(module: string, slug: string): Promise<void> {
    if (isSopSelected(module, slug) || loadingDocument.value) {
        return;
    }

    if (isDirty.value) {
        pendingSelection.value = { module, slug };
        unsavedDialogOpen.value = true;
        return;
    }

    await loadSopDocument(module, slug);
}

async function confirmDiscardAndSwitch(): Promise<void> {
    const target = pendingSelection.value;
    unsavedDialogOpen.value = false;
    pendingSelection.value = null;

    if (!target) {
        return;
    }

    await loadSopDocument(target.module, target.slug);
}

async function loadSopDocument(module: string, slug: string): Promise<void> {
    loadingDocument.value = true;
    editorError.value = '';
    statusMessage.value = '';
    conflictState.value = null;

    try {
        const loaded = await sopsApi.readSop(module, slug);
        activeDocument.value = loaded;
        draftContent.value = loaded.content;
        upsertSopInDirectory(loaded);
    } catch (error) {
        editorError.value =
            error instanceof Error ? error.message : 'Unable to load the selected SOP document.';
    } finally {
        loadingDocument.value = false;
    }
}

function discardChanges(): void {
    if (!activeDocument.value || saving.value) {
        return;
    }
    draftContent.value = activeDocument.value.content;
    editorError.value = '';
    conflictState.value = null;
}

async function saveCurrentSop(): Promise<void> {
    if (!activeDocument.value || !isDirty.value || saving.value) {
        return;
    }

    saving.value = true;
    editorError.value = '';
    statusMessage.value = '';
    conflictState.value = null;

    try {
        const updated = await sopsApi.updateSop(
            activeDocument.value.module,
            activeDocument.value.slug,
            {
                content: draftContent.value,
                expected_sha256: activeDocument.value.sha256,
            },
        );
        activeDocument.value = updated;
        draftContent.value = updated.content;
        upsertSopInDirectory(updated);
        statusMessage.value = `Saved changes to ${updated.relative_path}.`;
    } catch (error) {
        if (error instanceof SopConflictError) {
            conflictState.value = {
                message: error.message,
                latestDocument: error.document,
            };
        } else if (error instanceof ValidationRequestError) {
            const firstErrors = extractFirstFieldErrors(error.errors);
            editorError.value = firstErrors.content ?? error.message;
        } else {
            editorError.value =
                error instanceof Error ? error.message : 'Unable to save SOP changes.';
        }
    } finally {
        saving.value = false;
    }
}

function loadLatestFromConflict(): void {
    if (!conflictState.value) {
        return;
    }
    const latest = conflictState.value.latestDocument;
    activeDocument.value = latest;
    draftContent.value = latest.content;
    upsertSopInDirectory(latest);
    conflictState.value = null;
    editorError.value = '';
    statusMessage.value = `Reloaded latest version of ${latest.relative_path}.`;
}

function openNewSopDialog(preselectedModule?: string): void {
    createGlobalError.value = '';
    createFieldErrors.value = {};
    customModuleName.value = '';
    newSopName.value = '';
    newSopContent.value = '';
    showNewSopGuide.value = false;

    if (preselectedModule && localModules.value.some((group) => group.module === preselectedModule)) {
        moduleSelectMode.value = preselectedModule;
    } else if (localModules.value.length > 0) {
        moduleSelectMode.value = localModules.value[0].module;
    } else {
        moduleSelectMode.value = NEW_MODULE_OPTION;
    }

    newSopDialogOpen.value = true;

    void nextTick(() => {
        if (typeof document !== 'undefined') {
            const targetId =
                moduleSelectMode.value === NEW_MODULE_OPTION
                    ? 'new-sop-custom-module'
                    : 'new-sop-name';
            document.getElementById(targetId)?.focus();
        }
    });
}

function closeNewSopDialog(): void {
    if (creatingSop.value) {
        return;
    }
    newSopDialogOpen.value = false;
}

async function handleCreateSop(): Promise<void> {
    if (creatingSop.value) {
        return;
    }

    const moduleInput =
        moduleSelectMode.value === NEW_MODULE_OPTION
            ? customModuleName.value.trim()
            : moduleSelectMode.value;

    creatingSop.value = true;
    createGlobalError.value = '';
    createFieldErrors.value = {};

    try {
        const created = await sopsApi.createSop({
            module: moduleInput,
            name: newSopName.value.trim(),
            content: newSopContent.value,
        });

        upsertSopInDirectory(created);
        activeDocument.value = created;
        draftContent.value = created.content;
        editorTab.value = 'write';
        editorError.value = '';
        conflictState.value = null;
        statusMessage.value = `Created ${created.relative_path}.`;
        newSopDialogOpen.value = false;
    } catch (error) {
        if (error instanceof ValidationRequestError) {
            createFieldErrors.value = extractFirstFieldErrors(error.errors);
            createGlobalError.value = error.message;
        } else {
            createGlobalError.value =
                error instanceof Error ? error.message : 'Unable to create SOP file.';
        }
    } finally {
        creatingSop.value = false;
    }
}

async function copyRelativePath(): Promise<void> {
    if (!activeDocument.value || typeof navigator === 'undefined' || !navigator.clipboard) {
        return;
    }

    try {
        await navigator.clipboard.writeText(activeDocument.value.relative_path);
        copiedPath.value = true;
        if (copyResetTimer !== undefined) {
            clearTimeout(copyResetTimer);
        }
        copyResetTimer = setTimeout(() => {
            copiedPath.value = false;
        }, 1600);
    } catch {
        // Clipboard permission denied; ignore silently.
    }
}

function handleKeydown(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
        if (activeDocument.value && !newSopDialogOpen.value) {
            event.preventDefault();
            void saveCurrentSop();
        }
        return;
    }

    if (event.key === '/' && !event.metaKey && !event.ctrlKey && !event.altKey) {
        const target = event.target as HTMLElement | null;
        const isTyping =
            target !== null &&
            (target.tagName === 'INPUT' ||
                target.tagName === 'TEXTAREA' ||
                target.tagName === 'SELECT' ||
                target.isContentEditable);

        if (!isTyping && !newSopDialogOpen.value && typeof document !== 'undefined') {
            event.preventDefault();
            document.getElementById('sop-directory-filter')?.focus();
        }
    }
}

onMounted(() => {
    if (typeof window !== 'undefined') {
        window.addEventListener('keydown', handleKeydown);
    }
});

onUnmounted(() => {
    if (typeof window !== 'undefined') {
        window.removeEventListener('keydown', handleKeydown);
    }
    if (copyResetTimer !== undefined) {
        clearTimeout(copyResetTimer);
    }
});

function applyEditorFormat(action: MarkdownFormatId): void {
    if (!activeDocument.value || saving.value) {
        return;
    }

    const textarea = editorTextareaRef.value;
    const start = textarea?.selectionStart ?? draftContent.value.length;
    const end = textarea?.selectionEnd ?? start;
    const result = applyMarkdownFormat(
        draftContent.value,
        start,
        end,
        action,
        activeDocument.value.title,
    );

    draftContent.value = result.value;

    void nextTick(() => {
        const el = editorTextareaRef.value;
        if (el) {
            el.focus();
            el.setSelectionRange(result.selectionStart, result.selectionEnd);
        }
    });
}

function handleMainGuideInsert(action: MarkdownFormatId): void {
    markdownGuideOpen.value = false;
    applyEditorFormat(action);
}

function applyNewSopFormat(action: MarkdownFormatId): void {
    if (creatingSop.value) {
        return;
    }

    const textarea = newSopTextareaRef.value;
    const start = textarea?.selectionStart ?? newSopContent.value.length;
    const end = textarea?.selectionEnd ?? start;
    const result = applyMarkdownFormat(
        newSopContent.value,
        start,
        end,
        action,
        newSopName.value.trim() || undefined,
    );

    newSopContent.value = result.value;

    void nextTick(() => {
        const el = newSopTextareaRef.value;
        if (el) {
            el.focus();
            el.setSelectionRange(result.selectionStart, result.selectionEnd);
        }
    });
}

function tokenKey(tokenIndex: number, token: InlineToken): string {
    return `${tokenIndex}-${token.type}-${token.text.slice(0, 12)}`;
}
</script>

<template>
    <Head title="SOP Management" />

    <div class="space-y-3">
        <!-- Workbench Container -->
        <div
            class="grid min-h-[calc(100vh-8.5rem)] grid-cols-1 overflow-hidden rounded-[8px] border border-default bg-surface lg:grid-cols-[280px_minmax(0,1fr)]"
        >
            <!-- Left Directory Pane -->
            <aside
                aria-label="SOP modules directory"
                class="flex min-w-0 flex-col border-b border-default bg-surface lg:border-b-0 lg:border-r"
            >
                <div class="flex h-11 shrink-0 items-center justify-between gap-2 border-b border-default px-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <h2 class="truncate text-[13px] font-semibold text-primary">
                            SOP Directory
                        </h2>
                        <Badge variant="neutral" class="tabular-nums">
                            {{ totalSopCount }}
                        </Badge>
                    </div>

                    <Button
                        variant="primary"
                        size="sm"
                        data-testid="open-new-sop-dialog"
                        @click="openNewSopDialog()"
                    >
                        <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <span>New SOP</span>
                    </Button>
                </div>

                <!-- Directory Filter -->
                <div class="border-b border-default p-2.5">
                    <label for="sop-directory-filter" class="sr-only">
                        Filter SOPs by module or filename
                    </label>
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-secondary"
                            :stroke-width="1.75"
                            aria-hidden="true"
                        />
                        <input
                            id="sop-directory-filter"
                            v-model="searchQuery"
                            type="search"
                            autocomplete="off"
                            placeholder="Filter SOPs or modules... (/)"
                            class="h-8 w-full rounded-[6px] border border-default bg-subtle/60 pl-8 pr-2.5 text-[12px] text-primary placeholder:text-muted transition-colors duration-150 focus:bg-surface focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-accent-primary"
                        />
                    </div>
                </div>

                <!-- Directory List States -->
                <div v-if="props.initialState === 'loading'" class="space-y-2 p-3">
                    <div v-for="n in 4" :key="n" class="h-8 rounded-[6px] bg-subtle" />
                </div>

                <div
                    v-else-if="filteredModules.length === 0"
                    class="flex flex-1 flex-col items-center justify-center gap-2 px-4 py-8 text-center"
                >
                    <FileText class="h-5 w-5 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                    <p class="text-[13px] font-medium text-primary">
                        {{ searchQuery.trim() !== '' ? 'No matching SOPs' : 'No SOP modules found' }}
                    </p>
                    <p class="text-[12px] text-secondary">
                        {{
                            searchQuery.trim() !== ''
                                ? `Nothing in context/sops matched "${searchQuery.trim()}".`
                                : 'Create your first SOP to write a Markdown file into context/sops.'
                        }}
                    </p>
                    <Button
                        v-if="searchQuery.trim() !== ''"
                        variant="secondary"
                        size="sm"
                        @click="searchQuery = ''"
                    >
                        Clear filter
                    </Button>
                    <Button v-else variant="primary" size="sm" @click="openNewSopDialog()">
                        New SOP
                    </Button>
                </div>

                <nav
                    v-else
                    aria-label="SOP files by module"
                    class="flex-1 overflow-y-auto p-2 space-y-3"
                >
                    <section v-for="group in filteredModules" :key="group.module" class="space-y-1">
                        <div class="flex items-center justify-between gap-2 px-1.5 py-0.5">
                            <div class="flex min-w-0 items-center gap-1.5">
                                <span class="truncate text-[11px] font-semibold uppercase tracking-wider text-secondary">
                                    {{ group.label }}
                                </span>
                                <span class="font-mono text-[11px] tabular-nums text-muted">
                                    {{ group.sops.length }}
                                </span>
                            </div>

                            <button
                                type="button"
                                :data-testid="`quick-add-sop-${group.module}`"
                                :aria-label="`Add SOP to ${group.label}`"
                                class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-[4px] text-secondary transition-colors duration-150 hover:bg-subtle hover:text-primary cursor-pointer"
                                @click="openNewSopDialog(group.module)"
                            >
                                <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                            </button>
                        </div>

                        <p
                            v-if="group.sops.length === 0"
                            class="px-2 py-1 text-[12px] text-muted"
                        >
                            No SOP configured
                        </p>

                        <ul v-else class="space-y-0.5">
                            <li v-for="sop in group.sops" :key="`${sop.module}/${sop.slug}`">
                                <button
                                    type="button"
                                    :data-testid="`sop-item-${sop.module}-${sop.slug}`"
                                    :aria-current="isSopSelected(sop.module, sop.slug) ? 'true' : undefined"
                                    :class="[
                                        'flex w-full min-w-0 items-start gap-2 rounded-[6px] border px-2 py-1.5 text-left transition-colors duration-150 cursor-pointer',
                                        isSopSelected(sop.module, sop.slug)
                                            ? 'border-default bg-subtle text-primary'
                                            : 'border-transparent text-secondary hover:bg-subtle/60 hover:text-primary',
                                    ]"
                                    @click="selectSop(sop.module, sop.slug)"
                                >
                                    <FileText
                                        :class="[
                                            'mt-0.5 h-3.5 w-3.5 shrink-0',
                                            isSopSelected(sop.module, sop.slug)
                                                ? 'text-accent-primary'
                                                : 'text-secondary',
                                        ]"
                                        :stroke-width="1.75"
                                        aria-hidden="true"
                                    />
                                    <span class="min-w-0 flex-1 space-y-0.5">
                                        <span class="block truncate text-[13px] font-medium leading-4 text-primary">
                                            {{ sop.title }}
                                        </span>
                                        <span class="flex min-w-0 whitespace-nowrap font-mono text-[11px] leading-4 text-muted">
                                            <span class="overflow-hidden text-ellipsis">{{ sop.slug }}</span>.md
                                        </span>
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </section>
                </nav>
            </aside>

            <!-- Right Markdown Text Box Editor Pane -->
            <section aria-label="SOP Markdown editor" class="flex min-w-0 flex-1 flex-col bg-surface">
                <!-- Loading State -->
                <div
                    v-if="props.initialState === 'loading' || loadingDocument"
                    data-testid="sop-loading-state"
                    class="flex flex-1 flex-col gap-3 p-4"
                >
                    <div class="h-8 w-1/2 rounded bg-subtle" />
                    <div class="h-64 flex-1 rounded bg-subtle" />
                </div>

                <!-- Initial Page Error State -->
                <div
                    v-else-if="props.initialState === 'error'"
                    role="alert"
                    data-testid="sop-error-state"
                    class="m-4 flex items-start gap-2 rounded-[6px] border border-status-danger/30 bg-status-danger/10 p-3 text-[13px] text-status-danger"
                >
                    <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                    <span>{{ props.initialError || 'Unable to load SOP files.' }}</span>
                </div>

                <!-- Empty State (no SOP selected or directory empty) -->
                <div
                    v-else-if="!activeDocument"
                    data-testid="sop-empty-state"
                    class="flex flex-1 flex-col items-center justify-center gap-2.5 px-6 py-12 text-center"
                >
                    <FolderPlus class="h-6 w-6 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                    <h3 class="text-[14px] font-semibold text-primary">
                        Select or create an SOP
                    </h3>
                    <p class="max-w-md text-[13px] text-secondary">
                        Choose an existing Markdown procedure from the directory, or add a new SOP file under an existing or new module folder in <code class="font-mono text-[12px]">context/sops</code>.
                    </p>
                    <Button variant="primary" size="sm" @click="openNewSopDialog()">
                        <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <span>New SOP</span>
                    </Button>
                </div>

                <!-- Active Document Editor -->
                <template v-else>
                    <!-- Metadata & Actions Bar -->
                    <div
                        class="flex flex-wrap items-center justify-between gap-2 border-b border-default bg-surface px-3 py-2 sm:px-4"
                    >
                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                            <Badge variant="cobalt">{{ activeModuleLabel }}</Badge>

                            <div class="flex min-w-0 items-center gap-1">
                                <span
                                    data-testid="active-sop-path"
                                    class="min-w-0 truncate font-mono text-[12px] font-medium text-primary"
                                >
                                    {{ activeDocument.relative_path }}
                                </span>
                                <button
                                    type="button"
                                    :aria-label="copiedPath ? 'Path copied' : 'Copy relative file path'"
                                    class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-[4px] text-secondary transition-colors duration-150 hover:bg-subtle hover:text-primary cursor-pointer"
                                    @click="copyRelativePath"
                                >
                                    <Check
                                        v-if="copiedPath"
                                        class="h-3.5 w-3.5 text-status-success"
                                        :stroke-width="1.75"
                                        aria-hidden="true"
                                    />
                                    <Copy v-else class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                </button>
                            </div>

                            <span class="text-[11px] text-secondary whitespace-nowrap">
                                Updated {{ formatTimestamp(activeDocument.updated_at) }}
                            </span>

                            <Badge v-if="isDirty" variant="warning" data-testid="sop-unsaved-badge">
                                Unsaved
                            </Badge>
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            <div
                                role="tablist"
                                aria-label="Editor view mode"
                                class="flex items-center gap-0.5 rounded-[6px] border border-default bg-subtle p-0.5"
                            >
                                <button
                                    type="button"
                                    role="tab"
                                    data-testid="tab-write"
                                    :aria-selected="editorTab === 'write' ? 'true' : 'false'"
                                    :class="[
                                        'h-6 rounded-[4px] px-2.5 text-[12px] font-medium transition-colors duration-150 cursor-pointer',
                                        editorTab === 'write'
                                            ? 'border border-default bg-surface text-primary'
                                            : 'text-secondary hover:text-primary',
                                    ]"
                                    @click="editorTab = 'write'"
                                >
                                    Write
                                </button>
                                <button
                                    type="button"
                                    role="tab"
                                    data-testid="tab-preview"
                                    :aria-selected="editorTab === 'preview' ? 'true' : 'false'"
                                    :class="[
                                        'h-6 rounded-[4px] px-2.5 text-[12px] font-medium transition-colors duration-150 cursor-pointer',
                                        editorTab === 'preview'
                                            ? 'border border-default bg-surface text-primary'
                                            : 'text-secondary hover:text-primary',
                                    ]"
                                    @click="editorTab = 'preview'"
                                >
                                    Preview
                                </button>
                            </div>

                            <Button
                                variant="secondary"
                                size="sm"
                                data-testid="discard-sop-button"
                                :disabled="!isDirty || saving"
                                @click="discardChanges"
                            >
                                <RotateCcw class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                <span>Discard</span>
                            </Button>

                            <Button
                                variant="primary"
                                size="sm"
                                data-testid="save-sop-button"
                                :loading="saving"
                                :disabled="!isDirty || saving"
                                @click="saveCurrentSop"
                            >
                                <span>Save SOP</span>
                                <kbd
                                    class="hidden sm:inline-block rounded bg-accent-hover px-1 py-0.5 font-mono text-[10px] font-medium text-accent-contrast"
                                >
                                    Cmd+S
                                </kbd>
                            </Button>
                        </div>
                    </div>

                    <!-- Status, Error & Conflict Banners -->
                    <div v-if="statusMessage || editorError || conflictState" class="space-y-2 border-b border-default px-4 py-2.5">
                        <div
                            v-if="statusMessage"
                            role="status"
                            data-testid="sop-saved-banner"
                            class="flex items-center gap-2 rounded-[6px] border border-status-success/30 bg-status-success/10 px-3 py-1.5 text-[12px] text-status-success"
                        >
                            <CheckCircle2 class="h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                            <span>{{ statusMessage }}</span>
                        </div>

                        <div
                            v-if="editorError"
                            id="sop-editor-error"
                            role="alert"
                            data-testid="sop-error-banner"
                            class="flex items-center gap-2 rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3 py-1.5 text-[12px] text-status-danger"
                        >
                            <AlertCircle class="h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                            <span>{{ editorError }}</span>
                        </div>

                        <div
                            v-if="conflictState"
                            role="alert"
                            data-testid="sop-conflict-banner"
                            class="flex flex-wrap items-center justify-between gap-2 rounded-[6px] border border-status-warning/30 bg-status-warning/10 px-3 py-2 text-[12px] text-status-warning"
                        >
                            <div class="flex items-center gap-2">
                                <AlertTriangle class="h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                                <span>{{ conflictState.message }}</span>
                            </div>
                            <Button
                                variant="secondary"
                                size="sm"
                                data-testid="reload-latest-sop"
                                @click="loadLatestFromConflict"
                            >
                                Load Latest Version
                            </Button>
                        </div>
                    </div>

                    <!-- Raw Markdown Text Box -->
                    <div v-if="editorTab === 'write'" class="flex min-h-0 flex-1 flex-col">
                        <!-- Markdown Formatting Toolbar & Guide Trigger -->
                        <div
                            ref="editorToolbarRef"
                            role="toolbar"
                            v-bind="{ focusgroup: 'toolbar wrap' }"
                            aria-label="Markdown formatting"
                            aria-controls="sop-editor-textarea"
                            data-testid="sop-markdown-toolbar"
                            class="flex flex-wrap items-center justify-between gap-2 border-b border-default bg-subtle/60 px-3 py-1.5"
                        >
                            <div class="flex flex-wrap items-center gap-1">
                                <div role="group" aria-label="Headings and emphasis" class="flex items-center gap-0.5">
                                    <button
                                        type="button"
                                        data-testid="toolbar-heading"
                                        aria-label="Section heading"
                                        title="Section heading (## Heading)"
                                        :aria-disabled="saving ? 'true' : undefined"
                                        class="inline-flex min-h-7 min-w-7 items-center justify-center rounded-[4px] border border-transparent text-secondary transition-colors duration-150 hover:bg-surface hover:text-primary hover:border-default aria-disabled:opacity-50 cursor-pointer"
                                        @click="applyEditorFormat('heading')"
                                    >
                                        <Heading2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                    </button>
                                    <button
                                        type="button"
                                        data-testid="toolbar-bold"
                                        aria-label="Bold"
                                        title="Bold (**text**)"
                                        :aria-disabled="saving ? 'true' : undefined"
                                        class="inline-flex min-h-7 min-w-7 items-center justify-center rounded-[4px] border border-transparent text-secondary transition-colors duration-150 hover:bg-surface hover:text-primary hover:border-default aria-disabled:opacity-50 cursor-pointer"
                                        @click="applyEditorFormat('bold')"
                                    >
                                        <Bold class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                    </button>
                                    <button
                                        type="button"
                                        data-testid="toolbar-italic"
                                        aria-label="Italic"
                                        title="Italic (*text*)"
                                        :aria-disabled="saving ? 'true' : undefined"
                                        class="inline-flex min-h-7 min-w-7 items-center justify-center rounded-[4px] border border-transparent text-secondary transition-colors duration-150 hover:bg-surface hover:text-primary hover:border-default aria-disabled:opacity-50 cursor-pointer"
                                        @click="applyEditorFormat('italic')"
                                    >
                                        <Italic class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                    </button>
                                    <button
                                        type="button"
                                        data-testid="toolbar-code"
                                        aria-label="Inline code"
                                        title="Inline code (`code`)"
                                        :aria-disabled="saving ? 'true' : undefined"
                                        class="inline-flex min-h-7 min-w-7 items-center justify-center rounded-[4px] border border-transparent text-secondary transition-colors duration-150 hover:bg-surface hover:text-primary hover:border-default aria-disabled:opacity-50 cursor-pointer"
                                        @click="applyEditorFormat('code')"
                                    >
                                        <Code class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                    </button>
                                    <button
                                        type="button"
                                        data-testid="toolbar-link"
                                        aria-label="Insert link"
                                        title="Link ([label](/path))"
                                        :aria-disabled="saving ? 'true' : undefined"
                                        class="inline-flex min-h-7 min-w-7 items-center justify-center rounded-[4px] border border-transparent text-secondary transition-colors duration-150 hover:bg-surface hover:text-primary hover:border-default aria-disabled:opacity-50 cursor-pointer"
                                        @click="applyEditorFormat('link')"
                                    >
                                        <Link2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                    </button>
                                </div>

                                <span role="separator" aria-orientation="vertical" class="mx-1 h-4 border-l border-default" />

                                <div role="group" aria-label="Structure" class="flex items-center gap-0.5">
                                    <button
                                        type="button"
                                        data-testid="toolbar-bullet-list"
                                        aria-label="Bullet list"
                                        title="Bullet list (- item)"
                                        :aria-disabled="saving ? 'true' : undefined"
                                        class="inline-flex min-h-7 min-w-7 items-center justify-center rounded-[4px] border border-transparent text-secondary transition-colors duration-150 hover:bg-surface hover:text-primary hover:border-default aria-disabled:opacity-50 cursor-pointer"
                                        @click="applyEditorFormat('bulletList')"
                                    >
                                        <List class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                    </button>
                                    <button
                                        type="button"
                                        data-testid="toolbar-ordered-list"
                                        aria-label="Numbered list"
                                        title="Numbered list (1. step)"
                                        :aria-disabled="saving ? 'true' : undefined"
                                        class="inline-flex min-h-7 min-w-7 items-center justify-center rounded-[4px] border border-transparent text-secondary transition-colors duration-150 hover:bg-surface hover:text-primary hover:border-default aria-disabled:opacity-50 cursor-pointer"
                                        @click="applyEditorFormat('orderedList')"
                                    >
                                        <ListOrdered class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                    </button>
                                    <button
                                        type="button"
                                        data-testid="toolbar-blockquote"
                                        aria-label="Rule callout"
                                        title="Rule callout (> Golden Rule)"
                                        :aria-disabled="saving ? 'true' : undefined"
                                        class="inline-flex min-h-7 min-w-7 items-center justify-center rounded-[4px] border border-transparent text-secondary transition-colors duration-150 hover:bg-surface hover:text-primary hover:border-default aria-disabled:opacity-50 cursor-pointer"
                                        @click="applyEditorFormat('blockquote')"
                                    >
                                        <Quote class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                    </button>
                                    <button
                                        type="button"
                                        data-testid="toolbar-table"
                                        aria-label="Insert table"
                                        title="Markdown table"
                                        :aria-disabled="saving ? 'true' : undefined"
                                        class="inline-flex min-h-7 min-w-7 items-center justify-center rounded-[4px] border border-transparent text-secondary transition-colors duration-150 hover:bg-surface hover:text-primary hover:border-default aria-disabled:opacity-50 cursor-pointer"
                                        @click="applyEditorFormat('table')"
                                    >
                                        <Table class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                    </button>
                                    <button
                                        type="button"
                                        data-testid="toolbar-code-block"
                                        aria-label="Code block"
                                        title="Fenced code block"
                                        :aria-disabled="saving ? 'true' : undefined"
                                        class="inline-flex min-h-7 min-w-7 items-center justify-center rounded-[4px] border border-transparent text-secondary transition-colors duration-150 hover:bg-surface hover:text-primary hover:border-default aria-disabled:opacity-50 cursor-pointer"
                                        @click="applyEditorFormat('codeBlock')"
                                    >
                                        <Code2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                    </button>
                                </div>
                            </div>

                            <div role="group" aria-label="Markdown guide" class="flex items-center">
                                <button
                                    type="button"
                                    data-testid="open-markdown-guide"
                                    aria-label="Markdown Guide"
                                    title="Open Markdown formatting guide and starter template"
                                    class="inline-flex min-h-7 items-center gap-1.5 rounded-[6px] border border-default bg-surface px-2.5 text-[12px] font-medium text-primary transition-colors duration-150 hover:bg-subtle cursor-pointer"
                                    @click="markdownGuideOpen = true"
                                >
                                    <BookOpen class="h-3.5 w-3.5 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                                    <span>Markdown Guide</span>
                                </button>
                            </div>
                        </div>

                        <label for="sop-editor-textarea" class="sr-only">
                            SOP Markdown content
                        </label>
                        <textarea
                            id="sop-editor-textarea"
                            ref="editorTextareaRef"
                            v-model="draftContent"
                            data-testid="sop-editor-textarea"
                            :disabled="saving"
                            :aria-invalid="editorError ? 'true' : 'false'"
                            :aria-describedby="editorError ? 'sop-editor-error' : undefined"
                            spellcheck="false"
                            placeholder="# Standard Operating Procedure&#10;&#10;Write Markdown guidelines for this module..."
                            class="min-h-[380px] w-full flex-1 resize-none border-0 bg-surface p-4 font-mono text-[13px] leading-relaxed text-primary placeholder:text-muted focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-accent-primary disabled:opacity-60"
                        />

                        <!-- Compact Footer Bar -->
                        <div
                            class="flex h-7 shrink-0 items-center justify-between border-t border-default bg-subtle/60 px-4 font-mono text-[11px] tabular-nums text-secondary"
                        >
                            <span>UTF-8 · LF · Markdown</span>
                            <span>
                                {{ lineCount }} lines · {{ wordCount }} words · {{ charCount }} chars
                            </span>
                        </div>
                    </div>

                    <!-- Safe Vue-Rendered Markdown Preview -->
                    <div
                        v-else
                        data-testid="sop-markdown-preview"
                        class="flex-1 overflow-y-auto p-5 space-y-3 text-[14px] leading-relaxed text-primary"
                    >
                        <p v-if="draftContent.trim() === ''" class="text-[13px] text-muted">
                            No Markdown content yet. Switch to Write to begin editing.
                        </p>

                        <template v-for="(block, blockIdx) in previewBlocks" :key="blockIdx">
                            <h1
                                v-if="block.type === 'heading' && block.level === 1"
                                class="border-b border-default pb-2 text-[20px] font-semibold tracking-[-0.02em] text-primary"
                            >
                                <template v-for="(token, tIdx) in block.tokens" :key="tokenKey(tIdx, token)">
                                    <strong v-if="token.type === 'strong'">{{ token.text }}</strong>
                                    <em v-else-if="token.type === 'em'">{{ token.text }}</em>
                                    <code
                                        v-else-if="token.type === 'code'"
                                        class="rounded bg-subtle px-1 py-0.5 font-mono text-[12px]"
                                    >{{ token.text }}</code>
                                    <a
                                        v-else-if="token.type === 'link'"
                                        :href="token.href"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-accent-primary underline underline-offset-2"
                                    >{{ token.text }}</a>
                                    <template v-else>{{ token.text }}</template>
                                </template>
                            </h1>

                            <h2
                                v-else-if="block.type === 'heading' && block.level === 2"
                                class="pt-2 text-[16px] font-semibold tracking-[-0.01em] text-primary"
                            >
                                <template v-for="(token, tIdx) in block.tokens" :key="tokenKey(tIdx, token)">
                                    <strong v-if="token.type === 'strong'">{{ token.text }}</strong>
                                    <em v-else-if="token.type === 'em'">{{ token.text }}</em>
                                    <code
                                        v-else-if="token.type === 'code'"
                                        class="rounded bg-subtle px-1 py-0.5 font-mono text-[12px]"
                                    >{{ token.text }}</code>
                                    <a
                                        v-else-if="token.type === 'link'"
                                        :href="token.href"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-accent-primary underline underline-offset-2"
                                    >{{ token.text }}</a>
                                    <template v-else>{{ token.text }}</template>
                                </template>
                            </h2>

                            <h3
                                v-else-if="block.type === 'heading' && block.level === 3"
                                class="pt-1 text-[14px] font-semibold text-primary"
                            >
                                <template v-for="(token, tIdx) in block.tokens" :key="tokenKey(tIdx, token)">
                                    <strong v-if="token.type === 'strong'">{{ token.text }}</strong>
                                    <em v-else-if="token.type === 'em'">{{ token.text }}</em>
                                    <code
                                        v-else-if="token.type === 'code'"
                                        class="rounded bg-subtle px-1 py-0.5 font-mono text-[12px]"
                                    >{{ token.text }}</code>
                                    <template v-else>{{ token.text }}</template>
                                </template>
                            </h3>

                            <h4
                                v-else-if="block.type === 'heading' && block.level === 4"
                                class="text-[13px] font-semibold text-secondary"
                            >
                                <template v-for="(token, tIdx) in block.tokens" :key="tokenKey(tIdx, token)">
                                    {{ token.text }}
                                </template>
                            </h4>

                            <p v-else-if="block.type === 'paragraph'" class="text-[13px] leading-6 text-primary">
                                <template v-for="(token, tIdx) in block.tokens" :key="tokenKey(tIdx, token)">
                                    <strong v-if="token.type === 'strong'">{{ token.text }}</strong>
                                    <em v-else-if="token.type === 'em'">{{ token.text }}</em>
                                    <code
                                        v-else-if="token.type === 'code'"
                                        class="rounded border border-default bg-subtle px-1 py-0.5 font-mono text-[12px]"
                                    >{{ token.text }}</code>
                                    <a
                                        v-else-if="token.type === 'link'"
                                        :href="token.href"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-accent-primary underline underline-offset-2"
                                    >{{ token.text }}</a>
                                    <template v-else>{{ token.text }}</template>
                                </template>
                            </p>

                            <ul
                                v-else-if="block.type === 'list' && !block.ordered"
                                class="list-disc space-y-1 pl-5 text-[13px] leading-6 text-primary"
                            >
                                <li v-for="(itemTokens, itemIdx) in block.items" :key="itemIdx">
                                    <template v-for="(token, tIdx) in itemTokens" :key="tokenKey(tIdx, token)">
                                        <strong v-if="token.type === 'strong'">{{ token.text }}</strong>
                                        <em v-else-if="token.type === 'em'">{{ token.text }}</em>
                                        <code
                                            v-else-if="token.type === 'code'"
                                            class="rounded border border-default bg-subtle px-1 py-0.5 font-mono text-[12px]"
                                        >{{ token.text }}</code>
                                        <a
                                            v-else-if="token.type === 'link'"
                                            :href="token.href"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="text-accent-primary underline underline-offset-2"
                                        >{{ token.text }}</a>
                                        <template v-else>{{ token.text }}</template>
                                    </template>
                                </li>
                            </ul>

                            <ol
                                v-else-if="block.type === 'list' && block.ordered"
                                class="list-decimal space-y-1 pl-5 text-[13px] leading-6 text-primary"
                            >
                                <li v-for="(itemTokens, itemIdx) in block.items" :key="itemIdx">
                                    <template v-for="(token, tIdx) in itemTokens" :key="tokenKey(tIdx, token)">
                                        <strong v-if="token.type === 'strong'">{{ token.text }}</strong>
                                        <em v-else-if="token.type === 'em'">{{ token.text }}</em>
                                        <code
                                            v-else-if="token.type === 'code'"
                                            class="rounded border border-default bg-subtle px-1 py-0.5 font-mono text-[12px]"
                                        >{{ token.text }}</code>
                                        <a
                                            v-else-if="token.type === 'link'"
                                            :href="token.href"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="text-accent-primary underline underline-offset-2"
                                        >{{ token.text }}</a>
                                        <template v-else>{{ token.text }}</template>
                                    </template>
                                </li>
                            </ol>

                            <blockquote
                                v-else-if="block.type === 'blockquote'"
                                class="rounded-[6px] border border-default bg-subtle/60 px-3.5 py-2.5 text-[13px] text-secondary space-y-1"
                            >
                                <p v-for="(lineTokens, lineIdx) in block.lines" :key="lineIdx">
                                    <template v-for="(token, tIdx) in lineTokens" :key="tokenKey(tIdx, token)">
                                        <strong v-if="token.type === 'strong'">{{ token.text }}</strong>
                                        <em v-else-if="token.type === 'em'">{{ token.text }}</em>
                                        <code
                                            v-else-if="token.type === 'code'"
                                            class="rounded border border-default bg-surface px-1 py-0.5 font-mono text-[12px]"
                                        >{{ token.text }}</code>
                                        <template v-else>{{ token.text }}</template>
                                    </template>
                                </p>
                            </blockquote>

                            <pre
                                v-else-if="block.type === 'code'"
                                class="overflow-x-auto rounded-[6px] border border-default bg-subtle p-3 font-mono text-[12px] leading-5 text-primary"
                            ><code>{{ block.content }}</code></pre>

                            <div
                                v-else-if="block.type === 'table'"
                                class="overflow-x-auto rounded-[6px] border border-default"
                            >
                                <table class="w-full border-collapse text-left text-[12px]">
                                    <thead>
                                        <tr class="border-b border-default bg-subtle text-secondary">
                                            <th
                                                v-for="(headerTokens, hIdx) in block.headers"
                                                :key="hIdx"
                                                class="px-3 py-1.5 font-semibold"
                                            >
                                                <template v-for="(token, tIdx) in headerTokens" :key="tokenKey(tIdx, token)">
                                                    <code
                                                        v-if="token.type === 'code'"
                                                        class="font-mono text-[11px]"
                                                    >{{ token.text }}</code>
                                                    <template v-else>{{ token.text }}</template>
                                                </template>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-default">
                                        <tr v-for="(row, rIdx) in block.rows" :key="rIdx">
                                            <td
                                                v-for="(cellTokens, cIdx) in row"
                                                :key="cIdx"
                                                class="px-3 py-1.5 text-primary"
                                            >
                                                <template v-for="(token, tIdx) in cellTokens" :key="tokenKey(tIdx, token)">
                                                    <strong v-if="token.type === 'strong'">{{ token.text }}</strong>
                                                    <code
                                                        v-else-if="token.type === 'code'"
                                                        class="rounded bg-subtle px-1 py-0.5 font-mono text-[11px]"
                                                    >{{ token.text }}</code>
                                                    <template v-else>{{ token.text }}</template>
                                                </template>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <hr v-else-if="block.type === 'hr'" class="border-default" />
                        </template>
                    </div>
                </template>
            </section>
        </div>

        <!-- New SOP Dialog -->
        <Dialog
            :open="newSopDialogOpen"
            title="New Standard Operating Procedure"
            description="Creates a Markdown file inside context/sops under an existing or new module folder."
            max-width-class="max-w-xl"
            @update:open="(open) => (open ? (newSopDialogOpen = true) : closeNewSopDialog())"
        >
            <form
                ref="newSopFormRef"
                data-testid="new-sop-dialog"
                class="space-y-3"
                @submit.prevent="handleCreateSop"
            >
                <div
                    v-if="createGlobalError"
                    role="alert"
                    data-testid="new-sop-error-banner"
                    class="flex items-center gap-2 rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                >
                    <AlertCircle class="h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                    <span>{{ createGlobalError }}</span>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="new-sop-module" class="block text-[12px] font-medium text-primary">
                            Module
                        </label>
                        <select
                            id="new-sop-module"
                            v-model="moduleSelectMode"
                            data-testid="new-sop-module-select"
                            :disabled="creatingSop"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-accent-primary"
                        >
                            <option
                                v-for="group in localModules"
                                :key="group.module"
                                :value="group.module"
                            >
                                {{ group.label }} ({{ group.module }})
                            </option>
                            <option :value="NEW_MODULE_OPTION">+ Add new module...</option>
                        </select>
                        <p
                            v-if="moduleSelectMode !== NEW_MODULE_OPTION && createFieldErrors.module"
                            role="alert"
                            class="text-[12px] text-status-danger"
                        >
                            {{ createFieldErrors.module }}
                        </p>
                    </div>

                    <div class="space-y-1">
                        <label for="new-sop-name" class="block text-[12px] font-medium text-primary">
                            SOP name
                        </label>
                        <Input
                            id="new-sop-name"
                            v-model="newSopName"
                            data-testid="new-sop-name"
                            name="name"
                            type="text"
                            autocomplete="off"
                            placeholder="Enterprise Deal Qualification"
                            data-error="new-sop-name-error"
                            :disabled="creatingSop"
                            :error-message="createFieldErrors.name ?? ''"
                            required
                        />
                        <p
                            id="new-sop-name-error"
                            class="hidden text-[12px] text-status-danger [:has(#new-sop-name:user-invalid)_&]:block [:has(#new-sop-name.user-invalid-fallback)_&]:block"
                        >
                            Enter a name for the SOP file.
                        </p>
                    </div>
                </div>

                <div v-if="moduleSelectMode === NEW_MODULE_OPTION" class="space-y-1">
                    <label for="new-sop-custom-module" class="block text-[12px] font-medium text-primary">
                        New module name
                    </label>
                    <Input
                        id="new-sop-custom-module"
                        v-model="customModuleName"
                        data-testid="new-sop-custom-module"
                        name="module"
                        type="text"
                        autocomplete="off"
                        placeholder="Renewals"
                        data-error="new-sop-custom-module-error"
                        :disabled="creatingSop"
                        :error-message="createFieldErrors.module ?? ''"
                        required
                    />
                    <p
                        id="new-sop-custom-module-error"
                        class="hidden text-[12px] text-status-danger [:has(#new-sop-custom-module:user-invalid)_&]:block [:has(#new-sop-custom-module.user-invalid-fallback)_&]:block"
                    >
                        Enter a module folder name.
                    </p>
                </div>

                <div class="rounded-[6px] border border-default bg-subtle/70 px-3 py-2">
                    <p class="text-[11px] font-medium text-secondary">Target Markdown File</p>
                    <p
                        data-testid="new-sop-path-preview"
                        class="mt-0.5 truncate font-mono text-[12px] text-primary"
                    >
                        {{ newSopPathPreview }}
                    </p>
                </div>

                <div class="space-y-1.5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <label for="new-sop-content" class="block text-[12px] font-medium text-primary">
                            SOP content (Markdown)
                        </label>

                        <div class="flex items-center gap-1.5">
                            <Button
                                variant="secondary"
                                size="sm"
                                type="button"
                                data-testid="new-sop-insert-template"
                                :disabled="creatingSop"
                                @click="applyNewSopFormat('template')"
                            >
                                <FileCode2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                <span>Insert SOP Template</span>
                            </Button>

                            <Button
                                variant="secondary"
                                size="sm"
                                type="button"
                                data-testid="toggle-new-sop-markdown-guide"
                                :aria-expanded="showNewSopGuide ? 'true' : 'false'"
                                aria-controls="new-sop-markdown-guide"
                                @click="showNewSopGuide = !showNewSopGuide"
                            >
                                <BookOpen class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                <span>Markdown Guide</span>
                            </Button>
                        </div>
                    </div>

                    <div
                        v-if="showNewSopGuide"
                        id="new-sop-markdown-guide"
                        data-testid="new-sop-markdown-guide"
                        class="max-h-60 overflow-y-auto rounded-[6px] border border-default bg-subtle/40 p-2"
                    >
                        <MarkdownGuideReference
                            compact
                            :disabled="creatingSop"
                            @insert="applyNewSopFormat"
                            @insert-template="applyNewSopFormat('template')"
                        />
                    </div>

                    <textarea
                        id="new-sop-content"
                        ref="newSopTextareaRef"
                        v-model="newSopContent"
                        data-testid="new-sop-content"
                        name="content"
                        rows="10"
                        spellcheck="false"
                        :disabled="creatingSop"
                        :aria-invalid="createFieldErrors.content ? 'true' : 'false'"
                        :aria-describedby="createFieldErrors.content ? 'new-sop-content-error' : undefined"
                        placeholder="# Standard Operating Procedure&#10;&#10;## 1. Purpose&#10;Describe rules, gates, and approval requirements..."
                        class="w-full rounded-[6px] border border-default bg-surface p-3 font-mono text-[12px] leading-relaxed text-primary placeholder:text-muted focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-accent-primary disabled:opacity-50"
                    />
                    <p
                        v-if="createFieldErrors.content"
                        id="new-sop-content-error"
                        role="alert"
                        class="text-[12px] text-status-danger"
                    >
                        {{ createFieldErrors.content }}
                    </p>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-default pt-3">
                    <Button
                        variant="secondary"
                        size="md"
                        type="button"
                        :disabled="creatingSop"
                        @click="closeNewSopDialog"
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        size="md"
                        type="submit"
                        data-testid="submit-new-sop"
                        :loading="creatingSop"
                    >
                        Create SOP
                    </Button>
                </div>
            </form>
        </Dialog>

        <!-- Markdown Formatting Guide Dialog -->
        <MarkdownGuideDialog
            :open="markdownGuideOpen"
            :disabled="saving"
            @update:open="markdownGuideOpen = $event"
            @insert="handleMainGuideInsert"
            @insert-template="handleMainGuideInsert('template')"
        />

        <!-- Unsaved Changes Confirmation Dialog -->
        <Dialog
            :open="unsavedDialogOpen"
            title="Discard unsaved changes?"
            description="You have unsaved edits in the current SOP. Switching files will discard them."
            max-width-class="max-w-sm"
            @update:open="unsavedDialogOpen = $event"
        >
            <div data-testid="unsaved-changes-dialog" class="flex items-center justify-end gap-2 pt-1">
                <Button
                    variant="secondary"
                    size="sm"
                    data-testid="keep-editing-sop"
                    @click="unsavedDialogOpen = false"
                >
                    Keep Editing
                </Button>
                <Button
                    variant="danger"
                    size="sm"
                    data-testid="confirm-discard-switch"
                    @click="confirmDiscardAndSwitch"
                >
                    Discard &amp; Switch
                </Button>
            </div>
        </Dialog>
    </div>
</template>
