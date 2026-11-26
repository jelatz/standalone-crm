import { nextTick } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiRequestError } from '@/modules/crm/api/crmApi';

const { mockReadSop, mockCreateSop, mockUpdateSop, MockSopConflictError } = vi.hoisted(() => {
    class HoistedConflictError extends Error {
        public readonly status = 409;
        public readonly currentSha256: string;
        public readonly document: App.Modules.Core.Sops.Data.SopDocumentData;

        public constructor(
            message: string,
            currentSha256: string,
            document: App.Modules.Core.Sops.Data.SopDocumentData,
        ) {
            super(message);
            this.name = 'SopConflictError';
            this.currentSha256 = currentSha256;
            this.document = document;
        }
    }

    return {
        mockReadSop: vi.fn(),
        mockCreateSop: vi.fn(),
        mockUpdateSop: vi.fn(),
        MockSopConflictError: HoistedConflictError,
    };
});

vi.mock('@inertiajs/vue3', async () => {
    const vue = await import('vue');

    const HeadStub = vue.defineComponent({
        name: 'InertiaHeadStub',
        props: {
            title: { type: String, default: '' },
        },
        setup() {
            return () => null;
        },
    });

    return {
        Head: HeadStub,
    };
});

vi.mock('@/modules/core/api/sopsApi', async () => {
    const crmApi = await import('@/modules/crm/api/crmApi');
    return {
        ApiRequestError: crmApi.ApiRequestError,
        extractFirstFieldErrors: crmApi.extractFirstFieldErrors,
        SopConflictError: MockSopConflictError,
        sopsApi: {
            readSop: mockReadSop,
            createSop: mockCreateSop,
            updateSop: mockUpdateSop,
        },
    };
});

import SopsPage from '@/modules/core/pages/SopsPage.vue';

function makeDoc(
    overrides: Partial<App.Modules.Core.Sops.Data.SopDocumentData> = {},
): App.Modules.Core.Sops.Data.SopDocumentData {
    return {
        module: 'leads',
        slug: 'lead-intake-and-qualification',
        filename: 'lead-intake-and-qualification.md',
        relative_path: 'context/sops/leads/lead-intake-and-qualification.md',
        title: 'Lead Intake & Qualification',
        size_bytes: 64,
        updated_at: '2026-11-26T12:00:00Z',
        sha256: 'a'.repeat(64),
        content: '# Lead Intake & Qualification\n\nInitial SOP body.\n',
        ...overrides,
    };
}

function makeModules(): App.Modules.Core.Sops.Data.SopModuleGroupData[] {
    const leadDoc = makeDoc();
    const dealDoc = makeDoc({
        module: 'deals',
        slug: 'enterprise-qualification',
        filename: 'enterprise-qualification.md',
        relative_path: 'context/sops/deals/enterprise-qualification.md',
        title: 'Enterprise Qualification',
        sha256: 'b'.repeat(64),
        content: '# Enterprise Qualification\n\nDeal checklist.\n',
    });

    return [
        {
            module: 'leads',
            label: 'Leads',
            sops: [leadDoc],
        },
        {
            module: 'contacts',
            label: 'Contacts',
            sops: [],
        },
        {
            module: 'deals',
            label: 'Deals',
            sops: [dealDoc],
        },
    ];
}

describe('SopsPage', () => {
    let mountedWrappers: Array<{ unmount: () => void }> = [];

    beforeEach(() => {
        mockReadSop.mockReset();
        mockCreateSop.mockReset();
        mockUpdateSop.mockReset();
    });

    afterEach(() => {
        mountedWrappers.forEach((wrapper) => wrapper.unmount());
        mountedWrappers = [];
        document.body.innerHTML = '';
    });

    it('renders module directory, plain Markdown text box, and saves modifications via button and Cmd+S', async () => {
        const initialDoc = makeDoc();
        const updatedDoc = makeDoc({
            sha256: 'c'.repeat(64),
            content: '# Lead Intake & Qualification\n\nUpdated rules via UI.\n',
        });
        mockUpdateSop.mockResolvedValue(updatedDoc);

        const wrapper = mount(SopsPage, {
            attachTo: document.body,
            props: {
                modules: makeModules(),
                initialDocument: initialDoc,
            },
        });
        mountedWrappers.push(wrapper);

        expect(wrapper.get('[data-testid="active-sop-path"]').text()).toBe(
            'context/sops/leads/lead-intake-and-qualification.md',
        );
        expect(wrapper.get('[data-testid="sop-item-leads-lead-intake-and-qualification"]').text()).toContain(
            'lead-intake-and-qualification.md',
        );

        const textarea = wrapper.get<HTMLTextAreaElement>('[data-testid="sop-editor-textarea"]');
        expect(textarea.element.value).toBe(initialDoc.content);
        expect(wrapper.get('[data-testid="save-sop-button"]').attributes('disabled')).toBeDefined();

        await textarea.setValue(updatedDoc.content);
        await nextTick();
        expect(wrapper.find('[data-testid="sop-unsaved-badge"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="save-sop-button"]').attributes('disabled')).toBeUndefined();

        await wrapper.get('[data-testid="save-sop-button"]').trigger('click');
        await flushPromises();

        expect(mockUpdateSop).toHaveBeenCalledWith('leads', 'lead-intake-and-qualification', {
            content: updatedDoc.content,
            expected_sha256: initialDoc.sha256,
        });
        expect(wrapper.get('[data-testid="sop-saved-banner"]').text()).toContain(
            'context/sops/leads/lead-intake-and-qualification.md',
        );
        expect(wrapper.find('[data-testid="sop-unsaved-badge"]').exists()).toBe(false);

        // Verify Cmd+S also triggers save when dirty
        const secondUpdate = makeDoc({
            sha256: 'd'.repeat(64),
            content: '# Lead Intake & Qualification\n\nSaved via Cmd+S.\n',
        });
        mockUpdateSop.mockResolvedValue(secondUpdate);
        await textarea.setValue(secondUpdate.content);
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 's', metaKey: true, bubbles: true }));
        await flushPromises();

        expect(mockUpdateSop).toHaveBeenCalledTimes(2);
        expect(mockUpdateSop).toHaveBeenLastCalledWith('leads', 'lead-intake-and-qualification', {
            content: secondUpdate.content,
            expected_sha256: updatedDoc.sha256,
        });
    });

    it('guards unsaved edits when switching SOPs and switches only after confirming discard', async () => {
        const dealDoc = makeDoc({
            module: 'deals',
            slug: 'enterprise-qualification',
            filename: 'enterprise-qualification.md',
            relative_path: 'context/sops/deals/enterprise-qualification.md',
            title: 'Enterprise Qualification',
            sha256: 'b'.repeat(64),
            content: '# Enterprise Qualification\n\nDeal checklist.\n',
        });
        mockReadSop.mockResolvedValue(dealDoc);

        const wrapper = mount(SopsPage, {
            attachTo: document.body,
            props: {
                modules: makeModules(),
                initialDocument: makeDoc(),
            },
        });
        mountedWrappers.push(wrapper);

        const textarea = wrapper.get<HTMLTextAreaElement>('[data-testid="sop-editor-textarea"]');
        await textarea.setValue('# Unsaved local edit\n');

        await wrapper.get('[data-testid="sop-item-deals-enterprise-qualification"]').trigger('click');
        await flushPromises();

        expect(mockReadSop).not.toHaveBeenCalled();
        expect(document.body.querySelector('[data-testid="unsaved-changes-dialog"]')).not.toBeNull();

        const discardBtn = document.body.querySelector<HTMLButtonElement>(
            '[data-testid="confirm-discard-switch"]',
        );
        discardBtn?.click();
        await flushPromises();
        await nextTick();

        expect(mockReadSop).toHaveBeenCalledWith('deals', 'enterprise-qualification');
        expect(wrapper.get('[data-testid="active-sop-path"]').text()).toBe(
            'context/sops/deals/enterprise-qualification.md',
        );
        expect(
            wrapper.get<HTMLTextAreaElement>('[data-testid="sop-editor-textarea"]').element.value,
        ).toBe(dealDoc.content);
    });

    it('creates a new SOP under a brand-new module and updates the directory and editor', async () => {
        const createdDoc = makeDoc({
            module: 'renewals',
            slug: 'renewal-playbook',
            filename: 'renewal-playbook.md',
            relative_path: 'context/sops/renewals/renewal-playbook.md',
            title: 'Renewal Playbook',
            sha256: 'e'.repeat(64),
            content: '# Renewal Playbook\n\nReach out 90 days prior.\n',
        });
        mockCreateSop.mockResolvedValue(createdDoc);

        const wrapper = mount(SopsPage, {
            attachTo: document.body,
            props: {
                modules: makeModules(),
                initialDocument: makeDoc(),
            },
        });
        mountedWrappers.push(wrapper);

        await wrapper.get('[data-testid="open-new-sop-dialog"]').trigger('click');
        await flushPromises();
        await nextTick();

        const moduleSelect = document.body.querySelector<HTMLSelectElement>(
            '[data-testid="new-sop-module-select"]',
        );
        expect(moduleSelect).not.toBeNull();
        if (moduleSelect) {
            moduleSelect.value = '__new__';
            moduleSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }
        await nextTick();

        const customModuleInput = document.body.querySelector<HTMLInputElement>('#new-sop-custom-module');
        const nameInput = document.body.querySelector<HTMLInputElement>('#new-sop-name');
        const contentTextarea = document.body.querySelector<HTMLTextAreaElement>('#new-sop-content');

        expect(customModuleInput).not.toBeNull();
        expect(nameInput).not.toBeNull();
        expect(contentTextarea).not.toBeNull();

        if (customModuleInput) {
            customModuleInput.value = 'Renewals';
            customModuleInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (nameInput) {
            nameInput.value = 'Renewal Playbook';
            nameInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (contentTextarea) {
            contentTextarea.value = createdDoc.content;
            contentTextarea.dispatchEvent(new Event('input', { bubbles: true }));
        }
        await nextTick();

        expect(
            document.body.querySelector('[data-testid="new-sop-path-preview"]')?.textContent?.trim(),
        ).toBe('context/sops/renewals/renewal-playbook.md');

        const form = document.body.querySelector<HTMLFormElement>('[data-testid="new-sop-dialog"]');
        form?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await flushPromises();
        await nextTick();

        expect(mockCreateSop).toHaveBeenCalledWith({
            module: 'Renewals',
            name: 'Renewal Playbook',
            content: createdDoc.content,
        });
        expect(wrapper.get('[data-testid="active-sop-path"]').text()).toBe(
            'context/sops/renewals/renewal-playbook.md',
        );
        expect(wrapper.find('[data-testid="sop-item-renewals-renewal-playbook"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="sop-saved-banner"]').text()).toContain(
            'context/sops/renewals/renewal-playbook.md',
        );
    });

    it('handles 409 conflict reload, safe Write/Preview toggle, and loading/empty/error states', async () => {
        const latestDoc = makeDoc({
            sha256: 'f'.repeat(64),
            content: '# Lead Intake\n\n- Check **ICP** and [Docs](javascript:alert(1)).\n',
        });
        mockUpdateSop.mockRejectedValue(
            new MockSopConflictError(
                'This SOP was modified on disk since you opened it.',
                latestDoc.sha256,
                latestDoc,
            ),
        );

        const wrapper = mount(SopsPage, {
            attachTo: document.body,
            props: {
                modules: makeModules(),
                initialDocument: makeDoc(),
            },
        });
        mountedWrappers.push(wrapper);

        const textarea = wrapper.get<HTMLTextAreaElement>('[data-testid="sop-editor-textarea"]');
        await textarea.setValue('# Conflicting edit\n');
        await wrapper.get('[data-testid="save-sop-button"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="sop-conflict-banner"]').text()).toContain(
            'modified on disk',
        );

        await wrapper.get('[data-testid="reload-latest-sop"]').trigger('click');
        await nextTick();
        expect(textarea.element.value).toBe(latestDoc.content);

        // Toggle Preview tab and verify safe Markdown rendering without dangerous links
        await wrapper.get('[data-testid="tab-preview"]').trigger('click');
        await nextTick();
        const preview = wrapper.get('[data-testid="sop-markdown-preview"]');
        expect(preview.text()).toContain('Lead Intake');
        expect(preview.text()).toContain('ICP');
        expect(preview.find('a[href^="javascript:"]').exists()).toBe(false);

        // Loading, empty, and error states
        const loadingWrapper = mount(SopsPage, {
            attachTo: document.body,
            props: { initialState: 'loading' },
        });
        mountedWrappers.push(loadingWrapper);
        expect(loadingWrapper.find('[data-testid="sop-loading-state"]').exists()).toBe(true);

        const emptyWrapper = mount(SopsPage, {
            attachTo: document.body,
            props: { modules: [], initialDocument: null },
        });
        mountedWrappers.push(emptyWrapper);
        expect(emptyWrapper.find('[data-testid="sop-empty-state"]').exists()).toBe(true);

        const errorWrapper = mount(SopsPage, {
            attachTo: document.body,
            props: { initialState: 'error', initialError: 'Disk read failure.' },
        });
        mountedWrappers.push(errorWrapper);
        expect(errorWrapper.get('[data-testid="sop-error-state"]').text()).toContain(
            'Disk read failure.',
        );
    });

    it('renders API error message when loading an SOP fails', async () => {
        mockReadSop.mockRejectedValue(new ApiRequestError('SOP file missing.', 404));

        const wrapper = mount(SopsPage, {
            attachTo: document.body,
            props: {
                modules: makeModules(),
                initialDocument: makeDoc(),
            },
        });
        mountedWrappers.push(wrapper);

        await wrapper.get('[data-testid="sop-item-deals-enterprise-qualification"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="sop-error-banner"]').text()).toContain('SOP file missing.');
    });

    it('renders accessible Markdown toolbar with roving keyboard focus and formats text in Write mode', async () => {
        const wrapper = mount(SopsPage, {
            attachTo: document.body,
            props: {
                modules: makeModules(),
                initialDocument: makeDoc({
                    content: '# Lead Intake\n\nVerify domain check.\n',
                }),
            },
        });
        mountedWrappers.push(wrapper);

        const toolbar = wrapper.get('[data-testid="sop-markdown-toolbar"]');
        expect(toolbar.attributes('role')).toBe('toolbar');
        expect(toolbar.attributes('focusgroup')).toBe('toolbar wrap');
        expect(toolbar.attributes('aria-controls')).toBe('sop-editor-textarea');

        const headingBtn = wrapper.get<HTMLButtonElement>('[data-testid="toolbar-heading"]').element;
        const boldBtn = wrapper.get<HTMLButtonElement>('[data-testid="toolbar-bold"]').element;
        const guideBtn = wrapper.get<HTMLButtonElement>('[data-testid="open-markdown-guide"]').element;

        expect(headingBtn.tabIndex).toBe(0);
        expect(boldBtn.tabIndex).toBe(-1);

        headingBtn.focus();
        toolbar.element.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
        expect(document.activeElement).toBe(boldBtn);
        expect(boldBtn.tabIndex).toBe(0);
        expect(headingBtn.tabIndex).toBe(-1);

        toolbar.element.dispatchEvent(new KeyboardEvent('keydown', { key: 'End', bubbles: true }));
        expect(document.activeElement).toBe(guideBtn);

        toolbar.element.dispatchEvent(new KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
        expect(document.activeElement).toBe(headingBtn);

        const textarea = wrapper.get<HTMLTextAreaElement>('[data-testid="sop-editor-textarea"]').element;
        const domainStart = textarea.value.indexOf('domain');
        textarea.setSelectionRange(domainStart, domainStart + 'domain'.length);

        await wrapper.get('[data-testid="toolbar-bold"]').trigger('click');
        await nextTick();
        expect(textarea.value).toContain('Verify **domain** check.');
        expect(wrapper.find('[data-testid="sop-unsaved-badge"]').exists()).toBe(true);

        textarea.setSelectionRange(textarea.value.length, textarea.value.length);
        await wrapper.get('[data-testid="toolbar-table"]').trigger('click');
        await nextTick();
        expect(textarea.value).toContain('| Condition | Required Action |');
    });

    it('opens the Markdown Guide dialog, inserts a snippet at cursor, and provides inline guide and starter template in New SOP dialog', async () => {
        const wrapper = mount(SopsPage, {
            attachTo: document.body,
            props: {
                modules: makeModules(),
                initialDocument: makeDoc({
                    content: '# Lead Intake\n\nExisting paragraph.\n',
                }),
            },
        });
        mountedWrappers.push(wrapper);

        const textarea = wrapper.get<HTMLTextAreaElement>('[data-testid="sop-editor-textarea"]').element;
        textarea.setSelectionRange(textarea.value.length, textarea.value.length);

        await wrapper.get('[data-testid="open-markdown-guide"]').trigger('click');
        await flushPromises();
        await nextTick();

        const guideDialog = document.body.querySelector('[data-testid="markdown-guide-dialog"]');
        expect(guideDialog).not.toBeNull();

        const blockquoteInsert = guideDialog?.querySelector<HTMLButtonElement>(
            '[data-testid="guide-insert-blockquote"]',
        );
        expect(blockquoteInsert).not.toBeNull();
        blockquoteInsert?.click();
        await flushPromises();
        await nextTick();

        expect(document.body.querySelector('[data-testid="markdown-guide-dialog"]')).toBeNull();
        expect(textarea.value).toContain('> **Golden Rule:**');

        // Verify inline guide + starter template inside New SOP dialog
        await wrapper.get('[data-testid="open-new-sop-dialog"]').trigger('click');
        await flushPromises();
        await nextTick();

        const newSopNameInput = document.body.querySelector<HTMLInputElement>('#new-sop-name');
        if (newSopNameInput) {
            newSopNameInput.value = 'Partner Referral Intake';
            newSopNameInput.dispatchEvent(new Event('input', { bubbles: true }));
        }

        const guideToggle = document.body.querySelector<HTMLButtonElement>(
            '[data-testid="toggle-new-sop-markdown-guide"]',
        );
        expect(guideToggle?.getAttribute('aria-expanded')).toBe('false');
        guideToggle?.click();
        await nextTick();

        expect(guideToggle?.getAttribute('aria-expanded')).toBe('true');
        const inlineGuide = document.body.querySelector('[data-testid="new-sop-markdown-guide"]');
        expect(inlineGuide).not.toBeNull();

        const insertTemplateBtn = document.body.querySelector<HTMLButtonElement>(
            '[data-testid="new-sop-insert-template"]',
        );
        insertTemplateBtn?.click();
        await nextTick();

        const newContentTextarea = document.body.querySelector<HTMLTextAreaElement>('#new-sop-content');
        expect(newContentTextarea?.value).toContain('# Standard Operating Procedure: Partner Referral Intake');
        expect(newContentTextarea?.value).toContain('## 1. Purpose & Golden Rule');

        const insertHeadingBtn = inlineGuide?.querySelector<HTMLButtonElement>(
            '[data-testid="guide-insert-heading"]',
        );
        if (newContentTextarea) {
            newContentTextarea.setSelectionRange(
                newContentTextarea.value.length,
                newContentTextarea.value.length,
            );
        }
        insertHeadingBtn?.click();
        await nextTick();

        expect(newContentTextarea?.value).toContain('## Section Heading');
    });
});
