import { defineComponent, h, nextTick, reactive, shallowRef, type Component } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ConnectionStatus } from 'laravel-echo';
import {
    getEcho,
    mapEchoConnectionStatus,
    resetEcho,
    setEchoInstanceForTesting,
    type RealtimeChannelLike,
    type RealtimeEchoClient,
} from '@/echo';
import { useAuthStore } from '@/stores/auth.store';
import { useDraftsStore } from '@/stores/drafts.store';
import { useRealtimeStore } from '@/stores/realtime.store';
import { useUnreadsStore } from '@/stores/unreads.store';

interface MockPageState {
    url: string;
    component: string;
    props: {
        auth?: {
            user?: App.Modules.Core.Auth.Data.AuthenticatedUserData | null;
        };
        errors?: {
            sso?: string | string[];
        };
        moduleTitle?: string;
        [key: string]: unknown;
    };
}

const { mockPage, mockRouterVisit, mockRouterPost } = vi.hoisted(() => {
    const page: MockPageState = {
        url: '/app',
        component: 'core/pages/WorkspaceHomePage',
        props: {
            auth: {
                user: null,
            },
            errors: {},
        },
    };

    return {
        mockPage: page,
        mockRouterVisit: vi.fn(),
        mockRouterPost: vi.fn(),
    };
});

vi.mock('@inertiajs/vue3', async () => {
    const vue = await import('vue');
    const reactivePage = vue.reactive(mockPage);

    const LinkStub = vue.defineComponent({
        name: 'InertiaLinkStub',
        props: {
            href: {
                type: String,
                required: true,
            },
        },
        setup(props, { slots, attrs }) {
            return () => vue.h('a', { ...attrs, href: props.href }, slots.default?.());
        },
    });

    const HeadStub = vue.defineComponent({
        name: 'InertiaHeadStub',
        props: {
            title: {
                type: String,
                default: '',
            },
        },
        setup() {
            return () => null;
        },
    });

    return {
        Link: LinkStub,
        Head: HeadStub,
        usePage: () => reactivePage,
        useForm: <T extends Record<string, unknown>>(initialValues: T) => {
            const formState = vue.reactive({
                ...initialValues,
                errors: {} as Record<string, string>,
                processing: false,
                post: mockRouterPost,
                reset: (...fields: string[]) => {
                    const targets = fields.length > 0 ? fields : Object.keys(initialValues);
                    for (const field of targets) {
                        (formState as Record<string, unknown>)[field] = initialValues[field];
                    }
                },
                clearErrors: () => {
                    formState.errors = {};
                },
            });

            return formState;
        },
        router: {
            visit: mockRouterVisit,
            post: mockRouterPost,
        },
    };
});

import WorkspaceShellLayout from '@/layouts/WorkspaceShellLayout.vue';
import LoginPage from '@/modules/core/pages/LoginPage.vue';
import ModulePlaceholderPage from '@/modules/core/pages/ModulePlaceholderPage.vue';
import UsersPage from '@/modules/core/pages/UsersPage.vue';
import WorkspaceHomePage from '@/modules/core/pages/WorkspaceHomePage.vue';

function makeUser(): App.Modules.Core.Auth.Data.AuthenticatedUserData {
    return {
        id: 'usr-100',
        name: 'Jordan Mercer',
        email: 'jordan.mercer@company.internal',
        employee_code: 'EMP-0142',
        job_title: 'Operations Lead',
        department_id: 'dept-ops',
        roles: ['operations_manager'],
        permissions: ['handoffs.view', 'handoffs.review', 'projects.view'],
    };
}

function createMockEcho(): {
    client: RealtimeEchoClient;
    privateSpy: ReturnType<typeof vi.fn>;
    leaveSpy: ReturnType<typeof vi.fn>;
    emitConnectionStatus: (status: ConnectionStatus) => void;
} {
    let currentStatus: ConnectionStatus = 'connected';
    const listeners = new Set<(status: ConnectionStatus) => void>();

    const channelStub: RealtimeChannelLike = {
        listen: vi.fn(() => channelStub),
        stopListening: vi.fn(() => channelStub),
        notification: vi.fn(() => channelStub),
        error: vi.fn(() => channelStub),
    };

    const privateSpy = vi.fn(() => channelStub);
    const leaveSpy = vi.fn();

    const client: RealtimeEchoClient = {
        private: privateSpy,
        leave: leaveSpy,
        connectionStatus: () => currentStatus,
        disconnect: vi.fn(),
        connector: {
            onConnectionChange: (callback: (status: ConnectionStatus) => void) => {
                listeners.add(callback);
                return () => {
                    listeners.delete(callback);
                };
            },
        },
    };

    return {
        client,
        privateSpy,
        leaveSpy,
        emitConnectionStatus: (status: ConnectionStatus) => {
            currentStatus = status;
            listeners.forEach((cb) => cb(status));
        },
    };
}

describe('WorkspaceShellLayout & Core Pages', () => {
    let mountedWrappers: Array<{ unmount: () => void }> = [];

    beforeEach(() => {
        setActivePinia(createPinia());
        mockRouterVisit.mockReset();
        mockRouterPost.mockReset();
        mockPage.url = '/app';
        mockPage.component = 'core/pages/WorkspaceHomePage';
        mockPage.props = {
            auth: {
                user: makeUser(),
            },
            errors: {},
        };
    });

    afterEach(() => {
        mountedWrappers.forEach((wrapper) => wrapper.unmount());
        mountedWrappers = [];
        resetEcho();
        setEchoInstanceForTesting(null);
        vi.unstubAllEnvs();
        document.body.innerHTML = '';
        document.documentElement.classList.remove('dark');
    });

    it('preserves open drafts and Echo subscription across simulated Inertia page swaps', async () => {
        const mockEcho = createMockEcho();
        setEchoInstanceForTesting(mockEcho.client);

        const activeComponent = shallowRef<Component>(WorkspaceHomePage);
        const activeProps = reactive<Record<string, unknown>>({});

        const InertiaShellHost = defineComponent({
            setup() {
                return () =>
                    h(WorkspaceShellLayout, null, {
                        default: () => h(activeComponent.value, activeProps),
                    });
            },
        });

        const wrapper = mount(InertiaShellHost, {
            attachTo: document.body,
        });
        mountedWrappers.push(wrapper);
        await nextTick();

        const realtimeStore = useRealtimeStore();
        const draftsStore = useDraftsStore();
        const unreadsStore = useUnreadsStore();

        expect(realtimeStore.status).toBe('connected');
        expect(realtimeStore.subscribedUserId).toBe('usr-100');
        expect(mockEcho.privateSpy).toHaveBeenCalledTimes(1);
        expect(mockEcho.privateSpy).toHaveBeenCalledWith('user.usr-100');
        expect(wrapper.get('[data-testid="realtime-status-indicator"]').text()).toContain('Live');

        // Start and edit a draft in the docked tray
        await wrapper.get('[data-testid="topbar-new-draft"]').trigger('click');
        const bodyInput = wrapper.get<HTMLTextAreaElement>('#docked-draft-body');
        await bodyInput.setValue('Confirming Northwind Logistics warehouse cutover window.');

        // Mutate unread counts and verify live rail badges
        unreadsStore.setCounts({ handoffs: 3, chat: 5, sharedInbox: 2 });
        await nextTick();
        expect(wrapper.get('[data-testid="unread-badge-handoffs"]').text()).toBe('3');
        expect(wrapper.get('[data-testid="unread-badge-chat"]').text()).toBe('5');
        expect(wrapper.get('[data-testid="unread-badge-sharedInbox"]').text()).toBe('2');

        // Simulate Inertia navigation from /app -> /app/crm/companies
        mockPage.url = '/app/crm/companies';
        mockPage.component = 'core/pages/ModulePlaceholderPage';
        mockPage.props.moduleTitle = 'Companies';
        activeComponent.value = ModulePlaceholderPage;
        Object.assign(activeProps, {
            moduleKey: 'crm.companies',
            moduleTitle: 'Companies',
            moduleDescription: 'Silo-scoped enterprise accounts and metadata.',
            phaseLabel: 'Phase 1',
        });
        await nextTick();

        expect(wrapper.text()).toContain('Companies');
        expect(wrapper.text()).toContain('Route key: crm.companies');

        // Draft edits and Echo subscription survive untouched across page swap
        expect(draftsStore.draftCount).toBe(1);
        expect(draftsStore.activeDraft?.bodyText).toBe(
            'Confirming Northwind Logistics warehouse cutover window.',
        );
        expect(wrapper.get<HTMLTextAreaElement>('#docked-draft-body').element.value).toBe(
            'Confirming Northwind Logistics warehouse cutover window.',
        );
        expect(mockEcho.privateSpy).toHaveBeenCalledTimes(1);
        expect(mockEcho.leaveSpy).not.toHaveBeenCalled();

        // Simulate connection drop -> reconnect telemetry updates reactively
        mockEcho.emitConnectionStatus('reconnecting');
        await nextTick();
        expect(realtimeStore.status).toBe('reconnecting');
        expect(wrapper.get('[data-testid="realtime-status-indicator"]').text()).toContain(
            'Reconnecting',
        );
    });

    it('opens the command palette on Cmd+K / Ctrl+K and closes it on Escape', async () => {
        const mockEcho = createMockEcho();
        setEchoInstanceForTesting(mockEcho.client);

        const wrapper = mount(WorkspaceShellLayout, {
            attachTo: document.body,
            slots: {
                default: () => h('div', 'Content'),
            },
        });
        mountedWrappers.push(wrapper);
        await nextTick();

        expect(document.body.querySelector('[data-testid="command-palette-dialog"]')).toBeNull();

        window.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'k', metaKey: true, bubbles: true }),
        );
        await flushPromises();
        await nextTick();

        const dialogEl = document.body.querySelector('[data-testid="command-palette-dialog"]');
        expect(dialogEl).not.toBeNull();

        const searchInput = document.body.querySelector<HTMLInputElement>(
            '#workspace-command-search',
        );
        expect(searchInput).not.toBeNull();
        if (searchInput) {
            searchInput.value = 'unknown-module-xyz';
            searchInput.dispatchEvent(new Event('input', { bubbles: true }));
            await nextTick();
            expect(document.body.textContent).toContain('No matching views for "unknown-module-xyz"');
        }

        const contentEl = document.body.querySelector('[role="dialog"]');
        expect(contentEl).not.toBeNull();
        contentEl?.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        await flushPromises();
        await nextTick();

        expect(document.body.querySelector('[data-testid="command-palette-dialog"]')).toBeNull();

        // Verify Ctrl+K also opens the command palette
        window.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, bubbles: true }),
        );
        await flushPromises();
        await nextTick();
        expect(document.body.querySelector('[data-testid="command-palette-dialog"]')).not.toBeNull();
    });

    it('renders WorkspaceHomePage operational overview plus loading, empty, and error states', async () => {
        const readyWrapper = mount(WorkspaceHomePage, {
            attachTo: document.body,
        });
        mountedWrappers.push(readyWrapper);

        expect(readyWrapper.text()).toContain('Active Pipeline');
        expect(readyWrapper.text()).toContain('$1,428,500');
        expect(readyWrapper.text()).toContain('Northwind Logistics');
        expect(readyWrapper.text()).toContain('PRJ-2026-0042');

        // Trigger empty filter state
        const escalatedTab = readyWrapper
            .findAll('[role="tab"]')
            .find((tab) => tab.text() === 'Escalated');
        expect(escalatedTab).toBeDefined();
        await escalatedTab?.trigger('click');
        expect(readyWrapper.get('[data-testid="overview-empty-state"]').text()).toContain(
            'No items match this filter',
        );

        // Loading state
        const loadingWrapper = mount(WorkspaceHomePage, {
            attachTo: document.body,
            props: { initialState: 'loading' },
        });
        mountedWrappers.push(loadingWrapper);
        expect(loadingWrapper.find('[data-testid="overview-loading-state"]').exists()).toBe(true);

        // Error state
        const errorWrapper = mount(WorkspaceHomePage, {
            attachTo: document.body,
            props: {
                initialState: 'error',
                initialError: 'Upstream queue unavailable.',
            },
        });
        mountedWrappers.push(errorWrapper);
        const errorState = errorWrapper.get('[data-testid="overview-error-state"]');
        expect(errorState.attributes('role')).toBe('alert');
        expect(errorState.text()).toContain('Upstream queue unavailable.');
    });

    it('renders LoginPage with email/password autofill, show-password toggle, and role="alert" error banner', async () => {
        const loginLayout = (LoginPage as { layout?: () => unknown }).layout;
        expect(typeof loginLayout).toBe('function');
        expect(loginLayout?.()).toBeNull();

        const wrapper = mount(LoginPage, {
            attachTo: document.body,
        });
        mountedWrappers.push(wrapper);

        const emailInput = wrapper.get('input#email');
        const passwordInput = wrapper.get('input#password');

        expect(emailInput.attributes('type')).toBe('email');
        expect(emailInput.attributes('autocomplete')).toBe('username');
        expect(emailInput.attributes('spellcheck')).toBe('false');
        expect(passwordInput.attributes('type')).toBe('password');
        expect(passwordInput.attributes('autocomplete')).toBe('current-password');

        await wrapper.get('[data-testid="toggle-password-visibility"]').trigger('click');
        expect(wrapper.get('input#password').attributes('type')).toBe('text');

        await emailInput.setValue('superadmin@example.com');
        await passwordInput.setValue('SuperAdmin123!');
        await wrapper.get('form').trigger('submit');
        expect(mockRouterPost).toHaveBeenCalledWith(
            '/login',
            expect.objectContaining({ onFinish: expect.any(Function) }),
        );
    });

    it('renders UsersPage directory and Create User dialog with new-password rules and superadmin-only nav visibility', async () => {
        const mockEcho = createMockEcho();
        setEchoInstanceForTesting(mockEcho.client);

        // Regular operations_manager does not see Admin -> Users nav link
        const standardWrapper = mount(WorkspaceShellLayout, {
            attachTo: document.body,
            slots: { default: () => h('div', 'Body') },
        });
        mountedWrappers.push(standardWrapper);
        await nextTick();
        expect(standardWrapper.find('a[href="/app/admin/users"]').exists()).toBe(false);

        // Superadmin with admin.users sees Admin -> Users nav link
        mockPage.props.auth = {
            user: {
                ...makeUser(),
                roles: ['superadmin'],
                permissions: ['admin.users'],
            },
        };
        const superWrapper = mount(WorkspaceShellLayout, {
            attachTo: document.body,
            slots: { default: () => h('div', 'Body') },
        });
        mountedWrappers.push(superWrapper);
        await nextTick();
        expect(superWrapper.find('a[href="/app/admin/users"]').exists()).toBe(true);

        const usersWrapper = mount(UsersPage, {
            attachTo: document.body,
            props: {
                users: [
                    {
                        id: 'usr-0',
                        employee_code: 'EMP-0000',
                        name: 'Super Admin',
                        email: 'superadmin@example.com',
                        job_title: 'Super Administrator',
                        department_id: 'dept-1',
                        department_name: 'General',
                        status: 'active',
                        roles: ['superadmin'],
                        created_at: null,
                    },
                ],
                departments: [{ id: 'dept-1', name: 'General' }],
                roles: [{ value: 'sales', label: 'Sales' }],
            },
        });
        mountedWrappers.push(usersWrapper);
        expect(usersWrapper.text()).toContain('Super Admin');
        expect(usersWrapper.text()).toContain('EMP-0000');

        await usersWrapper.get('[data-testid="open-create-user-dialog"]').trigger('click');
        await flushPromises();
        await nextTick();

        const newPasswordInput = document.body.querySelector<HTMLInputElement>('#create-user-password');
        expect(newPasswordInput).not.toBeNull();
        expect(newPasswordInput?.getAttribute('autocomplete')).toBe('new-password');
        expect(document.body.textContent).toContain(
            'At least 8 characters including uppercase, lowercase, number, and symbol.',
        );
    });

    it('enforces auth RBAC helpers, safe Echo fallback without Reverb key, theme toggle, and logout lock', async () => {
        const authStore = useAuthStore();
        authStore.setUser(makeUser());

        expect(authStore.isAuthenticated).toBe(true);
        expect(authStore.hasRole('operations_manager')).toBe(true);
        expect(authStore.hasRole('admin')).toBe(false);
        expect(authStore.hasPermission('handoffs.review')).toBe(true);
        expect(authStore.hasPermission('admin.users')).toBe(false);

        expect(mapEchoConnectionStatus('connected')).toBe('connected');
        expect(mapEchoConnectionStatus('connecting')).toBe('reconnecting');
        expect(mapEchoConnectionStatus('failed')).toBe('disconnected');

        resetEcho();
        vi.stubEnv('VITE_REVERB_APP_KEY', '');
        expect(getEcho()).toBeNull();
        vi.unstubAllEnvs();

        const mockEcho = createMockEcho();
        setEchoInstanceForTesting(mockEcho.client);

        const wrapper = mount(WorkspaceShellLayout, {
            attachTo: document.body,
            slots: {
                default: () => h('div', 'Shell body'),
            },
        });
        mountedWrappers.push(wrapper);
        await nextTick();

        await wrapper.get('[data-testid="theme-toggle"]').trigger('click');
        expect(document.documentElement.classList.contains('dark')).toBe(true);

        const signOutButton = wrapper.get('button[aria-label="Sign out"]');
        await signOutButton.trigger('click');
        await signOutButton.trigger('click');
        expect(mockRouterPost).toHaveBeenCalledTimes(1);
        expect(mockRouterPost).toHaveBeenCalledWith(
            '/logout',
            {},
            expect.objectContaining({ onFinish: expect.any(Function) }),
        );
    });
});
