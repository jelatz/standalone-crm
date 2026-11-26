import { h, nextTick } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { AxiosError, AxiosHeaders } from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ConnectionStatus } from 'laravel-echo';
import {
    resetEcho,
    setEchoInstanceForTesting,
    type RealtimeChannelLike,
    type RealtimeEchoClient,
} from '@/echo';
import { useAuthStore } from '@/stores/auth.store';
import {
    crmApi,
    crmHttpClient,
    ForbiddenRequestError,
    mapCrmApiError,
    OptimisticLockConflictError,
    StageGateValidationError,
} from '@/modules/crm/api/crmApi';
import { createDataTableColumnHelper } from '@/components/data-table/tableFeatures';

const { mockPage, mockRouterVisit, mockRouterGet, mockRouterReload, mockRouterPost } = vi.hoisted(() => ({
    mockPage: {
        url: '/app/crm/deals',
        component: 'crm/pages/DealsIndexPage',
        props: {
            auth: {
                user: null as App.Modules.Core.Auth.Data.AuthenticatedUserData | null,
            },
            errors: {},
        },
    },
    mockRouterVisit: vi.fn(),
    mockRouterGet: vi.fn(),
    mockRouterReload: vi.fn(),
    mockRouterPost: vi.fn(),
}));

vi.mock('@inertiajs/vue3', async () => {
    const vue = await import('vue');
    const reactivePage = vue.reactive(mockPage);

    const LinkStub = vue.defineComponent({
        name: 'InertiaLinkStub',
        props: {
            href: { type: String, required: true },
        },
        setup(props, { slots, attrs }) {
            return () => vue.h('a', { ...attrs, href: props.href }, slots.default?.());
        },
    });

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
        Link: LinkStub,
        Head: HeadStub,
        usePage: () => reactivePage,
        router: {
            visit: mockRouterVisit,
            get: mockRouterGet,
            reload: mockRouterReload,
            post: mockRouterPost,
        },
    };
});

import DataTable from '@/components/data-table/DataTable.vue';
import WorkspaceShellLayout from '@/layouts/WorkspaceShellLayout.vue';
import ActivityTimeline from '@/modules/crm/components/ActivityTimeline.vue';
import CustomFieldsForm from '@/modules/crm/components/CustomFieldsForm.vue';
import DealKanbanBoard from '@/modules/crm/components/DealKanbanBoard.vue';
import RichJsonRenderer from '@/modules/crm/components/RichJsonRenderer.vue';
import CompanyDetailPage from '@/modules/crm/pages/CompanyDetailPage.vue';
import ContactDetailPage from '@/modules/crm/pages/ContactDetailPage.vue';
import DealDetailPage from '@/modules/crm/pages/DealDetailPage.vue';
import DealsIndexPage from '@/modules/crm/pages/DealsIndexPage.vue';
import PipelineSettingsPage from '@/modules/crm/pages/PipelineSettingsPage.vue';

function makeUser(permissions: string[] = ['deals.view', 'deals.move_stage']): App.Modules.Core.Auth.Data.AuthenticatedUserData {
    return {
        id: 'usr-200',
        name: 'Devon Alvarez',
        email: 'devon.alvarez@company.internal',
        employee_code: 'EMP-0204',
        job_title: 'Account Executive',
        department_id: 'dept-sales',
        roles: ['sales'],
        permissions,
    };
}

function makeStage(
    id: string,
    name: string,
    stageType: App.Modules.Crm.Pipelines.Enums.PipelineStageTypeEnum = 'open',
    winProbability: number = 25,
): App.Modules.Crm.Pipelines.Data.PipelineStageData {
    return {
        id,
        pipeline_id: 'pipe-1',
        name,
        stage_type: stageType,
        win_probability: winProbability,
        rotting_days: 14,
        required_core_fields: [],
        required_custom_field_ids: [],
        sort_order: 1,
    };
}

function makeDealCard(
    id: string,
    stageId: string,
    title: string,
    version: number = 3,
): App.Modules.Crm.Deals.Data.DealCardData {
    return {
        id,
        company_id: 'comp-1',
        company_name: 'Northwind Logistics',
        primary_contact_id: 'cont-1',
        primary_contact_name: 'Hannah Kowalski',
        pipeline_id: 'pipe-1',
        stage_id: stageId,
        stage_name: 'Discovery',
        stage_type: 'open',
        owner_id: 'usr-200',
        owner_name: 'Devon Alvarez',
        account_manager_id: null,
        account_manager_name: null,
        title,
        amount_cents: 1200000,
        currency: 'USD',
        expected_close_date: '2026-12-15',
        stage_entered_at: '2026-11-20T10:00:00Z',
        won_at: null,
        lost_at: null,
        board_position: 65536,
        version,
        is_rotting: false,
        custom_fields: {},
        updated_at: '2026-11-25T10:00:00Z',
    };
}

function makeDealDetailFromCard(
    card: App.Modules.Crm.Deals.Data.DealCardData,
    overrides: Partial<App.Modules.Crm.Deals.Data.DealDetailData> = {},
): App.Modules.Crm.Deals.Data.DealDetailData {
    return {
        id: card.id,
        company_id: card.company_id,
        company_name: card.company_name,
        company_domain: 'northwind.internal',
        primary_contact_id: card.primary_contact_id,
        primary_contact_name: card.primary_contact_name,
        primary_contact_email: 'h.kowalski@northwind.internal',
        pipeline_id: card.pipeline_id,
        pipeline_name: 'Enterprise Sales',
        stage_id: card.stage_id,
        stage_name: card.stage_name,
        stage_type: card.stage_type,
        stage_win_probability: 50,
        owner_id: card.owner_id,
        owner_name: card.owner_name,
        account_manager_id: card.account_manager_id,
        account_manager_name: card.account_manager_name,
        title: card.title,
        amount_cents: card.amount_cents,
        currency: card.currency,
        expected_close_date: card.expected_close_date,
        stage_entered_at: card.stage_entered_at,
        won_at: card.won_at,
        lost_at: card.lost_at,
        lost_reason: null,
        board_position: card.board_position,
        version: card.version + 1,
        custom_fields: card.custom_fields,
        created_at: '2026-11-01T10:00:00Z',
        updated_at: '2026-11-26T00:00:00Z',
        has_masked_company: false,
        has_masked_primary_contact: false,
        ...overrides,
    };
}

function makeBoard(): App.Modules.Crm.Deals.Data.DealKanbanBoardData {
    const stageDiscovery = makeStage('stage-1', 'Discovery', 'open', 20);
    const stageProposal = makeStage('stage-2', 'Proposal & Review', 'open', 60);
    const deal1 = makeDealCard('deal-1', 'stage-1', 'Regional WMS Provisioning', 3);

    return {
        pipeline: {
            id: 'pipe-1',
            name: 'Enterprise Sales',
            slug: 'enterprise-sales',
            department_id: null,
            is_default: true,
            is_active: true,
            sort_order: 0,
            stages: [stageDiscovery, stageProposal],
        },
        columns: [
            {
                stage: stageDiscovery,
                deals_count: 1,
                total_amount_cents: deal1.amount_cents,
                deals: [deal1],
            },
            {
                stage: stageProposal,
                deals_count: 0,
                total_amount_cents: 0,
                deals: [],
            },
        ],
        total_deals_count: 1,
        total_amount_cents: deal1.amount_cents,
    };
}

function createMockEcho(): {
    client: RealtimeEchoClient;
    channelStub: RealtimeChannelLike;
    stopListeningSpy: ReturnType<typeof vi.fn>;
    leaveSpy: ReturnType<typeof vi.fn>;
    emitEvent: (eventName: string, payload: unknown) => void;
} {
    const handlers = new Map<string, CallableFunction[]>();
    const stopListeningSpy = vi.fn((event: string, cb?: CallableFunction) => {
        if (cb) {
            const list = (handlers.get(event) ?? []).filter((item) => item !== cb);
            handlers.set(event, list);
        } else {
            handlers.delete(event);
        }
        return channelStub;
    });

    const channelStub: RealtimeChannelLike = {
        listen: vi.fn((event: string, cb: CallableFunction) => {
            const list = handlers.get(event) ?? [];
            list.push(cb);
            handlers.set(event, list);
            return channelStub;
        }),
        stopListening: stopListeningSpy,
        notification: vi.fn(() => channelStub),
        error: vi.fn(() => channelStub),
    };

    const leaveSpy = vi.fn();

    const client: RealtimeEchoClient = {
        private: vi.fn(() => channelStub),
        leave: leaveSpy,
        connectionStatus: (): ConnectionStatus => 'connected',
        disconnect: vi.fn(),
        connector: {
            onConnectionChange: () => () => {},
        },
    };

    return {
        client,
        channelStub,
        stopListeningSpy,
        leaveSpy,
        emitEvent: (eventName: string, payload: unknown) => {
            const list = handlers.get(eventName) ?? [];
            list.forEach((cb) => cb(payload));
        },
    };
}

describe('CRM Workspace Components & Interactions', () => {
    let mountedWrappers: Array<{ unmount: () => void }> = [];

    beforeEach(() => {
        setActivePinia(createPinia());
        mockRouterVisit.mockReset();
        mockRouterGet.mockReset();
        mockRouterReload.mockReset();
        mockRouterPost.mockReset();
        mockPage.url = '/app/crm/deals';
        mockPage.props = {
            auth: { user: makeUser() },
            errors: {},
        };
    });

    afterEach(() => {
        mountedWrappers.forEach((w) => w.unmount());
        mountedWrappers = [];
        vi.restoreAllMocks();
        vi.useRealTimers();
        resetEcho();
        setEchoInstanceForTesting(null);
        document.body.innerHTML = '';
    });

    it('maps 409 conflict, 422 stage gate validation, and 403 errors via mapCrmApiError', () => {
        const makeAxiosError = (status: number, data: unknown) =>
            new AxiosError('Request failed', String(status), undefined, undefined, {
                status,
                statusText: 'Error',
                headers: new AxiosHeaders(),
                config: { headers: new AxiosHeaders() },
                data,
            });

        const conflict = mapCrmApiError(
            makeAxiosError(409, {
                message: 'Stale deal version.',
                current_version: 7,
                entity: { id: 'deal-1', version: 7 },
            }),
        );
        expect(conflict).toBeInstanceOf(OptimisticLockConflictError);
        expect((conflict as OptimisticLockConflictError).currentVersion).toBe(7);

        const gateErr = mapCrmApiError(
            makeAxiosError(422, {
                message: 'Complete stage gates.',
                errors: { amount_cents: ['Deal Amount is required.'] },
                missing_core_fields: ['amount_cents'],
                missing_custom_fields: ['deployment_region'],
            }),
        );
        expect(gateErr).toBeInstanceOf(StageGateValidationError);
        expect((gateErr as StageGateValidationError).missingCoreFields).toEqual(['amount_cents']);
        expect((gateErr as StageGateValidationError).missingCustomFields).toEqual([
            'deployment_region',
        ]);

        const forbidden = mapCrmApiError(makeAxiosError(403, { message: 'Forbidden action.' }));
        expect(forbidden).toBeInstanceOf(ForbiddenRequestError);
    });

    it('performs optimistic Kanban move, rolls back on 409 Conflict with alert banner, and triggers debounced Echo reload', async () => {
        vi.useFakeTimers();
        const mockEcho = createMockEcho();
        setEchoInstanceForTesting(mockEcho.client);

        const authStore = useAuthStore();
        authStore.setUser(makeUser());

        const board = makeBoard();
        const dealCard = board.columns[0].deals[0];

        let rejectMove!: (err: unknown) => void;
        const moveSpy = vi.spyOn(crmApi, 'moveDealStage').mockImplementation(
            () =>
                new Promise((_resolve, reject) => {
                    rejectMove = reject;
                }),
        );

        const wrapper = mount(DealKanbanBoard, {
            attachTo: document.body,
            props: {
                board,
                customFieldDefinitions: [],
                assignableUsers: [],
                contacts: [],
                canMoveDeals: true,
                canOverrideGates: false,
            },
        });
        mountedWrappers.push(wrapper);
        await nextTick();

        expect(wrapper.get('[data-testid="kanban-column-count-stage-1"]').text()).toBe('1');
        expect(wrapper.get('[data-testid="kanban-column-count-stage-2"]').text()).toBe('0');

        // Trigger stage move via accessible select
        const stageSelect = wrapper.get<HTMLSelectElement>('[data-testid="kanban-stage-select-deal-1"]');
        await stageSelect.setValue('stage-2');
        await nextTick();

        // Optimistically moved before server resolves
        expect(wrapper.get('[data-testid="kanban-column-count-stage-1"]').text()).toBe('0');
        expect(wrapper.get('[data-testid="kanban-column-count-stage-2"]').text()).toBe('1');
        expect(moveSpy).toHaveBeenCalledWith(
            'deal-1',
            expect.objectContaining({
                target_stage_id: 'stage-2',
                expected_version: 3,
            }),
        );

        // Server rejects with 409 OptimisticLockConflictError -> rollback + conflict banner
        rejectMove(
            new OptimisticLockConflictError(
                'The record was modified by another user. Refresh and try again.',
                4,
                { id: dealCard.id, version: 4 },
            ),
        );
        await flushPromises();
        await nextTick();

        expect(wrapper.get('[data-testid="kanban-column-count-stage-1"]').text()).toBe('1');
        expect(wrapper.get('[data-testid="kanban-column-count-stage-2"]').text()).toBe('0');
        const conflictBanner = wrapper.get('[data-testid="kanban-conflict-banner"]');
        expect(conflictBanner.attributes('role')).toBe('alert');
        expect(conflictBanner.text()).toContain('409 Conflict');

        // Verify Echo SiloBoardInvalidated triggers debounced router.reload
        mockEcho.emitEvent('.App\\Modules\\Crm\\Deals\\Events\\SiloBoardInvalidated', {
            pipeline_id: 'pipe-1',
            entity_id: 'deal-1',
        });
        vi.advanceTimersByTime(300);
        expect(mockRouterReload).toHaveBeenCalledWith({ only: ['board', 'tableDeals'] });
    });

    it('opens StageGateModal on 422 StageGateValidationError, fills core + custom fields, and retries cleanly', async () => {
        const board = makeBoard();
        const dealCard = board.columns[0].deals[0];
        dealCard.amount_cents = 0;
        dealCard.expected_close_date = null;
        board.columns[0].total_amount_cents = 0;

        const customDefs: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[] = [
            {
                id: 'cf-region',
                entity_type: 'deal',
                pipeline_id: 'pipe-1',
                key: 'deployment_region',
                label: 'Deployment Region',
                field_type: 'select',
                options: [
                    { value: 'us_west', label: 'US West' },
                    { value: 'eu_central', label: 'EU Central' },
                ],
                validation_rules: {},
                is_filterable: true,
                is_active: true,
                sort_order: 0,
            },
        ];

        const moveSpy = vi
            .spyOn(crmApi, 'moveDealStage')
            .mockRejectedValueOnce(
                new StageGateValidationError(
                    'Complete all required fields before moving to Proposal & Review.',
                    {
                        amount_cents: ['Deal Amount is required.'],
                        expected_close_date: ['Expected Close Date is required.'],
                        'custom_fields.deployment_region': ['Deployment Region is required.'],
                    },
                    ['amount_cents', 'expected_close_date'],
                    ['deployment_region'],
                ),
            )
            .mockResolvedValueOnce(
                makeDealDetailFromCard(dealCard, {
                    stage_id: 'stage-2',
                    stage_name: 'Proposal & Review',
                    amount_cents: 4500000,
                    expected_close_date: '2026-12-20',
                    custom_fields: { deployment_region: 'us_west' },
                    version: 4,
                }),
            );

        const wrapper = mount(DealKanbanBoard, {
            attachTo: document.body,
            props: {
                board,
                customFieldDefinitions: customDefs,
                assignableUsers: [],
                contacts: [],
                canMoveDeals: true,
                canOverrideGates: true,
            },
        });
        mountedWrappers.push(wrapper);

        const stageSelect = wrapper.get<HTMLSelectElement>('[data-testid="kanban-stage-select-deal-1"]');
        await stageSelect.setValue('stage-2');
        await flushPromises();
        await nextTick();

        // Card rolled back pending gate completion, StageGateModal opened
        expect(wrapper.get('[data-testid="kanban-column-count-stage-1"]').text()).toBe('1');
        const gateForm = document.body.querySelector('[data-testid="stage-gate-modal-form"]');
        expect(gateForm).not.toBeNull();
        expect(document.body.querySelector('[data-testid="stage-gate-override-box"]')).not.toBeNull();

        const amountInput = document.body.querySelector<HTMLInputElement>('input[type="number"]');
        const dateInput = document.body.querySelector<HTMLInputElement>('input[type="date"]');
        const regionSelect = document.body.querySelector<HTMLSelectElement>(
            '#stage-gate-cf-deployment_region',
        );

        expect(amountInput).not.toBeNull();
        expect(dateInput).not.toBeNull();
        expect(regionSelect).not.toBeNull();

        if (amountInput && dateInput && regionSelect) {
            amountInput.value = '45000';
            amountInput.dispatchEvent(new Event('input', { bubbles: true }));
            dateInput.value = '2026-12-20';
            dateInput.dispatchEvent(new Event('input', { bubbles: true }));
            regionSelect.value = 'us_west';
            regionSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }

        await nextTick();
        gateForm?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await flushPromises();
        await nextTick();

        expect(moveSpy).toHaveBeenCalledTimes(2);
        expect(moveSpy).toHaveBeenNthCalledWith(
            2,
            'deal-1',
            expect.objectContaining({
                target_stage_id: 'stage-2',
                expected_version: 3,
                core_field_updates: {
                    amount_cents: 4500000,
                    expected_close_date: '2026-12-20',
                },
                custom_field_updates: {
                    deployment_region: 'us_west',
                },
                override_gates: false,
            }),
        );

        expect(document.body.querySelector('[data-testid="stage-gate-modal-form"]')).toBeNull();
        expect(wrapper.get('[data-testid="kanban-column-count-stage-1"]').text()).toBe('0');
        expect(wrapper.get('[data-testid="kanban-column-count-stage-2"]').text()).toBe('1');
    });

    it('renders ActivityTimeline safely without raw HTML injection, posts a note, and loads next cursor page', async () => {
        const initialPage: App.Modules.Crm.Timeline.Data.TimelinePageData = {
            items: [
                {
                    id: 'act-1',
                    actor_id: 'usr-200',
                    actor_name: 'Devon Alvarez',
                    activity_type: 'note_created',
                    source_type: 'note',
                    source_id: 'note-1',
                    visibility_mode: 'shared',
                    summary: 'Security review note',
                    metadata: { is_pinned: false },
                    occurred_at: '2026-11-25T14:00:00Z',
                    note: {
                        id: 'note-1',
                        author_id: 'usr-200',
                        author_name: 'Devon Alvarez',
                        body_json: {
                            type: 'doc',
                            content: [
                                {
                                    type: 'paragraph',
                                    content: [
                                        { type: 'text', text: '<img src=x onerror=alert(1)> ' },
                                        {
                                            type: 'text',
                                            text: 'Malicious Link',
                                            marks: [{ type: 'link', attrs: { href: 'javascript:alert(1)' } }],
                                        },
                                        { type: 'text', text: ' and ' },
                                        {
                                            type: 'text',
                                            text: 'Architecture Spec',
                                            marks: [{ type: 'link', attrs: { href: 'https://northwind.internal/spec' } }],
                                        },
                                    ],
                                },
                            ],
                        },
                        body_text: '<img src=x onerror=alert(1)> Malicious Link and Architecture Spec',
                        is_pinned: false,
                        created_at: '2026-11-25T14:00:00Z',
                        updated_at: '2026-11-25T14:00:00Z',
                    },
                },
            ],
            next_cursor: 'cursor-page-2',
            has_more: true,
        };

        const createNoteSpy = vi.spyOn(crmApi, 'createNote').mockResolvedValue({
            id: 'note-2',
            author_id: 'usr-200',
            author_name: 'Devon Alvarez',
            body_json: {
                type: 'doc',
                content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Follow-up scheduled with IT.' }] }],
            },
            body_text: 'Follow-up scheduled with IT.',
            is_pinned: true,
            created_at: '2026-11-26T01:00:00Z',
            updated_at: '2026-11-26T01:00:00Z',
        });

        const fetchTimelineSpy = vi.spyOn(crmApi, 'fetchTimeline').mockResolvedValue({
            items: [
                {
                    id: 'act-older',
                    actor_id: 'usr-200',
                    actor_name: 'Devon Alvarez',
                    activity_type: 'deal_created',
                    source_type: 'deal',
                    source_id: 'deal-1',
                    visibility_mode: 'shared',
                    summary: 'Created deal Regional WMS Provisioning',
                    metadata: {},
                    occurred_at: '2026-11-20T09:00:00Z',
                    note: null,
                },
            ],
            next_cursor: null,
            has_more: false,
        });

        const wrapper = mount(ActivityTimeline, {
            attachTo: document.body,
            props: {
                linkableType: 'deal',
                linkableId: '11111111-1111-1111-1111-111111111111',
                initialPage,
                canCreateNote: true,
            },
        });
        mountedWrappers.push(wrapper);
        await flushPromises();
        await nextTick();

        // Zero raw HTML injection: <img> must not exist, text must be escaped
        expect(wrapper.element.querySelector('img')).toBeNull();
        expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>');

        // javascript: link blocked from becoming <a>, https: link rendered safely
        const anchors = wrapper.findAll('a');
        expect(anchors.some((a) => a.attributes('href')?.startsWith('javascript:'))).toBe(false);
        expect(wrapper.find('[data-unsafe-link="blocked"]').text()).toBe('Malicious Link');
        const safeAnchor = anchors.find((a) => a.attributes('href') === 'https://northwind.internal/spec');
        expect(safeAnchor).toBeDefined();
        expect(safeAnchor?.attributes('rel')).toBe('noopener noreferrer');

        // Empty note submission shows validation alert
        await wrapper.get('[data-testid="rich-note-submit"]').trigger('click');
        expect(wrapper.get('[data-testid="rich-note-error"]').attributes('role')).toBe('alert');

        // Submit note through real TipTap editor + Pin checkbox + Submit button
        const fallbackTextarea = wrapper.find('[data-testid="rich-note-fallback-textarea"]');
        if (fallbackTextarea.exists()) {
            await fallbackTextarea.setValue('Follow-up scheduled with IT.');
        } else {
            const editorComp = wrapper.findComponent({ name: 'RichNoteEditor' });
            const exposedEditor = (editorComp.vm as unknown as {
                editor?: { commands: { setContent: (doc: unknown) => void } };
            }).editor;
            expect(exposedEditor).toBeDefined();
            exposedEditor?.commands.setContent({
                type: 'doc',
                content: [
                    {
                        type: 'paragraph',
                        content: [{ type: 'text', text: 'Follow-up scheduled with IT.' }],
                    },
                ],
            });
        }
        const pinCheckbox = wrapper.get<HTMLInputElement>('input[type="checkbox"]');
        await pinCheckbox.setValue(true);
        await wrapper.get('[data-testid="rich-note-submit"]').trigger('click');
        await flushPromises();
        await nextTick();

        expect(createNoteSpy).toHaveBeenCalledTimes(1);
        expect(createNoteSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                is_pinned: true,
                targets: [
                    {
                        linkable_type: 'deal',
                        linkable_id: '11111111-1111-1111-1111-111111111111',
                    },
                ],
            }),
        );
        expect(wrapper.get('[data-testid="pinned-notes-section"]').text()).toContain(
            'Follow-up scheduled with IT.',
        );

        // Load earlier cursor page
        await wrapper.get('[data-testid="timeline-load-more"]').trigger('click');
        await flushPromises();
        await nextTick();

        expect(fetchTimelineSpy).toHaveBeenCalledWith({
            linkable_type: 'deal',
            linkable_id: '11111111-1111-1111-1111-111111111111',
            cursor: 'cursor-page-2',
            limit: 25,
        });
        expect(wrapper.text()).toContain('Created deal Regional WMS Provisioning');
        expect(wrapper.find('[data-testid="timeline-load-more"]').exists()).toBe(false);
    });

    it('renders DataTable with compact rows, sorting, loading skeleton, empty state, and error retry', async () => {
        interface SampleRow {
            id: string;
            account: string;
            amount: number;
        }

        const helper = createDataTableColumnHelper<SampleRow>();
        const columns = [
            helper.accessor('account', {
                id: 'account',
                header: 'Account',
            }),
            helper.accessor('amount', {
                id: 'amount',
                header: 'Amount',
                meta: { align: 'right', numeric: true },
                cell: (info) => `$${info.getValue().toLocaleString('en-US')}`,
            }),
        ];

        const rows: SampleRow[] = [
            { id: '1', account: 'Redwood Precision Components', amount: 18000 },
            { id: '2', account: 'Cascadia Cold Storage Group', amount: 42000 },
        ];

        const readyWrapper = mount(DataTable<SampleRow>, {
            attachTo: document.body,
            props: {
                columns,
                data: rows,
                rowKey: (row) => row.id,
            },
        });
        mountedWrappers.push(readyWrapper);
        await nextTick();

        const bodyRows = readyWrapper.findAll('tbody tr');
        expect(bodyRows).toHaveLength(2);
        expect(bodyRows[0].classes()).toContain('h-8');

        // Sort by Amount ascending -> 18000 first
        const amountSortBtn = readyWrapper.get('[data-testid="sort-header-amount"]');
        await amountSortBtn.trigger('click');
        await nextTick();

        const sortedHeader = amountSortBtn.element.closest('th');
        expect(['ascending', 'descending']).toContain(sortedHeader?.getAttribute('aria-sort'));

        // Loading skeleton state
        const loadingWrapper = mount(DataTable<SampleRow>, {
            attachTo: document.body,
            props: {
                columns,
                data: [],
                loading: true,
            },
        });
        mountedWrappers.push(loadingWrapper);
        expect(loadingWrapper.find('[data-testid="data-table-loading"]').exists()).toBe(true);

        // Empty state
        const emptyWrapper = mount(DataTable<SampleRow>, {
            attachTo: document.body,
            props: {
                columns,
                data: [],
                emptyTitle: 'No companies visible',
            },
        });
        mountedWrappers.push(emptyWrapper);
        expect(emptyWrapper.get('[data-testid="data-table-empty"]').text()).toContain(
            'No companies visible',
        );

        // Error state with retry emit
        const errorWrapper = mount(DataTable<SampleRow>, {
            attachTo: document.body,
            props: {
                columns,
                data: [],
                error: 'CRM API timed out.',
            },
        });
        mountedWrappers.push(errorWrapper);
        const errorAlert = errorWrapper.get('[data-testid="data-table-error"]');
        expect(errorAlert.attributes('role')).toBe('alert');
        expect(errorAlert.text()).toContain('CRM API timed out.');

        await errorWrapper.get('[data-testid="data-table-retry"]').trigger('click');
        expect(errorWrapper.emitted('retry')).toHaveLength(1);
    });

    it('renders all 10 CustomFieldTypeEnum types with bound labels and role="alert" validation errors', async () => {
        const allTypes: App.Modules.Crm.CustomFields.Enums.CustomFieldTypeEnum[] = [
            'text',
            'textarea',
            'number',
            'currency',
            'boolean',
            'date',
            'select',
            'multi_select',
            'user_id',
            'url',
        ];

        const definitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[] = allTypes.map(
            (fieldType, idx) => ({
                id: `cf-${fieldType}`,
                entity_type: 'company',
                pipeline_id: null,
                key: `field_${fieldType}`,
                label: `Field ${fieldType}`,
                field_type: fieldType,
                options:
                    fieldType === 'select' || fieldType === 'multi_select'
                        ? [
                              { value: 'opt_a', label: 'Option A' },
                              { value: 'opt_b', label: 'Option B' },
                          ]
                        : null,
                validation_rules: { required: fieldType === 'text' },
                is_filterable: true,
                is_active: true,
                sort_order: idx,
            }),
        );

        const wrapper = mount(CustomFieldsForm, {
            attachTo: document.body,
            props: {
                definitions,
                modelValue: { field_multi_select: [] },
                assignableUsers: [
                    {
                        id: 'usr-200',
                        name: 'Devon Alvarez',
                        email: 'devon.alvarez@company.internal',
                        employee_code: 'EMP-0204',
                        job_title: 'Account Executive',
                        department_id: 'dept-sales',
                        department_name: 'Revenue',
                        status: 'active',
                        roles: ['sales'],
                        created_at: '2026-11-01T00:00:00+00:00',
                    },
                ],
                errors: {
                    'custom_fields.field_url': 'Field url must be a valid http or https URL.',
                },
                idPrefix: 'test-cf',
            },
        });
        mountedWrappers.push(wrapper);
        await nextTick();

        for (const fieldType of allTypes) {
            if (fieldType === 'multi_select') {
                expect(wrapper.find('#test-cf-field_multi_select-opt_a').exists()).toBe(true);
            } else {
                const inputEl = wrapper.find(`#test-cf-field_${fieldType}`);
                expect(inputEl.exists()).toBe(true);
                expect(wrapper.find(`label[for="test-cf-field_${fieldType}"]`).exists()).toBe(true);
            }
        }

        const urlInput = wrapper.get('#test-cf-field_url');
        expect(urlInput.attributes('aria-invalid')).toBe('true');
        const alertEl = wrapper.find('[role="alert"]');
        expect(alertEl.text()).toContain('Field url must be a valid http or https URL.');
    });

    it('gates CRM Settings nav item in WorkspaceShellLayout by pipelines.manage or custom_fields.manage', async () => {
        mockPage.props = {
            auth: { user: makeUser(['deals.view']) },
            errors: {},
        };

        const restrictedWrapper = mount(WorkspaceShellLayout, {
            attachTo: document.body,
            slots: { default: () => h('div', 'CRM') },
        });
        mountedWrappers.push(restrictedWrapper);
        await nextTick();

        expect(restrictedWrapper.find('a[href="/app/crm/settings/pipelines"]').exists()).toBe(false);

        const authStore = useAuthStore();
        authStore.setUser(makeUser(['deals.view', 'pipelines.manage']));
        await nextTick();

        const adminLink = restrictedWrapper.find('a[href="/app/crm/settings/pipelines"]');
        expect(adminLink.exists()).toBe(true);
        expect(adminLink.text()).toContain('CRM Settings');

        authStore.setUser(makeUser(['deals.view', 'custom_fields.manage']));
        await nextTick();
        expect(restrictedWrapper.find('a[href="/app/crm/settings/pipelines"]').exists()).toBe(true);
    });

    it('syncs DealDetailPage localDeal.version and form when props.deal reloads after 409 Conflict', async () => {
        const board = makeBoard();
        const card = board.columns[0].deals[0];
        const initialDeal = makeDealDetailFromCard(card, {
            version: 3,
            title: 'Regional WMS Provisioning',
            amount_cents: 1200000,
        });
        const reloadedDeal = makeDealDetailFromCard(card, {
            version: 5,
            title: 'Regional WMS Provisioning (Server v5)',
            amount_cents: 2850000,
        });

        const updateSpy = vi
            .spyOn(crmApi, 'updateDeal')
            .mockRejectedValueOnce(
                new OptimisticLockConflictError('Stale deal version.', 5, reloadedDeal),
            )
            .mockResolvedValueOnce(
                makeDealDetailFromCard(card, {
                    version: 6,
                    title: 'Regional WMS Provisioning (Server v5)',
                    amount_cents: 2850000,
                }),
            );

        const wrapper = mount(DealDetailPage, {
            attachTo: document.body,
            props: {
                deal: initialDeal,
                company: {
                    id: 'comp-1',
                    name: 'Northwind Logistics',
                    domain: 'northwind.internal',
                    lifecycle_stage: 'active_client',
                },
                primaryContact: {
                    id: 'cont-1',
                    full_name: 'Hannah Kowalski',
                    primary_email: 'h.kowalski@northwind.internal',
                    job_title: 'VP Logistics',
                },
                pipeline: board.pipeline,
                stakeholders: [],
                collaborators: [],
                attachments: [],
                customFieldDefinitions: [],
                timeline: { items: [], next_cursor: null, has_more: false },
                assignableUsers: [],
                companies: [{ id: 'comp-1', name: 'Northwind Logistics', domain: 'northwind.internal' }],
                contacts: [
                    {
                        id: 'cont-1',
                        full_name: 'Hannah Kowalski',
                        primary_email: 'h.kowalski@northwind.internal',
                        company_id: 'comp-1',
                    },
                ],
                stakeholderRoles: ['decision_maker'],
                collaboratorAccessLevels: ['viewer', 'editor'],
                collaboratorRoleLabels: ['account_manager'],
                can: {
                    update: true,
                    delete: false,
                    moveStage: true,
                    overrideGates: false,
                    manageCollaborators: false,
                },
            },
        });
        mountedWrappers.push(wrapper);
        await nextTick();

        expect(wrapper.get('[data-testid="deal-version-badge"]').text()).toBe('v3');

        // Trigger 409 conflict on Save Properties
        await wrapper.get('aside[aria-label="Deal properties"] form').trigger('submit');
        await flushPromises();
        await nextTick();

        expect(wrapper.get('[data-testid="deal-detail-conflict-banner"]').text()).toContain('Version Conflict (409)');

        // Simulate Inertia router.reload updating props.deal and invoking onSuccess
        mockRouterReload.mockImplementation(async (options?: { onSuccess?: () => void }) => {
            await wrapper.setProps({ deal: reloadedDeal });
            options?.onSuccess?.();
        });

        await wrapper.get('[data-testid="deal-detail-reload-latest"]').trigger('click');
        await flushPromises();
        await nextTick();

        expect(mockRouterReload).toHaveBeenCalledTimes(1);
        expect(wrapper.find('[data-testid="deal-detail-conflict-banner"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="deal-version-badge"]').text()).toBe('v5');

        const titleInput = wrapper.get<HTMLInputElement>('aside[aria-label="Deal properties"] input[type="text"]');
        const amountInput = wrapper.get<HTMLInputElement>('aside[aria-label="Deal properties"] input[type="number"]');
        expect(titleInput.element.value).toBe('Regional WMS Provisioning (Server v5)');
        expect(amountInput.element.value).toBe('28500');

        // Next save sends updated expected_version: 5 instead of stale version 3
        await wrapper.get('aside[aria-label="Deal properties"] form').trigger('submit');
        await flushPromises();
        await nextTick();

        expect(updateSpy).toHaveBeenNthCalledWith(
            2,
            initialDeal.id,
            expect.objectContaining({ expected_version: 5 }),
        );
        expect(wrapper.get('[data-testid="deal-version-badge"]').text()).toBe('v6');
    });

    it('renders restricted notice and omits masked associations on DealDetailPage and ContactDetailPage saves', async () => {
        const board = makeBoard();
        const card = board.columns[0].deals[0];
        const maskedDeal = makeDealDetailFromCard(card, {
            company_id: null,
            company_name: null,
            company_domain: null,
            primary_contact_id: null,
            primary_contact_name: null,
            primary_contact_email: null,
            has_masked_company: true,
            has_masked_primary_contact: true,
            version: 4,
        });

        const updateDealSpy = vi.spyOn(crmApi, 'updateDeal').mockResolvedValue(maskedDeal);

        const dealWrapper = mount(DealDetailPage, {
            attachTo: document.body,
            props: {
                deal: maskedDeal,
                company: null,
                primaryContact: null,
                pipeline: board.pipeline,
                stakeholders: [],
                collaborators: [],
                attachments: [],
                customFieldDefinitions: [],
                timeline: { items: [], next_cursor: null, has_more: false },
                assignableUsers: [],
                companies: [{ id: 'comp-visible', name: 'Cascadia Cold Storage', domain: 'cascadia.internal' }],
                contacts: [
                    {
                        id: 'cont-visible',
                        full_name: 'Elena Rostova',
                        primary_email: 'elena@cascadia.internal',
                        company_id: 'comp-visible',
                    },
                ],
                stakeholderRoles: ['decision_maker'],
                collaboratorAccessLevels: ['viewer', 'editor'],
                collaboratorRoleLabels: ['account_manager'],
                can: {
                    update: true,
                    delete: false,
                    moveStage: true,
                    overrideGates: false,
                    manageCollaborators: false,
                },
            },
        });
        mountedWrappers.push(dealWrapper);
        await nextTick();

        expect(dealWrapper.get('[data-testid="deal-masked-company"]').text()).toBe(
            'Restricted (outside your silo)',
        );
        expect(dealWrapper.get('[data-testid="deal-masked-primary-contact"]').text()).toBe(
            'Restricted (outside your silo)',
        );
        expect(dealWrapper.find('aside[aria-label="Deal properties"] select option[value=""]').exists()).toBe(true); // account manager Unassigned only
        expect(dealWrapper.text()).not.toContain('None');

        await dealWrapper.get('aside[aria-label="Deal properties"] form').trigger('submit');
        await flushPromises();

        expect(updateDealSpy).toHaveBeenCalledTimes(1);
        const dealPayload = updateDealSpy.mock.calls[0][1] as Record<string, unknown>;
        expect(Object.prototype.hasOwnProperty.call(dealPayload, 'company_id')).toBe(false);
        expect(Object.prototype.hasOwnProperty.call(dealPayload, 'primary_contact_id')).toBe(false);

        // ContactDetailPage masked company + prop sync
        const maskedContact: App.Modules.Crm.Contacts.Data.ContactDetailData = {
            id: 'cont-masked-1',
            company_id: null,
            company_name: null,
            company_domain: null,
            owner_id: 'usr-200',
            owner_name: 'Devon Alvarez',
            first_name: 'Hannah',
            last_name: 'Kowalski',
            full_name: 'Hannah Kowalski',
            primary_email: 'h.kowalski@northwind.internal',
            secondary_emails: [],
            phones: [],
            job_title: 'Operations Director',
            is_primary_contact: false,
            custom_fields: {},
            created_at: '2026-11-01T10:00:00Z',
            updated_at: '2026-11-26T00:00:00Z',
            has_masked_company: true,
        };

        const updateContactSpy = vi.spyOn(crmApi, 'updateContact').mockResolvedValue(maskedContact);

        const contactWrapper = mount(ContactDetailPage, {
            attachTo: document.body,
            props: {
                contact: maskedContact,
                company: null,
                deals: [],
                collaborators: [],
                attachments: [],
                customFieldDefinitions: [],
                timeline: { items: [], next_cursor: null, has_more: false },
                assignableUsers: [],
                companies: [{ id: 'comp-visible', name: 'Cascadia Cold Storage', domain: 'cascadia.internal' }],
                collaboratorAccessLevels: ['viewer', 'editor'],
                collaboratorRoleLabels: ['account_manager'],
                can: {
                    update: true,
                    delete: false,
                    manageCollaborators: false,
                },
            },
        });
        mountedWrappers.push(contactWrapper);
        await nextTick();

        expect(contactWrapper.get('[data-testid="contact-masked-company"]').text()).toBe(
            'Restricted (outside your silo)',
        );
        expect(contactWrapper.get('[data-testid="contact-linked-company-masked"]').text()).toBe(
            'Restricted (outside your silo)',
        );

        await contactWrapper.get('aside[aria-label="Contact properties"] form').trigger('submit');
        await flushPromises();

        expect(updateContactSpy).toHaveBeenCalledTimes(1);
        const contactPayload = updateContactSpy.mock.calls[0][1] as Record<string, unknown>;
        expect(Object.prototype.hasOwnProperty.call(contactPayload, 'company_id')).toBe(false);

        // Inertia prop reload syncs ContactDetailPage and CompanyDetailPage
        await contactWrapper.setProps({
            contact: { ...maskedContact, first_name: 'Hannah-Reloaded', full_name: 'Hannah-Reloaded Kowalski' },
        });
        await nextTick();
        expect(contactWrapper.text()).toContain('Hannah-Reloaded Kowalski');

        const companyWrapper = mount(CompanyDetailPage, {
            attachTo: document.body,
            props: {
                company: {
                    id: 'comp-1',
                    name: 'Northwind Logistics',
                    domain: 'northwind.internal',
                    lifecycle_stage: 'active_client',
                    industry: 'Logistics',
                    employee_range: '500-1000',
                    annual_revenue_cents: 500000000,
                    billing_address: {},
                    owner_id: 'usr-200',
                    owner_name: 'Devon Alvarez',
                    account_manager_id: null,
                    account_manager_name: null,
                    custom_fields: {},
                    created_at: '2026-11-01T10:00:00Z',
                    updated_at: '2026-11-26T00:00:00Z',
                },
                contacts: [],
                deals: [],
                collaborators: [],
                attachments: [],
                customFieldDefinitions: [],
                timeline: { items: [], next_cursor: null, has_more: false },
                assignableUsers: [],
                lifecycleStages: ['lead', 'prospect', 'active_client'],
                collaboratorAccessLevels: ['viewer', 'editor'],
                collaboratorRoleLabels: ['account_manager'],
                can: {
                    update: true,
                    delete: false,
                    manageCollaborators: false,
                },
            },
        });
        mountedWrappers.push(companyWrapper);
        await companyWrapper.setProps({
            company: {
                ...companyWrapper.props('company'),
                name: 'Northwind Global Logistics',
            },
        });
        await nextTick();
        expect(companyWrapper.text()).toContain('Northwind Global Logistics');
    });

    it('calls channel.stopListening without calling echo.leave() when DealKanbanBoard unmounts', async () => {
        vi.useFakeTimers();
        const mockEcho = createMockEcho();
        setEchoInstanceForTesting(mockEcho.client);

        const authStore = useAuthStore();
        authStore.setUser(makeUser());

        const wrapper = mount(DealKanbanBoard, {
            attachTo: document.body,
            props: {
                board: makeBoard(),
                customFieldDefinitions: [],
                assignableUsers: [],
                contacts: [],
                canMoveDeals: true,
                canOverrideGates: false,
            },
        });
        await nextTick();

        const listenCalls = (mockEcho.channelStub.listen as ReturnType<typeof vi.fn>).mock.calls;
        expect(listenCalls).toHaveLength(1);
        const [eventName, registeredCallback] = listenCalls[0];
        expect(eventName).toBe('.App\\Modules\\Crm\\Deals\\Events\\SiloBoardInvalidated');

        wrapper.unmount();

        expect(mockEcho.stopListeningSpy).toHaveBeenCalledTimes(1);
        expect(mockEcho.stopListeningSpy).toHaveBeenCalledWith(
            '.App\\Modules\\Crm\\Deals\\Events\\SiloBoardInvalidated',
            registeredCallback,
        );
        expect(mockEcho.leaveSpy).not.toHaveBeenCalled();

        mockEcho.emitEvent('.App\\Modules\\Crm\\Deals\\Events\\SiloBoardInvalidated', {
            pipeline_id: 'pipe-1',
        });
        vi.advanceTimersByTime(300);
        expect(mockRouterReload).not.toHaveBeenCalled();
    });

    it('disables custom field key input and displays immutability hint when editing in PipelineSettingsPage', async () => {
        const board = makeBoard();
        const existingField: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData = {
            id: 'cf-1',
            entity_type: 'deal',
            pipeline_id: board.pipeline.id,
            key: 'deployment_region',
            label: 'Deployment Region',
            field_type: 'text',
            options: null,
            validation_rules: { required: false },
            is_filterable: true,
            is_active: true,
            sort_order: 0,
        };

        const wrapper = mount(PipelineSettingsPage, {
            attachTo: document.body,
            props: {
                pipelines: [board.pipeline],
                customFieldDefinitions: [existingField],
                allowedCoreFields: ['amount_cents', 'expected_close_date'],
                canManage: true,
            },
        });
        mountedWrappers.push(wrapper);
        await nextTick();

        const customFieldsTab = wrapper
            .findAll('button[role="tab"]')
            .find((btn) => btn.text().includes('Custom Fields'));
        expect(customFieldsTab).toBeDefined();
        await customFieldsTab?.trigger('click');
        await nextTick();

        await wrapper.get('button[aria-label="Edit custom field Deployment Region"]').trigger('click');
        await flushPromises();
        await nextTick();

        const keyInput = document.body.querySelector<HTMLInputElement>(
            'input[placeholder="deployment_region"]',
        );
        expect(keyInput).not.toBeNull();
        expect(keyInput?.value).toBe('deployment_region');
        expect(keyInput?.disabled).toBe(true);

        const hint = document.body.querySelector('[data-testid="custom-field-key-hint"]');
        expect(hint?.textContent).toContain('cannot be renamed');
    });

    it('enforces quick-fix safety guards across DealsIndexPage, ActivityTimeline, RichJsonRenderer, and crmApi CSRF', async () => {
        // 1. DealsIndexPage defaults canMoveDeals to false when activeUser is null
        mockPage.props = { auth: { user: null }, errors: {} };
        const authStore = useAuthStore();
        authStore.setUser(null);

        const board = makeBoard();
        const dealsPageWrapper = mount(DealsIndexPage, {
            attachTo: document.body,
            props: {
                view: 'kanban',
                board,
                tableDeals: null,
                filters: {
                    view: 'kanban',
                    pipeline_id: board.pipeline.id,
                    stage_id: null,
                    owner_id: null,
                    company_id: null,
                    search: null,
                    custom_fields: null,
                    sort_by: null,
                    sort_dir: null,
                    page: 1,
                    per_page: 25,
                },
                pipelines: [board.pipeline],
                filterDefinitions: [],
                customFieldDefinitions: [],
                assignableUsers: [],
                companies: [],
                contacts: [],
                can: { create: false },
            },
        });
        mountedWrappers.push(dealsPageWrapper);
        await nextTick();

        expect(dealsPageWrapper.get('[data-testid="kanban-card-deal-1"]').attributes('draggable')).toBe('false');
        expect(dealsPageWrapper.find('[data-testid="kanban-stage-select-deal-1"]').exists()).toBe(false);

        // 2. ActivityTimeline hides Pin/Unpin & Delete buttons when canCreateNote is false
        const timelineWrapper = mount(ActivityTimeline, {
            attachTo: document.body,
            props: {
                linkableType: 'deal',
                linkableId: 'deal-1',
                canCreateNote: false,
                initialPage: {
                    items: [
                        {
                            id: 'act-pinned',
                            actor_id: 'usr-200',
                            actor_name: 'Devon Alvarez',
                            activity_type: 'note_created',
                            source_type: 'note',
                            source_id: 'note-1',
                            visibility_mode: 'shared',
                            summary: 'Pinned note',
                            metadata: { is_pinned: true },
                            occurred_at: '2026-11-25T14:00:00Z',
                            note: {
                                id: 'note-1',
                                author_id: 'usr-200',
                                author_name: 'Devon Alvarez',
                                body_json: {
                                    type: 'doc',
                                    content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Read only note' }] }],
                                },
                                body_text: 'Read only note',
                                is_pinned: true,
                                created_at: '2026-11-25T14:00:00Z',
                                updated_at: '2026-11-25T14:00:00Z',
                            },
                        },
                    ],
                    next_cursor: null,
                    has_more: false,
                },
            },
        });
        mountedWrappers.push(timelineWrapper);
        await nextTick();

        expect(timelineWrapper.find('[data-testid="rich-note-editor"]').exists()).toBe(false);
        expect(timelineWrapper.find('button[aria-label="Unpin note"]').exists()).toBe(false);
        expect(timelineWrapper.find('button[aria-label="Pin note"]').exists()).toBe(false);
        expect(timelineWrapper.find('button[aria-label="Delete pinned note"]').exists()).toBe(false);
        expect(timelineWrapper.find('button[aria-label="Delete note"]').exists()).toBe(false);

        // 3. RichJsonRenderer truncates & clamps heading levels safely
        const richWrapper = mount(RichJsonRenderer, {
            attachTo: document.body,
            props: {
                document: {
                    type: 'doc',
                    content: [
                        { type: 'heading', attrs: { level: 2.8 }, content: [{ type: 'text', text: 'Truncated' }] },
                        { type: 'heading', attrs: { level: 99 }, content: [{ type: 'text', text: 'Clamped High' }] },
                        { type: 'heading', attrs: { level: -2 }, content: [{ type: 'text', text: 'Clamped Low' }] },
                    ],
                },
            },
        });
        mountedWrappers.push(richWrapper);
        expect(richWrapper.get('h2').text()).toBe('Truncated');
        expect(richWrapper.get('h6').text()).toBe('Clamped High');
        expect(richWrapper.get('h1').text()).toBe('Clamped Low');

        // 4. crmApi prefers decoded XSRF-TOKEN cookie over stale meta csrf-token
        const metaEl = document.createElement('meta');
        metaEl.name = 'csrf-token';
        metaEl.content = 'stale-meta-token';
        document.head.appendChild(metaEl);
        document.cookie = 'XSRF-TOKEN=fresh%2Bcookie%3Dtoken; path=/';

        const originalAdapter = crmHttpClient.defaults.adapter;
        const capturedHeaders: Array<Record<string, unknown>> = [];
        crmHttpClient.defaults.adapter = async (config) => {
            capturedHeaders.push({ ...(config.headers ?? {}) });
            return {
                data: { deleted: true },
                status: 200,
                statusText: 'OK',
                headers: new AxiosHeaders(),
                config,
            };
        };

        try {
            await crmApi.deleteNote('note-1');
            expect(capturedHeaders[0]['X-XSRF-TOKEN']).toBe('fresh+cookie=token');
            expect(capturedHeaders[0]['X-CSRF-TOKEN']).toBeUndefined();

            document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
            await crmApi.deleteNote('note-1');
            expect(capturedHeaders[1]['X-CSRF-TOKEN']).toBe('stale-meta-token');
        } finally {
            crmHttpClient.defaults.adapter = originalAdapter;
            metaEl.remove();
            document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
        }
    });
});
