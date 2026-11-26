<script lang="ts">
import { defineComponent, h, type PropType, type VNode } from 'vue';
import { sanitizeLinkHref } from '@/modules/crm/utils/formatters';

interface ProseMirrorMark {
    type?: string;
    attrs?: Record<string, unknown>;
}

interface ProseMirrorNode {
    type?: string;
    text?: string;
    attrs?: Record<string, unknown>;
    marks?: ProseMirrorMark[];
    content?: ProseMirrorNode[];
}

function renderTextWithMarks(node: ProseMirrorNode, key: string): VNode | string {
    const rawText = typeof node.text === 'string' ? node.text : '';
    const marks = Array.isArray(node.marks) ? node.marks : [];

    let current: VNode | string = rawText;

    for (const mark of marks) {
        if (!mark || typeof mark !== 'object') {
            continue;
        }

        switch (mark.type) {
            case 'bold':
                current = h('strong', { class: 'font-semibold text-primary' }, [current]);
                break;
            case 'italic':
                current = h('em', { class: 'italic' }, [current]);
                break;
            case 'strike':
                current = h('s', { class: 'line-through text-muted' }, [current]);
                break;
            case 'code':
                current = h(
                    'code',
                    {
                        class: 'rounded border border-default bg-subtle px-1 py-0.5 font-mono text-[12px] text-primary',
                    },
                    [current],
                );
                break;
            case 'link': {
                const safeHref = sanitizeLinkHref(mark.attrs?.href);
                if (safeHref) {
                    current = h(
                        'a',
                        {
                            href: safeHref,
                            target: '_blank',
                            rel: 'noopener noreferrer',
                            class: 'text-accent-primary underline underline-offset-2 hover:text-accent-hover break-all',
                        },
                        [current],
                    );
                } else {
                    current = h(
                        'span',
                        {
                            class: 'text-secondary',
                            'data-unsafe-link': 'blocked',
                        },
                        [current],
                    );
                }
                break;
            }
            default:
                break;
        }
    }

    return typeof current === 'string' ? h('span', { key }, current) : h('span', { key }, [current]);
}

function renderNode(node: ProseMirrorNode, index: number): VNode | null {
    if (!node || typeof node !== 'object') {
        return null;
    }

    const key = `${node.type ?? 'node'}-${index}`;
    const children = Array.isArray(node.content)
        ? node.content
              .map((child, childIndex) => renderNode(child, childIndex))
              .filter((child): child is VNode => child !== null)
        : [];

    switch (node.type) {
        case 'doc':
            return h('div', { key, class: 'space-y-1.5 text-[13px] leading-relaxed text-primary' }, children);
        case 'paragraph':
            return h('p', { key, class: 'min-h-[1em] break-words' }, children.length > 0 ? children : [h('br')]);
        case 'heading': {
            const rawLevel = Number(node.attrs?.level ?? 2);
            const level = Number.isFinite(rawLevel) ? Math.min(6, Math.max(1, Math.trunc(rawLevel))) : 2;
            const tag = `h${level}`;
            return h(tag, { key, class: 'font-semibold tracking-[-0.01em] text-primary' }, children);
        }
        case 'bulletList':
            return h('ul', { key, class: 'list-disc space-y-0.5 pl-5' }, children);
        case 'orderedList': {
            const start = Number(node.attrs?.start ?? 1);
            return h(
                'ol',
                {
                    key,
                    start: Number.isFinite(start) && start >= 1 ? Math.trunc(start) : 1,
                    class: 'list-decimal space-y-0.5 pl-5',
                },
                children,
            );
        }
        case 'listItem':
            return h('li', { key, class: 'leading-relaxed' }, children);
        case 'blockquote':
            return h(
                'blockquote',
                { key, class: 'border-l-2 border-default pl-3 text-secondary italic' },
                children,
            );
        case 'codeBlock':
            return h(
                'pre',
                {
                    key,
                    class: 'overflow-x-auto rounded-[6px] border border-default bg-subtle p-2.5 font-mono text-[12px] text-primary',
                },
                [h('code', {}, children)],
            );
        case 'hardBreak':
            return h('br', { key });
        case 'horizontalRule':
            return h('hr', { key, class: 'my-2 border-default' });
        case 'text': {
            const rendered = renderTextWithMarks(node, key);
            return typeof rendered === 'string' ? h('span', { key }, rendered) : rendered;
        }
        default:
            return children.length > 0 ? h('div', { key }, children) : null;
    }
}

export default defineComponent({
    name: 'RichJsonRenderer',
    props: {
        document: {
            type: Object as PropType<Record<string, unknown> | null | undefined>,
            default: null,
        },
        fallbackText: {
            type: String,
            default: '',
        },
    },
    setup(props) {
        return () => {
            const doc = props.document as ProseMirrorNode | null | undefined;
            if (doc && doc.type === 'doc' && Array.isArray(doc.content) && doc.content.length > 0) {
                return renderNode(doc, 0);
            }

            if (props.fallbackText && props.fallbackText.trim() !== '') {
                return h(
                    'p',
                    { class: 'whitespace-pre-wrap break-words text-[13px] leading-relaxed text-primary' },
                    props.fallbackText,
                );
            }

            return h('p', { class: 'text-[12px] text-muted' }, 'Empty note');
        };
    },
});
</script>
