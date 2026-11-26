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
import { useRealtimeStore } from '@/stores/realtime.store';

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

const { mockPage, mockRouterVisit, mockRouterPost, mockFormStateRef } = vi.hoisted(() => {
    const page: MockPageState = {
        url: '/app',
        component: 'crm/pages/CrmOverviewPage',
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
        mockFormStateRef: { current: null as null | { errors: Record<string, string> } },
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

            return (() => {
                mockFormStateRef.current = formState;
                return formState;
            })();
        },
        router: {
            visit: mockRouterVisit,
            post: mockRouterPost,
            reload: vi.fn(),
        },
    };
});

import CrmShellLayout from '@/layouts/CrmShellLayout.vue';
import LoginPage from '@/modules/core/pages/LoginPage.vue';
import UsersPage from '@/modules/core/pages/UsersPage.vue';
import CrmOverviewPage from '@/modules/crm/pages/CrmOverviewPage.vue';

function makeUser(): App.Modules.Core.Auth.Data.AuthenticatedUserData {
    return {
        id: 'usr-100',
        name: 'Jordan Mercer',
        email: 'jordan.mercer@company.internal',
        employee_code: 'EMP-0142',
        job_title: 'Sales Director',
        department_id: 'dept-sales',
        roles: ['director'],
        permissions: [
            'leads.view',
            'leads.create',
            'leads.convert',
            'contacts.view',
            'companies.view',
            'deals.view',
        ],
    };
}

function makeOverviewData(
    overrides: Partial<App.Modules.Crm.Overview.Data.CrmOverviewData> = {},
): App.Modules.Crm.Overview.Data.CrmOverviewData {
    return {
        open_leads_count: 14,
        qualified_leads_count: 5,
        open_pipeline_cents: 142850000,
        open_deals_count: 8,
        weighted_forecast_cents: 76400000,
        won_this_month_cents: 28000000,
        won_this_month_count: 2,
        companies_count: 22,
        contacts_count: 48,
        pipeline_name: 'New Business',
        stage_breakdown: [
            {
                stage_id: 'stg-1',
                stage_name: 'Discovery',
                stage_type: 'open',
                win_probability: 25,
                deals_count: 4,
                total_amount_cents: 62000000,
                weighted_amount_cents: 15500000,
            },
            {
                stage_id: 'stg-2',
                stage_name: 'Proposal',
                stage_type: 'open',
                win_probability: 50,
                deals_count: 4,
                total_amount_cents: 80850000,
                weighted_amount_cents: 60900000,
            },
        ],
        recent_leads: [
            {
                id: 'lead-101',
                owner_id: 'usr-100',
                owner_name: 'Jordan Mercer',
                first_name: 'Elena',
                last_name: 'Vance',
                full_name: 'Elena Vance',
                email: 'elena@northwind.io',
                phone: '+1-415-555-0100',
                job_title: 'VP Operations',
                company_name: 'Northwind Logistics',
                website: 'https://northwind.io',
                status: 'qualified',
                source: 'inbound_web',
                estimated_value_cents: 4500000,
                currency: 'USD',
                converted_at: null,
                converted_company_id: null,
                converted_contact_id: null,
                converted_deal_id: null,
                custom_fields: {},
                created_at: '2026-11-20T10:00:00Z',
                updated_at: '2026-11-25T10:00:00Z',
            },
        ],
        closing_soon_deals: [
            {
                id: 'deal-101',
                company_id: 'comp-101',
                company_name: 'Northwind Logistics',
                primary_contact_id: 'cont-101',
                primary_contact_name: 'Hannah Kowalski',
                pipeline_id: 'pipe-1',
                stage_id: 'stg-2',
                stage_name: 'Proposal',
                stage_type: 'open',
                owner_id: 'usr-100',
                owner_name: 'Jordan Mercer',
                account_manager_id: null,
                account_manager_name: null,
                title: 'Northwind WMS Rollout',
                amount_cents: 48000000,
                currency: 'USD',
                expected_close_date: '2026-12-10',
                win_probability: 75,
                effective_win_probability: 75,
                stage_entered_at: '2026-11-15T10:00:00Z',
                won_at: null,
                lost_at: null,
                board_position: 65536,
                version: 1,
                is_rotting: false,
                custom_fields: {},
                updated_at: '2026-11-25T10:00:00Z',
            },
        ],
        ...overrides,
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

describe('CrmShellLayout & Core Pages', () => {
    let mountedWrappers: Array<{ unmount: () => void }> = [];

    beforeEach(() => {
        setActivePinia(createPinia());
        mockRouterVisit.mockReset();
        mockRouterPost.mockReset();
        localStorage.clear();
        mockPage.url = '/app';
        mockPage.component = 'crm/pages/CrmOverviewPage';
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

    it('preserves Echo subscription across Inertia page swaps with zero Drafts tray or workspace nav links', async () => {
        const mockEcho = createMockEcho();
        setEchoInstanceForTesting(mockEcho.client);

        const activeComponent = shallowRef<Component>(CrmOverviewPage);
        const activeProps = reactive<Record<string, unknown>>({
            overview: makeOverviewData(),
            can: {
                viewLeads: true,
                createLead: true,
                viewCompanies: true,
                viewContacts: true,
                viewDeals: true,
                createDeal: true,
            },
        });

        const InertiaShellHost = defineComponent({
            setup() {
                return () =>
                    h(CrmShellLayout, null, {
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

        expect(realtimeStore.status).toBe('connected');
        expect(realtimeStore.subscribedUserId).toBe('usr-100');
        expect(mockEcho.privateSpy).toHaveBeenCalledTimes(1);
        expect(mockEcho.privateSpy).toHaveBeenCalledWith('user.usr-100');
        expect(wrapper.get('[data-testid="realtime-status-indicator"]').text()).toContain('Live');

        // Pure CRM rail and top bar must NOT expose legacy Drafts tray or Delivery/Messaging links
        expect(wrapper.find('[data-testid="topbar-new-draft"]').exists()).toBe(false);
        expect(wrapper.find('a[href="/app/projects"]').exists()).toBe(false);
        expect(wrapper.find('a[href="/app/my-work"]').exists()).toBe(false);
        expect(wrapper.find('a[href="/app/chat"]').exists()).toBe(false);
        expect(wrapper.find('a[href="/app/email"]').exists()).toBe(false);
        expect(wrapper.find('a[href="/app/crm/leads"]').exists()).toBe(true);

        // Simulate Inertia navigation from /app -> /app/crm/leads (using mounted slot swap)
        mockPage.url = '/app/crm/leads';
        mockPage.component = 'crm/pages/CrmOverviewPage';
        Object.assign(activeProps, {
            overview: makeOverviewData({ open_leads_count: 19 }),
        });
        await nextTick();

        expect(wrapper.text()).toContain('19');
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

        const wrapper = mount(CrmShellLayout, {
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

        const searchInput = document.body.querySelector<HTMLInputElement>('#crm-command-search');
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

    it('renders CrmOverviewPage KPI strip, recent leads, closing soon deals, and loading/empty/error states', async () => {
        const defaultCan = {
            viewLeads: true,
            createLead: true,
            viewCompanies: true,
            viewContacts: true,
            viewDeals: true,
            createDeal: true,
        };

        const readyWrapper = mount(CrmOverviewPage, {
            attachTo: document.body,
            props: {
                overview: makeOverviewData(),
                can: defaultCan,
            },
        });
        mountedWrappers.push(readyWrapper);

        expect(readyWrapper.get('[data-testid="overview-kpi-strip"]').text()).toContain('$1,428,500');
        expect(readyWrapper.get('[data-testid="overview-kpi-strip"]').text()).toContain('$764,000');
        expect(readyWrapper.get('[data-testid="overview-recent-leads"]').text()).toContain('Elena Vance');
        expect(readyWrapper.get('[data-testid="overview-closing-soon-deals"]').text()).toContain(
            'Northwind WMS Rollout',
        );

        // Empty leads & deals state
        const emptyWrapper = mount(CrmOverviewPage, {
            attachTo: document.body,
            props: {
                overview: makeOverviewData({
                    recent_leads: [],
                    closing_soon_deals: [],
                    stage_breakdown: [],
                }),
                can: defaultCan,
            },
        });
        mountedWrappers.push(emptyWrapper);
        expect(emptyWrapper.find('[data-testid="overview-recent-leads-empty"]').exists()).toBe(true);
        expect(emptyWrapper.find('[data-testid="overview-closing-soon-empty"]').exists()).toBe(true);

        // Loading state
        const loadingWrapper = mount(CrmOverviewPage, {
            attachTo: document.body,
            props: {
                overview: makeOverviewData(),
                can: defaultCan,
                initialState: 'loading',
            },
        });
        mountedWrappers.push(loadingWrapper);
        expect(loadingWrapper.find('[data-testid="overview-loading"]').exists()).toBe(true);

        // Error state
        const errorWrapper = mount(CrmOverviewPage, {
            attachTo: document.body,
            props: {
                overview: makeOverviewData(),
                can: defaultCan,
                initialState: 'error',
                initialError: 'Upstream CRM query unavailable.',
            },
        });
        mountedWrappers.push(errorWrapper);
        const errorState = errorWrapper.get('[data-testid="overview-error"]');
        expect(errorState.attributes('role')).toBe('alert');
        expect(errorState.text()).toContain('Upstream CRM query unavailable.');
    });

    it('renders LoginPage with email/password autofill, show-password toggle, and role="alert" error banner', async () => {
        const loginLayout = (LoginPage as { layout?: () => unknown }).layout;
        expect(typeof loginLayout).toBe('function');
        expect(loginLayout?.()).toBeNull();

        const wrapper = mount(LoginPage, {
            attachTo: document.body,
            props: {
                demoSuperadmin: {
                    email: 'superadmin@example.com',
                    password: 'SuperAdmin123!',
                },
            },
        });
        mountedWrappers.push(wrapper);

        const emailInput = wrapper.get<HTMLInputElement>('input#email');
        const passwordInput = wrapper.get<HTMLInputElement>('input#password');

        expect(emailInput.attributes('type')).toBe('email');
        expect(emailInput.attributes('autocomplete')).toBe('username');
        expect(emailInput.attributes('spellcheck')).toBe('false');
        expect(passwordInput.attributes('type')).toBe('password');
        expect(passwordInput.attributes('autocomplete')).toBe('current-password');

        await wrapper.get('[data-testid="fill-superadmin-credentials"]').trigger('click');
        expect(emailInput.element.value).toBe('superadmin@example.com');
        expect(passwordInput.element.value).toBe('SuperAdmin123!');

        await wrapper.get('[data-testid="toggle-password-visibility"]').trigger('click');
        expect(passwordInput.attributes('type')).toBe('text');

        if (mockFormStateRef.current) {
            mockFormStateRef.current.errors = { email: 'These credentials do not match our records.' };
        }
        await nextTick();
        const alertBanner = wrapper.get('[role="alert"]');
        expect(alertBanner.text()).toContain('These credentials do not match our records.');
    });

    it('gates Admin Users and SOPs links by superadmin role and renders UsersPage with autocomplete="new-password" modal', async () => {
        const mockEcho = createMockEcho();
        setEchoInstanceForTesting(mockEcho.client);

        // Standard user without admin.users must NOT see Users or SOPs links
        const standardWrapper = mount(CrmShellLayout, {
            attachTo: document.body,
            slots: { default: () => h('div', 'Body') },
        });
        mountedWrappers.push(standardWrapper);
        await nextTick();
        expect(standardWrapper.find('a[href="/app/admin/users"]').exists()).toBe(false);
        expect(standardWrapper.find('a[href="/app/admin/sops"]').exists()).toBe(false);

        // Regular admin (non-superadmin) must NOT see Admin Users or SOPs links
        mockPage.props.auth = {
            user: {
                ...makeUser(),
                roles: ['admin'],
                permissions: ['admin.users'],
            },
        };
        const adminWrapper = mount(CrmShellLayout, {
            attachTo: document.body,
            slots: { default: () => h('div', 'Body') },
        });
        mountedWrappers.push(adminWrapper);
        await nextTick();
        expect(adminWrapper.find('a[href="/app/admin/users"]').exists()).toBe(false);
        expect(adminWrapper.find('a[href="/app/admin/sops"]').exists()).toBe(false);

        // Superadmin with admin.users sees Admin -> Users and Admin -> SOPs nav links
        mockPage.props.auth = {
            user: {
                ...makeUser(),
                roles: ['superadmin'],
                permissions: ['admin.users'],
            },
        };
        const superWrapper = mount(CrmShellLayout, {
            attachTo: document.body,
            slots: { default: () => h('div', 'Body') },
        });
        mountedWrappers.push(superWrapper);
        await nextTick();
        expect(superWrapper.find('a[href="/app/admin/users"]').exists()).toBe(true);
        expect(superWrapper.find('a[href="/app/admin/sops"]').exists()).toBe(true);

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

    it('enforces auth RBAC helpers, safe Echo fallback without Reverb key, crm-theme toggle, and logout lock', async () => {
        const authStore = useAuthStore();
        authStore.setUser(makeUser());

        expect(authStore.isAuthenticated).toBe(true);
        expect(authStore.hasRole('director')).toBe(true);
        expect(authStore.hasRole('admin')).toBe(false);
        expect(authStore.hasPermission('leads.convert')).toBe(true);
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

        const wrapper = mount(CrmShellLayout, {
            attachTo: document.body,
            slots: {
                default: () => h('div', 'Shell body'),
            },
        });
        mountedWrappers.push(wrapper);
        await nextTick();

        await wrapper.get('[data-testid="theme-toggle"]').trigger('click');
        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(localStorage.getItem('crm-theme')).toBe('dark');

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
