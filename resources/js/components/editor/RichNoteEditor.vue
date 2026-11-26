<script setup lang="ts">
import { computed, onBeforeUnmount, ref, shallowRef, useId, watch, type ShallowRef } from 'vue';
import { Editor, EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import {
    Bold,
    Code2,
    Italic,
    Link2,
    List,
    ListOrdered,
    Pin,
    Send,
    Unlink,
} from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import { isSafeExternalUrl } from '@/modules/crm/utils/formatters';

export type ProseMirrorDoc = Record<string, any>;

const props = withDefaults(
    defineProps<{
        submitting?: boolean;
        disabled?: boolean;
        errorMessage?: string;
        placeholder?: string;
        submitLabel?: string;
        allowPin?: boolean;
        initialPinned?: boolean;
        resetKey?: number;
    }>(),
    {
        submitting: false,
        disabled: false,
        errorMessage: '',
        placeholder: 'Write an internal note... (Cmd/Ctrl+Enter to save)',
        submitLabel: 'Save note',
        allowPin: true,
        initialPinned: false,
        resetKey: 0,
    },
);

const emit = defineEmits<{
    submit: [payload: { body_json: ProseMirrorDoc; is_pinned: boolean }];
}>();

const instanceId = useId();
const editorLabelId = `note-editor-label-${instanceId}`;
const pinCheckboxId = `note-pin-${instanceId}`;
const linkInputId = `note-link-url-${instanceId}`;

const isPinned = ref<boolean>(props.initialPinned);
const validationError = ref<string>('');
const showLinkBar = ref<boolean>(false);
const linkUrlDraft = ref<string>('');
const linkError = ref<string>('');
const fallbackText = ref<string>('');
const hasContent = ref<boolean>(false);

function checkDocHasContent(doc: ProseMirrorDoc | null | undefined): boolean {
    if (!doc || typeof doc !== 'object') {
        return false;
    }

    const walk = (node: unknown): boolean => {
        if (!node || typeof node !== 'object') {
            return false;
        }
        const candidate = node as { type?: string; text?: string; content?: unknown[] };
        if (candidate.type === 'text' && typeof candidate.text === 'string' && candidate.text.trim() !== '') {
            return true;
        }
        if (Array.isArray(candidate.content)) {
            return candidate.content.some(walk);
        }
        return false;
    };

    return walk(doc);
}

function buildFallbackDoc(rawText: string): ProseMirrorDoc {
    const lines = rawText.split(/\r?\n/);
    return {
        type: 'doc',
        content: lines.map((line) => ({
            type: 'paragraph',
            content: line.trim() !== '' ? [{ type: 'text', text: line }] : [],
        })),
    };
}

const editorInitFailed = ref<boolean>(false);

function createSafeEditor(): ShallowRef<Editor | undefined> {
    try {
        return useEditor({
            editable: !props.disabled,
            extensions: [
                StarterKit.configure({
                    link: {
                        openOnClick: false,
                        autolink: true,
                        linkOnPaste: true,
                        HTMLAttributes: {
                            rel: 'noopener noreferrer',
                            target: '_blank',
                        },
                        isAllowedUri: (url) => isSafeExternalUrl(url),
                    },
                }),
            ],
            content: {
                type: 'doc',
                content: [{ type: 'paragraph' }],
            },
            editorProps: {
                attributes: {
                    role: 'textbox',
                    'aria-multiline': 'true',
                    'aria-labelledby': editorLabelId,
                    class: 'min-h-[84px] max-h-[260px] overflow-y-auto px-3 py-2 text-[13px] leading-relaxed text-primary focus:outline-none',
                },
                handleKeyDown: (_view, event) => {
                    if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
                        event.preventDefault();
                        handleSubmit();
                        return true;
                    }
                    return false;
                },
            },
            onUpdate: ({ editor: activeEditor }) => {
                const json = activeEditor.getJSON() as ProseMirrorDoc;
                hasContent.value = checkDocHasContent(json);
                if (hasContent.value) {
                    validationError.value = '';
                }
            },
        });
    } catch {
        editorInitFailed.value = true;
        return shallowRef<Editor | undefined>(undefined);
    }
}

const editor = createSafeEditor();

const isFallbackMode = computed(() => editorInitFailed.value || !editor.value);

const combinedError = computed(() => validationError.value || props.errorMessage);

watch(
    () => props.disabled,
    (nextDisabled) => {
        editor.value?.setEditable(!nextDisabled);
    },
);

watch(
    () => props.resetKey,
    () => {
        reset();
    },
);

onBeforeUnmount(() => {
    editor.value?.destroy();
});

function toggleLinkBar(): void {
    if (props.disabled) {
        return;
    }

    if (showLinkBar.value) {
        closeLinkBar();
        return;
    }

    const currentHref = editor?.value?.getAttributes('link')?.href;
    linkUrlDraft.value = typeof currentHref === 'string' ? currentHref : 'https://';
    linkError.value = '';
    showLinkBar.value = true;
}

function closeLinkBar(): void {
    showLinkBar.value = false;
    linkUrlDraft.value = '';
    linkError.value = '';
}

function applyLink(): void {
    const trimmed = linkUrlDraft.value.trim();
    if (trimmed === '') {
        editor?.value?.chain().focus().unsetLink().run();
        closeLinkBar();
        return;
    }

    if (!isSafeExternalUrl(trimmed)) {
        linkError.value = 'Enter a valid http://, https://, or mailto: URL.';
        return;
    }

    editor?.value
        ?.chain()
        .focus()
        .extendMarkRange('link')
        .setLink({ href: trimmed, target: '_blank', rel: 'noopener noreferrer' })
        .run();
    closeLinkBar();
}

function removeLink(): void {
    editor?.value?.chain().focus().unsetLink().run();
    closeLinkBar();
}

function currentDocumentJson(): ProseMirrorDoc {
    if (editor?.value) {
        return editor.value.getJSON() as ProseMirrorDoc;
    }
    return buildFallbackDoc(fallbackText.value);
}

function handleFallbackInput(): void {
    hasContent.value = fallbackText.value.trim().length > 0;
    if (hasContent.value) {
        validationError.value = '';
    }
}

function handleSubmit(): void {
    if (props.submitting || props.disabled) {
        return;
    }

    const doc = currentDocumentJson();
    if (!checkDocHasContent(doc)) {
        validationError.value = 'Note content cannot be empty.';
        return;
    }

    validationError.value = '';
    emit('submit', {
        body_json: doc,
        is_pinned: props.allowPin ? isPinned.value : false,
    });
}

function reset(): void {
    validationError.value = '';
    isPinned.value = false;
    fallbackText.value = '';
    hasContent.value = false;
    closeLinkBar();
    editor?.value?.commands.setContent({
        type: 'doc',
        content: [{ type: 'paragraph' }],
    });
}

defineExpose({
    reset,
    editor,
});
</script>

<template>
    <div
        data-testid="rich-note-editor"
        class="rounded-[8px] border border-default bg-surface"
    >
        <span :id="editorLabelId" class="sr-only">Internal note composer</span>

        <!-- Formatting Toolbar -->
        <div
            class="flex flex-wrap items-center justify-between gap-2 border-b border-default bg-subtle px-2.5 py-1.5"
        >
            <div class="flex flex-wrap items-center gap-0.5" role="toolbar" aria-label="Note formatting">
                <button
                    type="button"
                    aria-label="Bold"
                    :aria-pressed="editor?.isActive('bold') ? 'true' : 'false'"
                    :disabled="props.disabled || isFallbackMode"
                    :class="[
                        'inline-flex h-6 w-6 items-center justify-center rounded-[4px] text-secondary transition-colors duration-150 cursor-pointer disabled:opacity-40',
                        editor?.isActive('bold')
                            ? 'bg-surface text-primary border border-default'
                            : 'hover:bg-surface hover:text-primary',
                    ]"
                    @click="editor?.chain().focus().toggleBold().run()"
                >
                    <Bold class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                </button>
                <button
                    type="button"
                    aria-label="Italic"
                    :aria-pressed="editor?.isActive('italic') ? 'true' : 'false'"
                    :disabled="props.disabled || isFallbackMode"
                    :class="[
                        'inline-flex h-6 w-6 items-center justify-center rounded-[4px] text-secondary transition-colors duration-150 cursor-pointer disabled:opacity-40',
                        editor?.isActive('italic')
                            ? 'bg-surface text-primary border border-default'
                            : 'hover:bg-surface hover:text-primary',
                    ]"
                    @click="editor?.chain().focus().toggleItalic().run()"
                >
                    <Italic class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                </button>
                <button
                    type="button"
                    aria-label="Bullet list"
                    :aria-pressed="editor?.isActive('bulletList') ? 'true' : 'false'"
                    :disabled="props.disabled || isFallbackMode"
                    :class="[
                        'inline-flex h-6 w-6 items-center justify-center rounded-[4px] text-secondary transition-colors duration-150 cursor-pointer disabled:opacity-40',
                        editor?.isActive('bulletList')
                            ? 'bg-surface text-primary border border-default'
                            : 'hover:bg-surface hover:text-primary',
                    ]"
                    @click="editor?.chain().focus().toggleBulletList().run()"
                >
                    <List class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                </button>
                <button
                    type="button"
                    aria-label="Ordered list"
                    :aria-pressed="editor?.isActive('orderedList') ? 'true' : 'false'"
                    :disabled="props.disabled || isFallbackMode"
                    :class="[
                        'inline-flex h-6 w-6 items-center justify-center rounded-[4px] text-secondary transition-colors duration-150 cursor-pointer disabled:opacity-40',
                        editor?.isActive('orderedList')
                            ? 'bg-surface text-primary border border-default'
                            : 'hover:bg-surface hover:text-primary',
                    ]"
                    @click="editor?.chain().focus().toggleOrderedList().run()"
                >
                    <ListOrdered class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                </button>
                <button
                    type="button"
                    aria-label="Code block"
                    :aria-pressed="editor?.isActive('codeBlock') ? 'true' : 'false'"
                    :disabled="props.disabled || isFallbackMode"
                    :class="[
                        'inline-flex h-6 w-6 items-center justify-center rounded-[4px] text-secondary transition-colors duration-150 cursor-pointer disabled:opacity-40',
                        editor?.isActive('codeBlock')
                            ? 'bg-surface text-primary border border-default'
                            : 'hover:bg-surface hover:text-primary',
                    ]"
                    @click="editor?.chain().focus().toggleCodeBlock().run()"
                >
                    <Code2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                </button>
                <button
                    type="button"
                    aria-label="Insert or edit link"
                    :aria-pressed="editor?.isActive('link') || showLinkBar ? 'true' : 'false'"
                    :disabled="props.disabled || isFallbackMode"
                    :class="[
                        'inline-flex h-6 w-6 items-center justify-center rounded-[4px] text-secondary transition-colors duration-150 cursor-pointer disabled:opacity-40',
                        editor?.isActive('link') || showLinkBar
                            ? 'bg-surface text-primary border border-default'
                            : 'hover:bg-surface hover:text-primary',
                    ]"
                    @click="toggleLinkBar"
                >
                    <Link2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                </button>
                <button
                    v-if="editor?.isActive('link')"
                    type="button"
                    aria-label="Remove link"
                    :disabled="props.disabled"
                    class="inline-flex h-6 w-6 items-center justify-center rounded-[4px] text-secondary transition-colors duration-150 hover:bg-surface hover:text-primary cursor-pointer"
                    @click="removeLink"
                >
                    <Unlink class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                </button>
            </div>

            <span class="hidden font-mono text-[10px] text-muted sm:inline">
                Cmd+Enter
            </span>
        </div>

        <!-- Inline Safe Link Bar -->
        <div
            v-if="showLinkBar"
            class="flex flex-col gap-2 border-b border-default bg-subtle/60 px-3 py-2 sm:flex-row sm:items-center"
            @keydown.escape.stop="closeLinkBar"
        >
            <label :for="linkInputId" class="sr-only">Link URL</label>
            <div class="min-w-0 flex-1">
                <Input
                    :id="linkInputId"
                    v-model="linkUrlDraft"
                    type="url"
                    placeholder="https://company.internal/brief"
                    :error-message="linkError"
                    @keydown.enter.prevent="applyLink"
                />
            </div>
            <div class="flex shrink-0 items-center gap-1.5">
                <Button variant="primary" size="sm" @click="applyLink">Apply</Button>
                <Button variant="ghost" size="sm" @click="closeLinkBar">Cancel</Button>
            </div>
        </div>

        <!-- Composer Body -->
        <div class="relative">
            <p
                v-if="!hasContent"
                class="pointer-events-none absolute left-3 top-2 text-[13px] text-muted select-none"
                aria-hidden="true"
            >
                {{ props.placeholder }}
            </p>
            <EditorContent v-if="!isFallbackMode && editor" :editor="editor" />
            <textarea
                v-else
                :aria-labelledby="editorLabelId"
                v-model="fallbackText"
                rows="3"
                :disabled="props.disabled"
                data-testid="rich-note-fallback-textarea"
                class="w-full resize-y bg-transparent px-3 py-2 text-[13px] text-primary placeholder:text-muted focus:outline-none"
                @input="handleFallbackInput"
                @keydown.meta.enter.prevent="handleSubmit"
                @keydown.ctrl.enter.prevent="handleSubmit"
            />
        </div>

        <!-- Validation Banner -->
        <div
            v-if="combinedError"
            role="alert"
            data-testid="rich-note-error"
            class="border-t border-status-danger/25 bg-status-danger/10 px-3 py-1.5 text-[12px] text-status-danger"
        >
            {{ combinedError }}
        </div>

        <!-- Footer Controls -->
        <div
            class="flex flex-wrap items-center justify-between gap-2 border-t border-default bg-surface px-3 py-2"
        >
            <label
                v-if="props.allowPin"
                :for="pinCheckboxId"
                class="inline-flex items-center gap-1.5 text-[12px] text-secondary cursor-pointer select-none"
            >
                <input
                    :id="pinCheckboxId"
                    v-model="isPinned"
                    type="checkbox"
                    :disabled="props.disabled || props.submitting"
                    class="h-3.5 w-3.5 rounded border-default accent-accent-primary"
                />
                <Pin class="h-3 w-3 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                <span>Pin note</span>
            </label>
            <span v-else />

            <Button
                variant="primary"
                size="sm"
                :loading="props.submitting"
                :disabled="props.disabled"
                data-testid="rich-note-submit"
                @click="handleSubmit"
            >
                <Send class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                <span>{{ props.submitLabel }}</span>
            </Button>
        </div>
    </div>
</template>
