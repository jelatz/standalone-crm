import { onMounted, onUnmounted, type Ref } from 'vue';

/**
 * Synchronizes native :user-invalid visual state with aria-invalid, dynamic
 * aria-describedby error links, and polite live-region announcements after
 * user interaction (blur, change, or failed form submission).
 */
export function useAccessibleFormValidation(formRef: Ref<HTMLFormElement | null>): void {
    const flagged = new WeakSet<Element>();
    const announced = new WeakSet<Element>();
    const dirtyFallback = new WeakMap<Element, { hasInteracted: boolean; hasBlurred: boolean }>();

    let announcer: HTMLDivElement | null = null;
    let announceTimer: ReturnType<typeof setTimeout> | undefined;
    let announcedField: Element | null = null;

    const supportsUserInvalid = (): boolean =>
        typeof CSS !== 'undefined' &&
        typeof CSS.supports === 'function' &&
        CSS.supports('selector(:user-invalid)');

    const isFormControl = (el: unknown): el is HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement =>
        el instanceof HTMLElement && el.matches('input, select, textarea');

    const linkError = (el: HTMLElement, shown: boolean): void => {
        const errorId = el.dataset.error;
        if (!errorId) {
            return;
        }

        const ids = (el.getAttribute('aria-describedby') || '')
            .split(/\s+/)
            .filter((id) => id && id !== errorId);

        if (shown) {
            ids.push(errorId);
        }

        if (ids.length > 0) {
            el.setAttribute('aria-describedby', ids.join(' '));
        } else {
            el.removeAttribute('aria-describedby');
        }
    };

    const announceError = (el: HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement): void => {
        const errorId = el.dataset.error;
        if (!errorId || !announcer) {
            return;
        }

        const errorEl = document.getElementById(errorId);
        if (!errorEl) {
            return;
        }

        const labelText = el.labels?.[0]?.textContent?.replace(/\s+/g, ' ').trim();
        const prefix = labelText ? `${labelText}: ` : '';
        const message = errorEl.textContent?.replace(/\s+/g, ' ').trim() ?? '';

        if (announceTimer !== undefined) {
            clearTimeout(announceTimer);
        }

        announcer.textContent = '';
        announcedField = el;
        announceTimer = setTimeout(() => {
            if (announcer) {
                announcer.textContent = prefix + message;
            }
        }, 500);
    };

    const isControlUserInvalid = (el: HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement): boolean => {
        if (supportsUserInvalid()) {
            try {
                return el.matches(':user-invalid');
            } catch {
                // Fall back to validity tracking when selector check is unsupported in JSDOM.
            }
        }

        const state = dirtyFallback.get(el);
        return Boolean(state?.hasInteracted && state?.hasBlurred && !el.validity.valid);
    };

    const syncAria = (el: unknown, announce = false): void => {
        if (!isFormControl(el)) {
            return;
        }

        const invalid = isControlUserInvalid(el);

        if (invalid) {
            if (announce && !announced.has(el)) {
                announceError(el);
                announced.add(el);
            }
            el.setAttribute('aria-invalid', 'true');
            el.classList.add('user-invalid-fallback');
            linkError(el, true);
            flagged.add(el);
        } else if (flagged.has(el) || el.getAttribute('aria-invalid') !== 'true') {
            el.setAttribute('aria-invalid', 'false');
            el.classList.remove('user-invalid-fallback');
            linkError(el, false);
            flagged.delete(el);
            announced.delete(el);

            if (announcedField === el) {
                if (announceTimer !== undefined) {
                    clearTimeout(announceTimer);
                }
                if (announcer) {
                    announcer.textContent = '';
                }
            }
        }
    };

    const handleBlur = (event: Event): void => {
        if (isFormControl(event.target)) {
            const state = dirtyFallback.get(event.target) ?? { hasInteracted: false, hasBlurred: false };
            state.hasBlurred = true;
            dirtyFallback.set(event.target, state);
        }
        syncAria(event.target, true);
    };

    const handleInput = (event: Event): void => {
        if (isFormControl(event.target)) {
            const state = dirtyFallback.get(event.target) ?? { hasInteracted: false, hasBlurred: false };
            state.hasInteracted = true;
            dirtyFallback.set(event.target, state);
            if (event.target.hasAttribute('aria-invalid')) {
                syncAria(event.target, false);
            }
        }
    };

    const handleChange = (event: Event): void => {
        if (isFormControl(event.target)) {
            const state = dirtyFallback.get(event.target) ?? { hasInteracted: false, hasBlurred: false };
            state.hasInteracted = true;
            state.hasBlurred = true;
            dirtyFallback.set(event.target, state);
        }
        syncAria(event.target, true);
    };

    const handleInvalid = (event: Event): void => {
        if (isFormControl(event.target)) {
            dirtyFallback.set(event.target, { hasInteracted: true, hasBlurred: true });
            announced.add(event.target);
        }
        syncAria(event.target, false);
    };

    const handleReset = (): void => {
        const form = formRef.value;
        if (!form) {
            return;
        }
        setTimeout(() => {
            Array.from(form.elements).forEach((el) => {
                dirtyFallback.delete(el);
                syncAria(el, false);
            });
        });
    };

    onMounted(() => {
        const form = formRef.value;
        if (!form || typeof document === 'undefined') {
            return;
        }

        announcer = document.createElement('div');
        announcer.setAttribute('aria-live', 'polite');
        announcer.className = 'sr-only';
        document.body.append(announcer);

        form.querySelectorAll('input, select, textarea').forEach((el) => syncAria(el, false));

        form.addEventListener('blur', handleBlur, true);
        form.addEventListener('input', handleInput);
        form.addEventListener('change', handleChange, true);
        form.addEventListener('invalid', handleInvalid, true);
        form.addEventListener('reset', handleReset, true);
    });

    onUnmounted(() => {
        const form = formRef.value;
        if (form) {
            form.removeEventListener('blur', handleBlur, true);
            form.removeEventListener('input', handleInput);
            form.removeEventListener('change', handleChange, true);
            form.removeEventListener('invalid', handleInvalid, true);
            form.removeEventListener('reset', handleReset, true);
        }
        if (announceTimer !== undefined) {
            clearTimeout(announceTimer);
        }
        if (announcer) {
            announcer.remove();
            announcer = null;
        }
    });
}
