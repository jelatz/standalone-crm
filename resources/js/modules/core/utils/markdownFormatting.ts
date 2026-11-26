export type MarkdownFormatId =
    | 'heading'
    | 'bold'
    | 'italic'
    | 'code'
    | 'link'
    | 'bulletList'
    | 'orderedList'
    | 'blockquote'
    | 'table'
    | 'codeBlock'
    | 'divider'
    | 'template';

export interface MarkdownGuideItem {
    id: Exclude<MarkdownFormatId, 'template'>;
    label: string;
    syntax: string;
    description: string;
}

export interface MarkdownEditResult {
    value: string;
    selectionStart: number;
    selectionEnd: number;
}

export const MARKDOWN_GUIDE_ITEMS: readonly MarkdownGuideItem[] = [
    {
        id: 'heading',
        label: 'Section Heading',
        syntax: '## Section Heading',
        description: 'Top-level and subsection headers (# to ####)',
    },
    {
        id: 'bold',
        label: 'Bold Emphasis',
        syntax: '**required gate**',
        description: 'Highlight mandatory rules, SLAs, or approval gates',
    },
    {
        id: 'italic',
        label: 'Italic Note',
        syntax: '*optional note*',
        description: 'Subtle inline clarification or field hint',
    },
    {
        id: 'code',
        label: 'Inline Code',
        syntax: '`status = new`',
        description: 'Exact field keys, enum values, routes, or CLI flags',
    },
    {
        id: 'bulletList',
        label: 'Bullet List',
        syntax: '- Checklist item',
        description: 'Unordered qualification criteria or exclusions',
    },
    {
        id: 'orderedList',
        label: 'Numbered Steps',
        syntax: '1. First step',
        description: 'Sequential operating workflow steps',
    },
    {
        id: 'blockquote',
        label: 'Rule Callout',
        syntax: '> **Golden Rule:** ...',
        description: 'Emphasize non-negotiable invariants and guardrails',
    },
    {
        id: 'table',
        label: 'Matrix Table',
        syntax: '| Condition | Action |',
        description: 'Structured approval tiers, channels, or field maps',
    },
    {
        id: 'codeBlock',
        label: 'Code Block',
        syntax: '```text ... ```',
        description: 'Multi-line command examples or JSON payload templates',
    },
    {
        id: 'link',
        label: 'CRM / Doc Link',
        syntax: '[Leads](/app/crm/leads)',
        description: 'Internal /app/... routes or https:// references',
    },
    {
        id: 'divider',
        label: 'Section Divider',
        syntax: '---',
        description: 'Horizontal hairline separating major SOP sections',
    },
];

export function buildSopStarterTemplate(title?: string): string {
    const cleanTitle = title?.trim() || 'Module Procedure';

    return [
        `# Standard Operating Procedure: ${cleanTitle}`,
        '',
        '## 1. Purpose & Golden Rule',
        '',
        '> **Invariant:** State the non-negotiable operational rule here.',
        '',
        '## 2. Eligibility & Gate Criteria',
        '',
        '- **Required Fields**: List mandatory fields or data gates',
        '- **Explicit Exclusions**: Clarify what should not proceed',
        '',
        '## 3. Step-by-Step Workflow',
        '',
        '1. Verify inputs and ownership boundaries.',
        '2. Complete required checks before status transitions.',
        '3. Record audit notes on the CRM record timeline.',
        '',
        '## 4. Escalation & Approvals',
        '',
        '| Scenario | Required Approver | SLA |',
        '| :--- | :--- | :--- |',
        '| Standard exception | Operations Manager | 1 business day |',
        '',
    ].join('\n');
}

function resolveBlockPrefix(value: string, start: number, compactList = false): string {
    if (start <= 0) {
        return '';
    }
    const prevChar = value[start - 1];
    if (prevChar === '\n') {
        if (compactList || start < 2 || value[start - 2] === '\n') {
            return '';
        }
        return '\n';
    }
    return compactList ? '\n' : '\n\n';
}

export function applyMarkdownFormat(
    value: string,
    rawStart: number,
    rawEnd: number,
    action: MarkdownFormatId,
    sopTitle?: string,
): MarkdownEditResult {
    const start = Math.max(0, Math.min(rawStart, value.length));
    const end = Math.max(start, Math.min(rawEnd, value.length));
    const before = value.slice(0, start);
    const selected = value.slice(start, end);
    const after = value.slice(end);

    if (action === 'template') {
        const template = buildSopStarterTemplate(sopTitle);
        if (value.trim() === '') {
            return {
                value: template,
                selectionStart: 0,
                selectionEnd: 0,
            };
        }
        const prefix = resolveBlockPrefix(value, start);
        const nextValue = `${before}${prefix}${template}${after}`;
        const cursor = before.length + prefix.length + template.length;
        return {
            value: nextValue,
            selectionStart: cursor,
            selectionEnd: cursor,
        };
    }

    if (action === 'bold' || action === 'italic' || action === 'code') {
        const markers: Record<'bold' | 'italic' | 'code', { marker: string; placeholder: string }> = {
            bold: { marker: '**', placeholder: 'required gate' },
            italic: { marker: '*', placeholder: 'optional note' },
            code: { marker: '`', placeholder: 'status = new' },
        };
        const { marker, placeholder } = markers[action];
        const inner = selected || placeholder;
        const nextValue = `${before}${marker}${inner}${marker}${after}`;
        const innerStart = before.length + marker.length;
        return {
            value: nextValue,
            selectionStart: innerStart,
            selectionEnd: innerStart + inner.length,
        };
    }

    if (action === 'link') {
        if (selected !== '') {
            const url = '/app/crm/leads';
            const snippet = `[${selected}](${url})`;
            const urlStart = before.length + selected.length + 3;
            return {
                value: `${before}${snippet}${after}`,
                selectionStart: urlStart,
                selectionEnd: urlStart + url.length,
            };
        }
        const label = 'Leads';
        const snippet = `[${label}](/app/crm/leads)`;
        return {
            value: `${before}${snippet}${after}`,
            selectionStart: before.length + 1,
            selectionEnd: before.length + 1 + label.length,
        };
    }

    const isList = action === 'bulletList' || action === 'orderedList';
    const prefix = resolveBlockPrefix(value, start, isList);

    if (action === 'heading') {
        const inner = selected || 'Section Heading';
        const snippet = `## ${inner}`;
        const innerStart = before.length + prefix.length + 3;
        return {
            value: `${before}${prefix}${snippet}${after}`,
            selectionStart: innerStart,
            selectionEnd: innerStart + inner.length,
        };
    }

    if (action === 'bulletList') {
        const inner = selected || 'Checklist item';
        const snippet = `- ${inner}`;
        const innerStart = before.length + prefix.length + 2;
        return {
            value: `${before}${prefix}${snippet}${after}`,
            selectionStart: innerStart,
            selectionEnd: innerStart + inner.length,
        };
    }

    if (action === 'orderedList') {
        const inner = selected || 'First step';
        const snippet = `1. ${inner}`;
        const innerStart = before.length + prefix.length + 3;
        return {
            value: `${before}${prefix}${snippet}${after}`,
            selectionStart: innerStart,
            selectionEnd: innerStart + inner.length,
        };
    }

    if (action === 'blockquote') {
        const inner = selected || 'State the non-negotiable invariant here.';
        const lead = '> **Golden Rule:** ';
        const snippet = `${lead}${inner}`;
        const innerStart = before.length + prefix.length + lead.length;
        return {
            value: `${before}${prefix}${snippet}${after}`,
            selectionStart: innerStart,
            selectionEnd: innerStart + inner.length,
        };
    }

    if (action === 'table') {
        const snippet = [
            '| Condition | Required Action |',
            '| :--- | :--- |',
            '| Standard case | Verify owner & fields |',
        ].join('\n');
        const nextValue = `${before}${prefix}${snippet}${after}`;
        const cursor = before.length + prefix.length + snippet.length;
        return {
            value: nextValue,
            selectionStart: cursor,
            selectionEnd: cursor,
        };
    }

    if (action === 'codeBlock') {
        const inner = selected || 'php artisan crm:leads:import batch.json --dry-run';
        const openFence = '```text\n';
        const closeFence = '\n```';
        const snippet = `${openFence}${inner}${closeFence}`;
        const innerStart = before.length + prefix.length + openFence.length;
        return {
            value: `${before}${prefix}${snippet}${after}`,
            selectionStart: innerStart,
            selectionEnd: innerStart + inner.length,
        };
    }

    // divider
    const snippet = '---';
    const nextValue = `${before}${prefix}${snippet}\n${after}`;
    const cursor = before.length + prefix.length + snippet.length + 1;
    return {
        value: nextValue,
        selectionStart: cursor,
        selectionEnd: cursor,
    };
}
