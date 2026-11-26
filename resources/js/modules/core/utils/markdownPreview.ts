export interface InlineToken {
    type: 'text' | 'strong' | 'em' | 'code' | 'link';
    text: string;
    href?: string;
}

export type MarkdownBlock =
    | { type: 'heading'; level: 1 | 2 | 3 | 4; tokens: InlineToken[] }
    | { type: 'paragraph'; tokens: InlineToken[] }
    | { type: 'list'; ordered: boolean; items: InlineToken[][] }
    | { type: 'blockquote'; lines: InlineToken[][] }
    | { type: 'code'; language: string; content: string }
    | { type: 'table'; headers: InlineToken[][]; rows: InlineToken[][][] }
    | { type: 'hr' };

function isSafeHref(rawHref: string): boolean {
    const trimmed = rawHref.trim();
    if (trimmed === '') {
        return false;
    }

    if (trimmed.startsWith('/') && !trimmed.startsWith('//')) {
        return true;
    }

    try {
        const parsed = new URL(trimmed);
        return parsed.protocol === 'https:' || parsed.protocol === 'http:';
    } catch {
        return false;
    }
}

export function parseInlineTokens(input: string): InlineToken[] {
    const tokens: InlineToken[] = [];
    const pattern = /(`[^`]+`|\*\*[^*]+\*\*|\*[^*\n]+\*|\[[^\]]+\]\([^)]+\))/g;
    let lastIndex = 0;

    for (const match of input.matchAll(pattern)) {
        const index = match.index ?? 0;
        if (index > lastIndex) {
            tokens.push({ type: 'text', text: input.slice(lastIndex, index) });
        }

        const raw = match[0];

        if (raw.startsWith('`') && raw.endsWith('`')) {
            tokens.push({ type: 'code', text: raw.slice(1, -1) });
        } else if (raw.startsWith('**') && raw.endsWith('**')) {
            tokens.push({ type: 'strong', text: raw.slice(2, -2) });
        } else if (raw.startsWith('*') && raw.endsWith('*')) {
            tokens.push({ type: 'em', text: raw.slice(1, -1) });
        } else if (raw.startsWith('[')) {
            const linkMatch = /^\[([^\]]+)\]\(([^)]+)\)$/.exec(raw);
            if (linkMatch && isSafeHref(linkMatch[2])) {
                tokens.push({
                    type: 'link',
                    text: linkMatch[1],
                    href: linkMatch[2].trim(),
                });
            } else {
                tokens.push({ type: 'text', text: raw });
            }
        } else {
            tokens.push({ type: 'text', text: raw });
        }

        lastIndex = index + raw.length;
    }

    if (lastIndex < input.length) {
        tokens.push({ type: 'text', text: input.slice(lastIndex) });
    }

    return tokens.length > 0 ? tokens : [{ type: 'text', text: '' }];
}

function splitTableRow(line: string): string[] {
    const trimmed = line.trim().replace(/^\|/, '').replace(/\|$/, '');
    return trimmed.split('|').map((cell) => cell.trim());
}

function isTableSeparator(line: string): boolean {
    const trimmed = line.trim();
    if (!trimmed.includes('|')) {
        return false;
    }
    const cells = splitTableRow(trimmed);
    return cells.length > 0 && cells.every((cell) => /^:?-{3,}:?$/.test(cell));
}

export function parseMarkdownBlocks(markdown: string): MarkdownBlock[] {
    const lines = markdown.replace(/\r\n?/g, '\n').split('\n');
    const blocks: MarkdownBlock[] = [];
    let index = 0;

    // Skip optional YAML frontmatter block for readable SOP preview
    if (lines[0]?.trim() === '---') {
        let closing = 1;
        while (closing < lines.length && lines[closing]?.trim() !== '---') {
            closing++;
        }
        if (closing < lines.length) {
            index = closing + 1;
        }
    }

    while (index < lines.length) {
        const line = lines[index] ?? '';
        const trimmed = line.trim();

        if (trimmed === '') {
            index++;
            continue;
        }

        // Fenced code block
        if (trimmed.startsWith('```')) {
            const language = trimmed.slice(3).trim();
            const codeLines: string[] = [];
            index++;
            while (index < lines.length && !(lines[index] ?? '').trim().startsWith('```')) {
                codeLines.push(lines[index] ?? '');
                index++;
            }
            if (index < lines.length) {
                index++;
            }
            blocks.push({
                type: 'code',
                language,
                content: codeLines.join('\n'),
            });
            continue;
        }

        // Horizontal rule
        if (/^(?:-{3,}|\*{3,}|_{3,})$/.test(trimmed)) {
            blocks.push({ type: 'hr' });
            index++;
            continue;
        }

        // Heading (# to ####)
        const headingMatch = /^(#{1,4})\s+(.+)$/.exec(trimmed);
        if (headingMatch) {
            const level = headingMatch[1].length as 1 | 2 | 3 | 4;
            blocks.push({
                type: 'heading',
                level,
                tokens: parseInlineTokens(headingMatch[2].trim()),
            });
            index++;
            continue;
        }

        // Pipe table
        if (
            trimmed.includes('|') &&
            index + 1 < lines.length &&
            isTableSeparator(lines[index + 1] ?? '')
        ) {
            const headers = splitTableRow(trimmed).map((cell) => parseInlineTokens(cell));
            index += 2;
            const rows: InlineToken[][][] = [];

            while (index < lines.length) {
                const rowLine = (lines[index] ?? '').trim();
                if (rowLine === '' || !rowLine.includes('|')) {
                    break;
                }
                rows.push(splitTableRow(rowLine).map((cell) => parseInlineTokens(cell)));
                index++;
            }

            blocks.push({ type: 'table', headers, rows });
            continue;
        }

        // Blockquote
        if (trimmed.startsWith('>')) {
            const quoteLines: InlineToken[][] = [];
            while (index < lines.length && (lines[index] ?? '').trim().startsWith('>')) {
                const content = (lines[index] ?? '').trim().replace(/^>\s?/, '');
                quoteLines.push(parseInlineTokens(content));
                index++;
            }
            blocks.push({ type: 'blockquote', lines: quoteLines });
            continue;
        }

        // Unordered list
        if (/^[-*+]\s+/.test(trimmed)) {
            const items: InlineToken[][] = [];
            while (index < lines.length && /^[-*+]\s+/.test((lines[index] ?? '').trim())) {
                const itemText = (lines[index] ?? '').trim().replace(/^[-*+]\s+/, '');
                items.push(parseInlineTokens(itemText));
                index++;
            }
            blocks.push({ type: 'list', ordered: false, items });
            continue;
        }

        // Ordered list
        if (/^\d+\.\s+/.test(trimmed)) {
            const items: InlineToken[][] = [];
            while (index < lines.length && /^\d+\.\s+/.test((lines[index] ?? '').trim())) {
                const itemText = (lines[index] ?? '').trim().replace(/^\d+\.\s+/, '');
                items.push(parseInlineTokens(itemText));
                index++;
            }
            blocks.push({ type: 'list', ordered: true, items });
            continue;
        }

        // Paragraph
        const paragraphLines: string[] = [];
        while (index < lines.length) {
            const current = lines[index] ?? '';
            const currentTrimmed = current.trim();
            if (
                currentTrimmed === '' ||
                currentTrimmed.startsWith('```') ||
                currentTrimmed.startsWith('>') ||
                /^(#{1,4})\s+/.test(currentTrimmed) ||
                /^[-*+]\s+/.test(currentTrimmed) ||
                /^\d+\.\s+/.test(currentTrimmed) ||
                /^(?:-{3,}|\*{3,}|_{3,})$/.test(currentTrimmed)
            ) {
                break;
            }
            paragraphLines.push(currentTrimmed);
            index++;
        }

        blocks.push({
            type: 'paragraph',
            tokens: parseInlineTokens(paragraphLines.join(' ')),
        });
    }

    return blocks;
}
