import { onMounted, onUnmounted, watch, type Ref } from 'vue';

/**
 * Installs a roving-tabindex keyboard fallback on a horizontal [role="toolbar"]
 * element ONLY when native HTML `focusgroup` is unsupported by the browser.
 */
export function useToolbarFocusgroup(toolbarRef: Ref<HTMLElement | null>): void {
    let attachedBar: HTMLElement | null = null;
    let cleanup: (() => void) | null = null;

    function detach(): void {
        if (cleanup) {
            cleanup();
            cleanup = null;
        }
        attachedBar = null;
    }

    function attach(bar: HTMLElement | null): void {
        if (bar === attachedBar) {
            return;
        }

        detach();

        if (
            !bar ||
            typeof HTMLElement === 'undefined' ||
            'focusGroup' in HTMLElement.prototype
        ) {
            return;
        }

        const getItems = (): HTMLButtonElement[] =>
            Array.from(bar.querySelectorAll<HTMLButtonElement>('button')).filter(
                (button) => !button.disabled,
            );

        const syncTabStops = (active?: HTMLButtonElement | null): void => {
            const items = getItems();
            if (items.length === 0) {
                return;
            }

            const target =
                active && items.includes(active)
                    ? active
                    : (items.find((button) => button.getAttribute('tabindex') === '0') ?? items[0]);

            items.forEach((button) => {
                button.tabIndex = button === target ? 0 : -1;
            });
        };

        syncTabStops();

        const handleFocusIn = (event: FocusEvent): void => {
            const target = event.target;
            if (target instanceof HTMLButtonElement && getItems().includes(target)) {
                syncTabStops(target);
            }
        };

        const handleKeyDown = (event: KeyboardEvent): void => {
            const items = getItems();
            if (items.length === 0) {
                return;
            }

            const activeEl = document.activeElement;
            const currentIndex =
                activeEl instanceof HTMLButtonElement ? items.indexOf(activeEl) : -1;

            if (currentIndex === -1) {
                return;
            }

            const rtl =
                typeof getComputedStyle === 'function' &&
                getComputedStyle(bar).direction === 'rtl';

            let nextIndex: number | null = null;

            if (event.key === 'ArrowRight') {
                const step = rtl ? -1 : 1;
                nextIndex = (currentIndex + step + items.length) % items.length;
            } else if (event.key === 'ArrowLeft') {
                const step = rtl ? 1 : -1;
                nextIndex = (currentIndex + step + items.length) % items.length;
            } else if (event.key === 'Home') {
                nextIndex = 0;
            } else if (event.key === 'End') {
                nextIndex = items.length - 1;
            }

            if (nextIndex === null) {
                return;
            }

            event.preventDefault();
            const nextItem = items[nextIndex];
            if (nextItem) {
                syncTabStops(nextItem);
                nextItem.focus();
            }
        };

        bar.addEventListener('focusin', handleFocusIn);
        bar.addEventListener('keydown', handleKeyDown);
        attachedBar = bar;

        cleanup = () => {
            bar.removeEventListener('focusin', handleFocusIn);
            bar.removeEventListener('keydown', handleKeyDown);
        };
    }

    onMounted(() => {
        attach(toolbarRef.value);
    });

    watch(
        toolbarRef,
        (bar) => {
            attach(bar);
        },
        { flush: 'post' },
    );

    onUnmounted(() => {
        detach();
    });
}
