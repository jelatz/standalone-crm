<?php

namespace App\Modules\Crm\Notes\Actions;

use Illuminate\Validation\ValidationException;

class SanitizeTipTapAstAction
{
    private const int MAX_DEPTH = 64;

    private const int MAX_TEXT_LENGTH = 50_000;

    /**
     * @var list<string>
     */
    private const array ALLOWED_BLOCK_NODES = [
        'paragraph',
        'heading',
        'bulletList',
        'orderedList',
        'listItem',
        'blockquote',
        'codeBlock',
        'hardBreak',
        'horizontalRule',
    ];

    /**
     * @var list<string>
     */
    private const array ALLOWED_MARKS = [
        'bold',
        'italic',
        'strike',
        'code',
        'link',
    ];

    /**
     * Sanitize a TipTap ProseMirror JSON AST and extract normalized plain body_text.
     *
     * @param  array<mixed, mixed>  $document
     * @return array{body_json: array<string, mixed>, body_text: string}
     */
    public function execute(array $document, bool $requireNonEmpty = true): array
    {
        if (($document['type'] ?? null) !== 'doc') {
            throw ValidationException::withMessages([
                'body_json' => ['Note content must be a valid TipTap doc document.'],
            ]);
        }

        $rawContent = is_array($document['content'] ?? null) ? $document['content'] : [];
        $sanitizedChildren = $this->sanitizeNodeList($rawContent, 1);

        $sanitizedDoc = [
            'type' => 'doc',
            'content' => $sanitizedChildren,
        ];

        $bodyText = $this->normalizePlainText($this->extractTextFromNodes($sanitizedChildren));

        if ($requireNonEmpty && $bodyText === '') {
            throw ValidationException::withMessages([
                'body_json' => ['Note content cannot be empty.'],
            ]);
        }

        if (mb_strlen($bodyText) > self::MAX_TEXT_LENGTH) {
            throw ValidationException::withMessages([
                'body_json' => ['Note content exceeds the maximum allowed length.'],
            ]);
        }

        return [
            'body_json' => $sanitizedDoc,
            'body_text' => $bodyText,
        ];
    }

    /**
     * @param  array<mixed, mixed>  $nodes
     * @return list<array<string, mixed>>
     */
    protected function sanitizeNodeList(array $nodes, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw ValidationException::withMessages([
                'body_json' => ['Note content nesting exceeds the maximum allowed depth.'],
            ]);
        }

        $sanitized = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $cleanNode = $this->sanitizeNode($node, $depth);
            if ($cleanNode !== null) {
                $sanitized[] = $cleanNode;
            }
        }

        return $sanitized;
    }

    /**
     * @param  array<mixed, mixed>  $node
     * @return array<string, mixed>|null
     */
    protected function sanitizeNode(array $node, int $depth): ?array
    {
        $type = $node['type'] ?? null;
        if (! is_string($type)) {
            return null;
        }

        if ($type === 'text') {
            $text = $node['text'] ?? null;
            if (! is_string($text)) {
                return null;
            }

            $clean = [
                'type' => 'text',
                'text' => $text,
            ];

            if (is_array($node['marks'] ?? null)) {
                $marks = $this->sanitizeMarks($node['marks']);
                if ($marks !== []) {
                    $clean['marks'] = $marks;
                }
            }

            return $clean;
        }

        if (! in_array($type, self::ALLOWED_BLOCK_NODES, true)) {
            return null;
        }

        $clean = ['type' => $type];
        $attrs = $this->sanitizeNodeAttributes($type, is_array($node['attrs'] ?? null) ? $node['attrs'] : []);
        if ($attrs !== []) {
            $clean['attrs'] = $attrs;
        }

        if (in_array($type, ['hardBreak', 'horizontalRule'], true)) {
            return $clean;
        }

        if (is_array($node['content'] ?? null)) {
            $clean['content'] = $this->sanitizeNodeList($node['content'], $depth + 1);
        }

        return $clean;
    }

    /**
     * @param  array<mixed, mixed>  $attrs
     * @return array<string, mixed>
     */
    protected function sanitizeNodeAttributes(string $type, array $attrs): array
    {
        return match ($type) {
            'heading' => [
                'level' => max(1, min(6, is_numeric($attrs['level'] ?? null) ? (int) $attrs['level'] : 2)),
            ],
            'orderedList' => isset($attrs['start']) && is_numeric($attrs['start']) && (int) $attrs['start'] >= 1
                ? ['start' => (int) $attrs['start']]
                : [],
            'codeBlock' => isset($attrs['language'])
                && is_string($attrs['language'])
                && preg_match('/^[a-zA-Z0-9_+-]{1,32}$/', $attrs['language']) === 1
                ? ['language' => $attrs['language']]
                : [],
            default => [],
        };
    }

    /**
     * @param  array<mixed, mixed>  $marks
     * @return list<array<string, mixed>>
     */
    protected function sanitizeMarks(array $marks): array
    {
        $sanitized = [];

        foreach ($marks as $mark) {
            if (! is_array($mark)) {
                continue;
            }

            $type = $mark['type'] ?? null;
            if (! is_string($type) || ! in_array($type, self::ALLOWED_MARKS, true)) {
                continue;
            }

            if ($type !== 'link') {
                $sanitized[] = ['type' => $type];

                continue;
            }

            $attrs = is_array($mark['attrs'] ?? null) ? $mark['attrs'] : [];
            $safeHref = $this->sanitizeHref($attrs['href'] ?? null);

            if ($safeHref === null) {
                continue;
            }

            $cleanAttrs = ['href' => $safeHref];
            if (($attrs['target'] ?? null) === '_blank') {
                $cleanAttrs['target'] = '_blank';
                $cleanAttrs['rel'] = 'noopener noreferrer';
            }

            $sanitized[] = [
                'type' => 'link',
                'attrs' => $cleanAttrs,
            ];
        }

        return $sanitized;
    }

    protected function sanitizeHref(mixed $href): ?string
    {
        if (! is_string($href) || preg_match('/[\x00-\x1F\x7F]/', $href) === 1) {
            return null;
        }

        $trimmed = trim($href);
        if ($trimmed === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));

        if ($scheme === 'mailto') {
            $addressPart = explode('?', substr($trimmed, 7), 2)[0];

            return filter_var($addressPart, FILTER_VALIDATE_EMAIL) !== false ? $trimmed : null;
        }

        if (in_array($scheme, ['http', 'https'], true)) {
            $host = parse_url($trimmed, PHP_URL_HOST);

            return filter_var($trimmed, FILTER_VALIDATE_URL) !== false && is_string($host) && $host !== ''
                ? $trimmed
                : null;
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    protected function extractTextFromNodes(array $nodes): string
    {
        $blockLineNodes = ['paragraph', 'heading', 'listItem', 'blockquote', 'codeBlock', 'bulletList', 'orderedList'];
        $segments = [];
        $inlineBuffer = '';

        foreach ($nodes as $node) {
            $type = $node['type'] ?? '';

            if ($type === 'text') {
                $inlineBuffer .= (string) ($node['text'] ?? '');

                continue;
            }

            if ($type === 'hardBreak') {
                $inlineBuffer .= "\n";

                continue;
            }

            if ($type === 'horizontalRule') {
                if ($inlineBuffer !== '') {
                    $segments[] = $inlineBuffer;
                    $inlineBuffer = '';
                }

                continue;
            }

            if (in_array($type, $blockLineNodes, true)) {
                if ($inlineBuffer !== '') {
                    $segments[] = $inlineBuffer;
                    $inlineBuffer = '';
                }

                $childText = is_array($node['content'] ?? null)
                    ? $this->extractTextFromNodes($node['content'])
                    : '';

                if (trim($childText) !== '') {
                    $segments[] = $childText;
                }
            }
        }

        if ($inlineBuffer !== '') {
            $segments[] = $inlineBuffer;
        }

        return implode("\n", $segments);
    }

    protected function normalizePlainText(string $text): string
    {
        $normalizedNewlines = str_replace(["\r\n", "\r"], "\n", $text);
        $collapsedBlankLines = preg_replace("/\n{3,}/", "\n\n", $normalizedNewlines) ?? $normalizedNewlines;

        return trim($collapsedBlankLines);
    }
}
