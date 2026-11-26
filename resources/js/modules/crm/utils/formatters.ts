export function formatMoneyCents(
    amountCents: number | null | undefined,
    currency: string = 'USD',
    options: { minimumFractionDigits?: number; maximumFractionDigits?: number } = {},
): string {
    if (amountCents === null || amountCents === undefined || Number.isNaN(amountCents)) {
        return '—';
    }

    const amount = amountCents / 100;
    const normalizedCurrency = (currency || 'USD').toUpperCase();
    const isWholeDollar = amountCents % 100 === 0;

    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: normalizedCurrency,
            minimumFractionDigits: options.minimumFractionDigits ?? (isWholeDollar ? 0 : 2),
            maximumFractionDigits: options.maximumFractionDigits ?? (isWholeDollar ? 0 : 2),
        }).format(amount);
    } catch {
        return `${normalizedCurrency} ${amount.toFixed(2)}`;
    }
}

export function formatShortDate(isoOrDate: string | null | undefined): string {
    if (!isoOrDate) {
        return '—';
    }

    const parsed = /^\d{4}-\d{2}-\d{2}$/.test(isoOrDate)
        ? new Date(`${isoOrDate}T00:00:00`)
        : new Date(isoOrDate);

    if (Number.isNaN(parsed.getTime())) {
        return isoOrDate;
    }

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(parsed);
}

export function formatDateTime(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    const parsed = new Date(iso);
    if (Number.isNaN(parsed.getTime())) {
        return iso;
    }

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(parsed);
}

export function formatFileSize(sizeBytes: number | null | undefined): string {
    if (sizeBytes === null || sizeBytes === undefined || sizeBytes < 0) {
        return '0 B';
    }

    if (sizeBytes < 1024) {
        return `${sizeBytes} B`;
    }

    if (sizeBytes < 1024 * 1024) {
        return `${(sizeBytes / 1024).toFixed(1)} KB`;
    }

    return `${(sizeBytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function formatEnumLabel(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return value
        .split('_')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

export function getInitials(name: string | null | undefined, fallback: string = '—'): string {
    const trimmed = name?.trim();
    if (!trimmed) {
        return fallback;
    }

    const parts = trimmed.split(/\s+/);
    const first = parts[0]?.charAt(0) ?? '';
    const last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';
    return (first + last).toUpperCase() || fallback;
}

export function isSafeExternalUrl(rawHref: unknown): rawHref is string {
    if (typeof rawHref !== 'string') {
        return false;
    }

    const trimmed = rawHref.trim();
    if (trimmed === '' || /[\u0000-\u001F\u007F]/.test(trimmed)) {
        return false;
    }

    try {
        const parsed = new URL(trimmed);
        return parsed.protocol === 'http:' || parsed.protocol === 'https:' || parsed.protocol === 'mailto:';
    } catch {
        return false;
    }
}

export function sanitizeLinkHref(rawHref: unknown): string | null {
    if (!isSafeExternalUrl(rawHref)) {
        return null;
    }

    return rawHref.trim();
}

export const CORE_FIELD_LABELS: Record<string, string> = {
    amount_cents: 'Deal Amount',
    expected_close_date: 'Expected Close Date',
    primary_contact_id: 'Primary Contact',
    lost_reason: 'Lost Reason',
};

export function getCoreFieldLabel(key: string): string {
    return CORE_FIELD_LABELS[key] ?? formatEnumLabel(key);
}
