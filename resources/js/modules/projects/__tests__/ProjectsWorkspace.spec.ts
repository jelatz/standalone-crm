import { h, nextTick } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ConnectionStatus } from 'laravel-echo';
import {
    resetEcho,
    setEchoInstanceForTesting,
    type RealtimeChannelLike,
    type RealtimeEchoClient,
    type RealtimePresenceChannelLike,
} from '@/echo';
import { useAuthStore } from '@/stores/auth.store';
import { HANDOFF_STATUS_CHANGED_EVENT, useRealtimeStore } from '@/stores/realtime.store';
import { useUnreadsStore } from '@/stores/unreads.store';
import { crmApi } from '@/modules/crm/api/crmApi';
import {
    OptimisticLockConflictError,
    projectsApi,
    ValidationRequestError,
} from '@/modules/projects/api/projectsApi';

const { mockPage, mockRouterVisit, mockRouterGet, mockRouterReload, mockRouterPost } = vi.hoisted(() => ({
    mockPage: {
        url: '/app/projects',
        component: 'projects/pages/ProjectsIndexPage',
        props: {
            auth: {
                user: null as App.Modules.Core.Auth.Data.AuthenticatedUserData | null,
            },
            unreadCounts: {
                handoffs: 0,
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

import WorkspaceShellLayout from '@/layouts/WorkspaceShellLayout.vue';
import DealKanbanBoard from '@/modules/crm/components/DealKanbanBoard.vue';
import DealDetailPage from '@/modules/crm/pages/DealDetailPage.vue';
import HandoffReviewPanel from '@/modules/projects/components/HandoffReviewPanel.vue';
import HandoffSubmitDrawer from '@/modules/projects/components/HandoffSubmitDrawer.vue';
import ProjectKanbanBoard from '@/modules/projects/components/ProjectKanbanBoard.vue';
import TaskDetailDrawer from '@/modules/projects/components/TaskDetailDrawer.vue';
import HandoffDetailPage from '@/modules/projects/pages/HandoffDetailPage.vue';
import HandoffIntakePage from '@/modules/projects/pages/HandoffIntakePage.vue';
import MyWorkPage from '@/modules/projects/pages/MyWorkPage.vue';
import ProjectWorkspacePage from '@/modules/projects/pages/ProjectWorkspacePage.vue';
import ProjectsIndexPage from '@/modules/projects/pages/ProjectsIndexPage.vue';

function makeUser(
    permissions: string[] = [
        'handoffs.view',
        'handoffs.submit',
        'handoffs.review',
        'handoffs.approve',
        'projects.view',
        'projects.create',
        'projects.update',
        'tasks.view',
        'tasks.create',
        'tasks.update',
        'tasks.delete',
        'deals.view',
        'deals.update',
        'deals.move_stage',
    ],
): App.Modules.Core.Auth.Data.AuthenticatedUserData {
    return {
        id: 'usr-pm-1',
        name: 'Jordan Mercer',
        email: 'jordan.mercer@company.internal',
        employee_code: 'EMP-0142',
        job_title: 'Delivery Operations Lead',
        department_id: 'dept-delivery',
        roles: ['operations_manager'],
        permissions,
    };
}

function makeSummaryUser(
    id: string,
    name: string,
    role: string = 'project_manager',
): App.Modules.Core.Users.Data.UserSummaryData {
    return {
        id,
        employee_code: `EMP-${id}`,
        name,
        email: `${id}@company.internal`,
        job_title: 'Specialist',
        department_id: 'dept-delivery',
        department_name: 'Delivery',
        status: 'active',
        roles: [role],
        created_at: '2026-11-01T10:00:00Z',
    };
}

function makeStage(
    id: string,
    name: string,
    category: App.Modules.Projects.Projects.Enums.ProjectStageCategoryEnum,
    wipLimit: number | null = null,
    tasksCount: number = 0,
): App.Modules.Projects.Projects.Data.ProjectStageData {
    return {
        id,
        project_id: 'prj-1',
        name,
        category,
        sort_order: 1,
        wip_limit: wipLimit,
        tasks_count: tasksCount,
    };
}

function makeTask(
    id: string,
    overrides: Partial<App.Modules.Projects.Tasks.Data.TaskData> = {},
): App.Modules.Projects.Tasks.Data.TaskData {
    return {
        id,
        project_id: 'prj-1',
        project_code: 'PRJ-2026-0042',
        project_name: 'Northwind Logistics WMS',
        project_stage_id: 'stg-todo',
        project_stage_name: 'To Do',
        project_stage_category: 'todo',
        parent_task_id: null,
        linkable_type: null,
        linkable_id: null,
        creator_id: 'usr-pm-1',
        creator_name: 'Jordan Mercer',
        assignee_id: 'usr-pm-1',
        assignee_name: 'Jordan Mercer',
        title: `Task ${id}`,
        description_json: null,
        priority: 'medium',
        status: 'todo',
        board_position: 65536,
        due_at: null,
        completed_at: null,
        estimated_minutes: 60,
        version: 1,
        subtasks_count: 0,
        watchers_count: 0,
        watcher_ids: [],
        is_watching: false,
        has_masked_linkable: false,
        created_at: '2026-11-20T10:00:00Z',
        updated_at: '2026-11-25T10:00:00Z',
        ...overrides,
    };
}

function makeHandoffDetail(
    overrides: Partial<App.Modules.Projects.Handoffs.Data.DealHandoffDetailData> = {},
): App.Modules.Projects.Handoffs.Data.DealHandoffDetailData {
    return {
        id: 'hnd-1',
        deal_id: 'deal-won-1',
        company_id: 'comp-1',
        deal: {
            id: 'deal-won-1',
            title: 'Northwind Regional WMS Rollout',
            amount_cents: 4500000,
            currency: 'USD',
            stage_id: 'stg-won',
            stage_name: 'Closed Won',
            stage_type: 'won',
        },
        company: {
            id: 'comp-1',
            name: 'Northwind Logistics',
            domain: 'northwind.internal',
            lifecycle_stage: 'active_client',
        },
        status: 'pending_review',
        scope_notes_json: {
            type: 'doc',
            content: [
                {
                    type: 'paragraph',
                    content: [{ type: 'text', text: 'Phase 1 warehouse cutover and SSO integration.' }],
                },
            ],
        },
        handoff_checklist: [
            { label: 'Signed SOW attached', checked: true },
            { label: 'Technical lead assigned', checked: false },
        ],
        target_kickoff_date: '2026-12-10',
        review_feedback: null,
        submitted_by: makeSummaryUser('usr-rep-1', 'Devon Alvarez', 'sales'),
        reviewed_by: null,
        assigned_pm: null,
        assigned_am: makeSummaryUser('usr-am-1', 'Priya Nair', 'account_manager'),
        template: null,
        provisioned_project: null,
        submitted_at: '2026-11-26T08:00:00Z',
        reviewed_at: null,
        provisioned_at: null,
        created_at: '2026-11-26T08:00:00Z',
        updated_at: '2026-11-26T08:00:00Z',
        ...overrides,
    };
}

function createMockPresenceEcho(): {
    client: RealtimeEchoClient;
    joinSpy: ReturnType<typeof vi.fn>;
    leaveSpy: ReturnType<typeof vi.fn>;
    emitPrivate: (eventName: string, payload: unknown) => void;
    emitPresenceHere: (members: Array<{ id: string; name: string }>) => void;
    emitPresenceJoining: (member: { id: string; name: string }) => void;
    emitPresenceLeaving: (member: { id: string; name: string }) => void;
    emitBoardChanged: (eventName: string, payload: unknown) => void;
} {
    const privateHandlers = new Map<string, CallableFunction[]>();
    const presenceHandlers = new Map<string, CallableFunction[]>();
    let hereCb: CallableFunction | null = null;
    let joiningCb: CallableFunction | null = null;
    let leavingCb: CallableFunction | null = null;

    const privateChannelStub: RealtimeChannelLike = {
        listen: vi.fn((event: string, cb: CallableFunction) => {
            const list = privateHandlers.get(event) ?? [];
            list.push(cb);
            privateHandlers.set(event, list);
            return privateChannelStub;
        }),
        stopListening: vi.fn(() => privateChannelStub),
        notification: vi.fn(() => privateChannelStub),
        error: vi.fn(() => privateChannelStub),
    };

    const presenceChannelStub: RealtimePresenceChannelLike = {
        here: vi.fn((cb: CallableFunction) => {
            hereCb = cb;
            return presenceChannelStub;
        }),
        joining: vi.fn((cb: CallableFunction) => {
            joiningCb = cb;
            return presenceChannelStub;
        }),
        leaving: vi.fn((cb: CallableFunction) => {
            leavingCb = cb;
            return presenceChannelStub;
        }),
        listen: vi.fn((event: string, cb: CallableFunction) => {
            const list = presenceHandlers.get(event) ?? [];
            list.push(cb);
            presenceHandlers.set(event, list);
            return presenceChannelStub;
        }),
        stopListening: vi.fn((event: string, cb?: CallableFunction) => {
            if (cb) {
                presenceHandlers.set(
                    event,
                    (presenceHandlers.get(event) ?? []).filter((f) => f !== cb),
                );
            } else {
                presenceHandlers.delete(event);
            }
            return presenceChannelStub;
        }),
        notification: vi.fn(() => presenceChannelStub),
        error: vi.fn(() => presenceChannelStub),
    };

    const joinSpy = vi.fn(() => presenceChannelStub);
    const leaveSpy = vi.fn();

    const client: RealtimeEchoClient = {
        private: vi.fn(() => privateChannelStub),
        join: joinSpy,
        leave: leaveSpy,
        connectionStatus: (): ConnectionStatus => 'connected',
        disconnect: vi.fn(),
        connector: {
            onConnectionChange: () => () => {},
        },
    };

    return {
        client,
        joinSpy,
        leaveSpy,
        emitPrivate: (eventName, payload) => {
            (privateHandlers.get(eventName) ?? []).forEach((cb) => cb(payload));
        },
        emitPresenceHere: (members) => {
            hereCb?.(members);
        },
        emitPresenceJoining: (member) => {
            joiningCb?.(member);
        },
        emitPresenceLeaving: (member) => {
            leavingCb?.(member);
        },
        emitBoardChanged: (eventName, payload) => {
            (presenceHandlers.get(eventName) ?? []).forEach((cb) => cb(payload));
        },
    };
}

describe('Task 2.4 — Projects, Deal Handoffs, My Work & CRM/Shell Wiring', () => {
    let mountedWrappers: Array<{ unmount: () => void }> = [];

    beforeEach(() => {
        setActivePinia(createPinia());
        mockRouterVisit.mockReset();
        mockRouterGet.mockReset();
        mockRouterReload.mockReset();
        mockRouterPost.mockReset();
        mockPage.url = '/app/projects';
        mockPage.props = {
            auth: { user: makeUser() },
            unreadCounts: { handoffs: 0 },
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

    it('performs optimistic move in ProjectKanbanBoard, rolls back on 409 and 422 WIP limit, and handles Echo presence + TaskBoardChanged', async () => {
        vi.useFakeTimers();
        const mockEcho = createMockPresenceEcho();
        setEchoInstanceForTesting(mockEcho.client);

        const todoStage = makeStage('stg-todo', 'To Do', 'todo', null, 1);
        const doingStage = makeStage('stg-doing', 'In Progress', 'in_progress', 1, 1);
        const t1 = makeTask('task-1', {
            project_stage_id: 'stg-todo',
            project_stage_name: 'To Do',
            version: 2,
        });
        const t2 = makeTask('task-2', {
            project_stage_id: 'stg-doing',
            project_stage_name: 'In Progress',
            version: 1,
        });

        let rejectFirstMove!: (err: unknown) => void;
        const moveSpy = vi
            .spyOn(projectsApi, 'moveTask')
            .mockImplementationOnce(
                () =>
                    new Promise((_resolve, reject) => {
                        rejectFirstMove = reject;
                    }),
            )
            .mockRejectedValueOnce(
                new ValidationRequestError("Stage 'In Progress' has reached its WIP limit of 1.", {
                    target_stage_id: ["Stage 'In Progress' has reached its WIP limit of 1."],
                }),
            );

        const wrapper = mount(ProjectKanbanBoard, {
            attachTo: document.body,
            props: {
                projectId: 'prj-1',
                stages: [todoStage, doingStage],
                columns: [
                    { stage: todoStage, tasks_count: 1, tasks: [t1] },
                    { stage: doingStage, tasks_count: 1, tasks: [t2] },
                ],
                canMoveTasks: true,
            },
        });
        mountedWrappers.push(wrapper);
        await nextTick();

        expect(mockEcho.joinSpy).toHaveBeenCalledWith('project-board.prj-1');
        expect(wrapper.get('[data-testid="project-kanban-column-count-stg-todo"]').text()).toBe('1');
        expect(wrapper.get('[data-testid="project-kanban-column-count-stg-doing"]').text()).toBe('1 / 1');

        // Presence co-viewers track .here / .joining / .leaving
        mockEcho.emitPresenceHere([
            { id: 'usr-pm-1', name: 'Jordan Mercer' },
            { id: 'usr-pm-2', name: 'Devon Alvarez' },
        ]);
        await nextTick();
        expect(wrapper.get('[data-testid="project-board-presence-strip"]').text()).toContain('2 viewing');

        mockEcho.emitPresenceJoining({ id: 'usr-am-1', name: 'Priya Nair' });
        await nextTick();
        expect(wrapper.get('[data-testid="project-board-presence-strip"]').text()).toContain('3 viewing');

        mockEcho.emitPresenceLeaving({ id: 'usr-am-1', name: 'Priya Nair' });
        await nextTick();
        expect(wrapper.get('[data-testid="project-board-presence-strip"]').text()).toContain('2 viewing');

        // 1. Optimistic move + 409 Conflict rollback
        const stageSelect = wrapper.get<HTMLSelectElement>(
            '[data-testid="project-task-stage-select-task-1"]',
        );
        await stageSelect.setValue('stg-doing');
        await nextTick();

        expect(wrapper.get('[data-testid="project-kanban-column-count-stg-todo"]').text()).toBe('0');
        expect(wrapper.get('[data-testid="project-kanban-column-count-stg-doing"]').text()).toBe('2 / 1');

        rejectFirstMove(
            new OptimisticLockConflictError('Stale task version.', 3, { id: 'task-1', version: 3 }),
        );
        await flushPromises();
        await nextTick();

        expect(wrapper.get('[data-testid="project-kanban-column-count-stg-todo"]').text()).toBe('1');
        expect(wrapper.get('[data-testid="project-kanban-column-count-stg-doing"]').text()).toBe('1 / 1');
        expect(wrapper.get('[data-testid="project-kanban-conflict-banner"]').attributes('role')).toBe(
            'alert',
        );

        // 2. 422 WIP limit rollback + alert banner
        await stageSelect.setValue('stg-doing');
        await flushPromises();
        await nextTick();

        expect(moveSpy).toHaveBeenCalledTimes(2);
        expect(wrapper.get('[data-testid="project-kanban-column-count-stg-todo"]').text()).toBe('1');
        expect(wrapper.get('[data-testid="project-kanban-column-count-stg-doing"]').text()).toBe('1 / 1');
        const errBanner = wrapper.get('[data-testid="project-kanban-error-banner"]');
        expect(errBanner.attributes('role')).toBe('alert');
        expect(errBanner.text()).toContain('WIP limit of 1');

        // 3. TaskBoardChanged: ignores own current version (version 2), reloads on newer version (version 3)
        mockEcho.emitBoardChanged('.App\\Modules\\Projects\\Tasks\\Events\\TaskBoardChanged', {
            task_id: 'task-1',
            project_id: 'prj-1',
            version: 2,
        });
        vi.advanceTimersByTime(300);
        expect(mockRouterReload).not.toHaveBeenCalled();

        mockEcho.emitBoardChanged('.App\\Modules\\Projects\\Tasks\\Events\\TaskBoardChanged', {
            task_id: 'task-1',
            project_id: 'prj-1',
            version: 3,
        });
        vi.advanceTimersByTime(300);
        expect(mockRouterReload).toHaveBeenCalledTimes(1);

        wrapper.unmount();
        expect(mockEcho.leaveSpy).toHaveBeenCalledWith('project-board.prj-1');
    });

    it('validates HandoffSubmitDrawer scope notes and submits structured payload including checklist & AM', async () => {
        const submitSpy = vi.spyOn(projectsApi, 'submitHandoff').mockResolvedValue(
            makeHandoffDetail({ id: 'hnd-new-1' }),
        );

        const wrapper = mount(HandoffSubmitDrawer, {
            attachTo: document.body,
            props: {
                open: true,
                portalDisabled: true,
                deal: {
                    id: 'deal-won-1',
                    title: 'Pacific Meridian Health Implementation',
                    amount_cents: 6500000,
                    currency: 'USD',
                    company_name: 'Pacific Meridian Health',
                    account_manager_id: 'usr-am-1',
                },
                assignableAms: [makeSummaryUser('usr-am-1', 'Priya Nair', 'account_manager')],
            },
        });
        mountedWrappers.push(wrapper);
        await flushPromises();
        await nextTick();

        // Empty scope notes blocked with role="alert"
        await wrapper.get('[data-testid="handoff-submit-form"]').trigger('submit');
        await nextTick();

        expect(submitSpy).not.toHaveBeenCalled();
        expect(wrapper.get('[data-testid="handoff-submit-error"]').attributes('role')).toBe('alert');

        // Populate TipTap editor or fallback + kickoff date + new checklist item
        const fallbackTextarea = wrapper.find('[data-testid="rich-note-fallback-textarea"]');
        if (fallbackTextarea.exists()) {
            await fallbackTextarea.setValue('Deploy multi-site HL7 connectors by Q1.');
        } else {
            const editorComp = wrapper.findComponent({ name: 'RichNoteEditor' });
            const exposedEditor = (editorComp.vm as unknown as {
                editor?: { commands: { setContent: (doc: unknown) => void } };
            }).editor;
            exposedEditor?.commands.setContent({
                type: 'doc',
                content: [
                    {
                        type: 'paragraph',
                        content: [{ type: 'text', text: 'Deploy multi-site HL7 connectors by Q1.' }],
                    },
                ],
            });
        }

        const dateInput = wrapper.get<HTMLInputElement>('input[type="date"]');
        await dateInput.setValue('2026-12-28');

        const addCheckInput = wrapper.get<HTMLInputElement>(
            'input[placeholder*="Security questionnaire"]',
        );
        await addCheckInput.setValue('BAA countersigned');
        await wrapper.get('[data-testid="handoff-add-checklist-btn"]').trigger('click');

        await wrapper.get('[data-testid="handoff-submit-form"]').trigger('submit');
        await flushPromises();
        await nextTick();

        expect(submitSpy).toHaveBeenCalledTimes(1);
        expect(submitSpy).toHaveBeenCalledWith(
            'deal-won-1',
            expect.objectContaining({
                target_kickoff_date: '2026-12-28',
                assigned_am_id: 'usr-am-1',
                handoff_checklist: expect.arrayContaining([
                    expect.objectContaining({ label: 'BAA countersigned', checked: false }),
                ]),
            }),
        );
        expect(wrapper.emitted('submitted')).toHaveLength(1);
    });

    it('validates HandoffReviewPanel Approve form (template, PM, AM) and Request Changes / Reject feedback', async () => {
        const templates: App.Modules.Projects.Templates.Data.ProjectTemplateSummaryData[] = [
            {
                id: 'tpl-1',
                name: 'Standard Client Onboarding',
                department_id: 'dept-delivery',
                department_name: 'Delivery',
                description: 'Standard onboarding workflow',
                is_active: true,
                default_stages: [],
                tasks_count: 8,
                created_at: '2026-11-01T00:00:00Z',
                updated_at: '2026-11-01T00:00:00Z',
            },
        ];
        const pms = [makeSummaryUser('usr-pm-1', 'Jordan Mercer', 'project_manager')];
        const ams = [makeSummaryUser('usr-am-1', 'Priya Nair', 'account_manager')];

        const approveSpy = vi.spyOn(projectsApi, 'approveHandoff').mockResolvedValue(
            makeHandoffDetail({ status: 'approved', assigned_pm: pms[0], template: templates[0] }),
        );
        const requestChangesSpy = vi.spyOn(projectsApi, 'requestChanges').mockResolvedValue(
            makeHandoffDetail({ status: 'changes_requested', review_feedback: 'Need signed SOW.' }),
        );

        const wrapper = mount(HandoffReviewPanel, {
            attachTo: document.body,
            props: {
                handoff: makeHandoffDetail(),
                templates,
                assignablePms: pms,
                assignableAms: ams,
                canApprove: true,
                canRequestChanges: true,
                canReject: true,
            },
        });
        mountedWrappers.push(wrapper);
        await nextTick();

        // Clear template selection -> validation alert blocks approve
        await wrapper.get('[data-testid="handoff-approve-template"]').setValue('');
        await wrapper.get('[data-testid="handoff-approve-form"]').trigger('submit');
        await nextTick();

        expect(approveSpy).not.toHaveBeenCalled();
        expect(wrapper.get('[data-testid="handoff-review-error"]').attributes('role')).toBe('alert');

        // Select template + PM + AM and approve
        await wrapper.get('[data-testid="handoff-approve-template"]').setValue('tpl-1');
        await wrapper.get('[data-testid="handoff-approve-pm"]').setValue('usr-pm-1');
        await wrapper.get('[data-testid="handoff-approve-am"]').setValue('usr-am-1');
        await wrapper.get('[data-testid="handoff-approve-form"]').trigger('submit');
        await flushPromises();

        expect(approveSpy).toHaveBeenCalledWith(
            'hnd-1',
            expect.objectContaining({
                project_template_id: 'tpl-1',
                assigned_pm_id: 'usr-pm-1',
                assigned_am_id: 'usr-am-1',
            }),
        );
        expect(wrapper.emitted('updated')).toHaveLength(1);

        // Request changes feedback validation + submission
        await wrapper.get('[data-testid="handoff-open-request-changes"]').trigger('click');
        await flushPromises();
        await nextTick();

        const decisionForm = document.body.querySelector('[data-testid="handoff-decision-form"]');
        expect(decisionForm).not.toBeNull();
        decisionForm?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await nextTick();

        expect(requestChangesSpy).not.toHaveBeenCalled();
        expect(document.body.querySelector('[data-testid="handoff-decision-error"]')?.getAttribute('role')).toBe(
            'alert',
        );

        const feedbackTextarea = document.body.querySelector<HTMLTextAreaElement>(
            'textarea[placeholder*="Specify missing SOW"]',
        );
        expect(feedbackTextarea).not.toBeNull();
        if (feedbackTextarea) {
            feedbackTextarea.value = 'Need signed SOW.';
            feedbackTextarea.dispatchEvent(new Event('input', { bubbles: true }));
        }
        await nextTick();
        decisionForm?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await flushPromises();

        expect(requestChangesSpy).toHaveBeenCalledWith('hnd-1', {
            review_feedback: 'Need signed SOW.',
        });
    });

    it('groups MyWorkPage into Overdue, Today, This Week, and Later and supports optimistic completion with 409 rollback', async () => {
        const overdueTask = makeTask('t-overdue', {
            title: 'Send security architecture sign-off',
            priority: 'urgent',
            due_at: '2026-11-24T12:00:00Z',
            version: 2,
        });
        const todayTask = makeTask('t-today', {
            title: 'Confirm Northwind billing schedule',
            project_id: null,
            project_code: null,
            project_name: null,
            linkable_type: 'deal',
            linkable_id: 'deal-won-1',
            priority: 'high',
            due_at: '2026-11-26T16:00:00Z',
            version: 1,
        });
        const weekTask = makeTask('t-week', {
            title: 'Review staging data import',
            project_id: null,
            project_code: null,
            has_masked_linkable: true,
            priority: 'medium',
            due_at: '2026-11-30T12:00:00Z',
            version: 1,
        });
        const laterTask = makeTask('t-later', {
            title: 'Draft post-cutover retrospective',
            priority: 'low',
            due_at: null,
            version: 1,
        });

        const updateSpy = vi
            .spyOn(projectsApi, 'updateTask')
            .mockResolvedValueOnce({ ...overdueTask, status: 'done', version: 3 })
            .mockRejectedValueOnce(
                new OptimisticLockConflictError('Task modified elsewhere.', 4, {
                    ...todayTask,
                    version: 4,
                }),
            );

        const wrapper = mount(MyWorkPage, {
            attachTo: document.body,
            props: {
                groups: [
                    { key: 'overdue', label: 'Overdue', tasks_count: 1, tasks: [overdueTask] },
                    { key: 'today', label: 'Today', tasks_count: 1, tasks: [todayTask] },
                    { key: 'this_week', label: 'This Week', tasks_count: 1, tasks: [weekTask] },
                    { key: 'later', label: 'Later', tasks_count: 1, tasks: [laterTask] },
                ],
                totalCount: 4,
                priorities: ['low', 'medium', 'high', 'urgent'],
                statuses: ['todo', 'in_progress', 'blocked', 'review', 'done', 'cancelled'],
                canUpdateTasks: true,
            },
        });
        mountedWrappers.push(wrapper);
        await nextTick();

        expect(wrapper.get('[data-testid="my-work-group-overdue"]').text()).toContain('Overdue');
        expect(wrapper.get('[data-testid="my-work-group-today"]').text()).toContain('Today');
        expect(wrapper.get('[data-testid="my-work-group-this_week"]').text()).toContain('This Week');
        expect(wrapper.get('[data-testid="my-work-group-later"]').text()).toContain('Later');
        expect(wrapper.get('[data-testid="my-work-project-pill-t-overdue"]').text()).toContain(
            'PRJ-2026-0042',
        );
        expect(wrapper.get('[data-testid="my-work-crm-pill-t-today"]').text()).toContain('Deal');
        expect(wrapper.get('[data-testid="my-work-masked-pill-t-week"]').text()).toContain(
            'Restricted CRM link',
        );

        // Quick complete overdue task -> optimistic removal + API call
        await wrapper.get('[data-testid="my-work-complete-t-overdue"]').trigger('change');
        await flushPromises();
        await nextTick();

        expect(updateSpy).toHaveBeenNthCalledWith(1, 't-overdue', {
            expected_version: 2,
            status: 'done',
        });
        expect(wrapper.find('[data-testid="my-work-task-row-t-overdue"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="my-work-group-count-overdue"]').text()).toBe('0');

        // 409 conflict on quick complete -> rolls back todayTask into Today group + alert banner
        await wrapper.get('[data-testid="my-work-complete-t-today"]').trigger('change');
        await flushPromises();
        await nextTick();

        expect(wrapper.find('[data-testid="my-work-task-row-t-today"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="my-work-group-count-today"]').text()).toBe('1');
        expect(wrapper.get('[data-testid="my-work-error-banner"]').attributes('role')).toBe('alert');
    });

    it('opens HandoffSubmitDrawer when Deal moves to won on DealKanbanBoard and exposes activeHandoff / Submit handoff on DealDetailPage', async () => {
        const openStage: App.Modules.Crm.Pipelines.Data.PipelineStageData = {
            id: 'stg-open',
            pipeline_id: 'pipe-1',
            name: 'Negotiation',
            stage_type: 'open',
            win_probability: 75,
            rotting_days: 14,
            required_core_fields: [],
            required_custom_field_ids: [],
            sort_order: 1,
        };
        const wonStage: App.Modules.Crm.Pipelines.Data.PipelineStageData = {
            id: 'stg-won',
            pipeline_id: 'pipe-1',
            name: 'Closed Won',
            stage_type: 'won',
            win_probability: 100,
            rotting_days: null,
            required_core_fields: [],
            required_custom_field_ids: [],
            sort_order: 2,
        };
        const dealCard: App.Modules.Crm.Deals.Data.DealCardData = {
            id: 'deal-won-1',
            company_id: 'comp-1',
            company_name: 'Northwind Logistics',
            primary_contact_id: 'cont-1',
            primary_contact_name: 'Hannah Kowalski',
            pipeline_id: 'pipe-1',
            stage_id: 'stg-open',
            stage_name: 'Negotiation',
            stage_type: 'open',
            owner_id: 'usr-rep-1',
            owner_name: 'Devon Alvarez',
            account_manager_id: null,
            account_manager_name: null,
            title: 'Northwind Regional WMS Rollout',
            amount_cents: 4500000,
            currency: 'USD',
            expected_close_date: '2026-12-15',
            stage_entered_at: '2026-11-20T10:00:00Z',
            won_at: null,
            lost_at: null,
            board_position: 65536,
            version: 1,
            is_rotting: false,
            custom_fields: {},
            updated_at: '2026-11-25T10:00:00Z',
        };
        const wonDetail: App.Modules.Crm.Deals.Data.DealDetailData = {
            ...dealCard,
            company_domain: 'northwind.internal',
            primary_contact_email: 'h.kowalski@northwind.internal',
            pipeline_name: 'Enterprise Sales',
            stage_id: 'stg-won',
            stage_name: 'Closed Won',
            stage_type: 'won',
            stage_win_probability: 100,
            lost_reason: null,
            won_at: '2026-11-26T00:00:00Z',
            version: 2,
            created_at: '2026-11-01T10:00:00Z',
            has_masked_company: false,
            has_masked_primary_contact: false,
        };

        vi.spyOn(crmApi, 'moveDealStage').mockResolvedValue(wonDetail);

        const boardWrapper = mount(DealKanbanBoard, {
            attachTo: document.body,
            props: {
                board: {
                    pipeline: {
                        id: 'pipe-1',
                        name: 'Enterprise Sales',
                        slug: 'enterprise-sales',
                        department_id: null,
                        is_default: true,
                        is_active: true,
                        sort_order: 0,
                        stages: [openStage, wonStage],
                    },
                    columns: [
                        {
                            stage: openStage,
                            deals_count: 1,
                            total_amount_cents: dealCard.amount_cents,
                            deals: [dealCard],
                        },
                        {
                            stage: wonStage,
                            deals_count: 0,
                            total_amount_cents: 0,
                            deals: [],
                        },
                    ],
                    total_deals_count: 1,
                    total_amount_cents: dealCard.amount_cents,
                },
                customFieldDefinitions: [],
                canMoveDeals: true,
                canSubmitHandoff: true,
            },
        });
        mountedWrappers.push(boardWrapper);

        await boardWrapper.get('[data-testid="kanban-stage-select-deal-won-1"]').setValue('stg-won');
        await flushPromises();
        await nextTick();

        expect(document.body.querySelector('[data-testid="handoff-submit-drawer"]')).not.toBeNull();

        // DealDetailPage: won deal without active handoff shows Submit handoff CTA
        const detailWrapper = mount(DealDetailPage, {
            attachTo: document.body,
            props: {
                deal: wonDetail,
                company: {
                    id: 'comp-1',
                    name: 'Northwind Logistics',
                    domain: 'northwind.internal',
                    lifecycle_stage: 'active_client',
                },
                primaryContact: null,
                pipeline: {
                    id: 'pipe-1',
                    name: 'Enterprise Sales',
                    slug: 'enterprise-sales',
                    department_id: null,
                    is_default: true,
                    is_active: true,
                    sort_order: 0,
                    stages: [openStage, wonStage],
                },
                stakeholders: [],
                collaborators: [],
                attachments: [],
                customFieldDefinitions: [],
                timeline: { items: [], next_cursor: null, has_more: false },
                assignableUsers: [],
                activeHandoff: null,
                companies: [],
                contacts: [],
                stakeholderRoles: ['decision_maker'],
                collaboratorAccessLevels: ['viewer'],
                collaboratorRoleLabels: ['account_manager'],
                can: {
                    update: true,
                    delete: false,
                    moveStage: true,
                    overrideGates: false,
                    manageCollaborators: false,
                    submitHandoff: true,
                },
            },
        });
        mountedWrappers.push(detailWrapper);
        await nextTick();

        expect(detailWrapper.find('[data-testid="deal-submit-handoff-cta"]').exists()).toBe(true);

        // When activeHandoff exists, shows status pill + link instead of Submit CTA
        await detailWrapper.setProps({
            activeHandoff: {
                id: 'hnd-99',
                deal_id: 'deal-won-1',
                company_id: 'comp-1',
                deal_title: wonDetail.title,
                deal_amount_cents: wonDetail.amount_cents,
                deal_currency: wonDetail.currency,
                company_name: wonDetail.company_name,
                status: 'pending_review',
                target_kickoff_date: null,
                review_feedback: null,
                submitted_by: null,
                reviewed_by: null,
                assigned_pm: null,
                assigned_am: null,
                template: null,
                provisioned_project: null,
                submitted_at: '2026-11-26T08:00:00Z',
                reviewed_at: null,
                provisioned_at: null,
            },
        });
        await nextTick();

        expect(detailWrapper.find('[data-testid="deal-submit-handoff-cta"]').exists()).toBe(false);
        const activeLink = detailWrapper.get('[data-testid="deal-active-handoff-link"]');
        expect(activeLink.attributes('href')).toBe('/app/projects/handoffs/hnd-99');
    });

    it('wires My Work nav link, unreadCounts.handoffs sync, and live HandoffStatusChanged updates in WorkspaceShellLayout', async () => {
        const mockEcho = createMockPresenceEcho();
        setEchoInstanceForTesting(mockEcho.client);

        mockPage.props.unreadCounts = { handoffs: 2 };

        const wrapper = mount(WorkspaceShellLayout, {
            attachTo: document.body,
            slots: {
                default: () => h('div', 'Delivery Shell'),
            },
        });
        mountedWrappers.push(wrapper);
        await nextTick();

        const unreadsStore = useUnreadsStore();
        const myWorkLink = wrapper.find('a[href="/app/my-work"]');
        expect(myWorkLink.exists()).toBe(true);
        expect(myWorkLink.text()).toContain('My Work');
        expect(unreadsStore.handoffs).toBe(2);
        expect(wrapper.get('[data-testid="unread-badge-handoffs"]').text()).toBe('2');

        // Live HandoffStatusChanged (pending_review) increments unread badge immediately
        mockEcho.emitPrivate(HANDOFF_STATUS_CHANGED_EVENT, {
            handoff_id: 'hnd-new',
            status: 'pending_review',
            deal_id: 'deal-1',
        });
        await nextTick();

        expect(unreadsStore.handoffs).toBe(3);
        expect(wrapper.get('[data-testid="unread-badge-handoffs"]').text()).toBe('3');
    });

    it('supports TaskDetailDrawer 1-level subtasks, watcher toggle, 409 conflict load-latest, and ProjectWorkspacePage tabs', async () => {
        const mockEcho = createMockPresenceEcho();
        setEchoInstanceForTesting(mockEcho.client);

        const parentTask = makeTask('task-parent', {
            title: 'Provision SSO tenant',
            version: 1,
            watchers_count: 0,
            is_watching: false,
        });
        const subtask = makeTask('task-sub-1', {
            parent_task_id: 'task-parent',
            title: 'Upload metadata XML',
            version: 1,
        });

        vi.spyOn(projectsApi, 'toggleWatcher').mockResolvedValue({
            ...parentTask,
            is_watching: true,
            watchers_count: 1,
            watcher_ids: ['usr-pm-1'],
        });
        vi.spyOn(projectsApi, 'createTask').mockResolvedValue(
            makeTask('task-sub-2', {
                parent_task_id: 'task-parent',
                title: 'Verify SAML assertion',
            }),
        );
        vi.spyOn(projectsApi, 'updateTask').mockRejectedValueOnce(
            new OptimisticLockConflictError('Stale task version.', 4, {
                ...parentTask,
                title: 'Provision SSO tenant (Server v4)',
                version: 4,
            }),
        );

        const drawerWrapper = mount(TaskDetailDrawer, {
            attachTo: document.body,
            props: {
                open: true,
                portalDisabled: true,
                task: parentTask,
                subtasks: [subtask],
                assignableUsers: [makeSummaryUser('usr-pm-1', 'Jordan Mercer')],
                canUpdate: true,
                canDelete: false,
                canCreateSubtask: true,
            },
        });
        mountedWrappers.push(drawerWrapper);
        await nextTick();

        await drawerWrapper.get('[data-testid="task-watcher-toggle"]').trigger('click');
        await flushPromises();
        expect(drawerWrapper.get('[data-testid="task-watcher-toggle"]').text()).toContain('Watching (1)');

        const subtaskInput = drawerWrapper.get<HTMLInputElement>('input[placeholder="Add 1-level subtask..."]');
        await subtaskInput.setValue('Verify SAML assertion');
        await drawerWrapper.get('[data-testid="task-subtasks-section"] form').trigger('submit');
        await flushPromises();
        expect(drawerWrapper.text()).toContain('Verify SAML assertion');

        // 409 Conflict -> Load latest updates version badge
        await drawerWrapper.get('[data-testid="task-drawer-form"]').trigger('submit');
        await flushPromises();
        await nextTick();
        expect(drawerWrapper.get('[data-testid="task-drawer-conflict-banner"]').attributes('role')).toBe(
            'alert',
        );
        await drawerWrapper.get('[data-testid="task-drawer-load-latest"]').trigger('click');
        await nextTick();
        expect(drawerWrapper.get('[data-testid="task-drawer-version"]').text()).toBe('v4');

        // ProjectWorkspacePage co-viewers & tab switching + HandoffIntakePage empty/ready check
        const stageTodo = makeStage('stg-todo', 'To Do', 'todo', null, 2);
        const wsWrapper = mount(ProjectWorkspacePage, {
            attachTo: document.body,
            props: {
                project: {
                    id: 'prj-1',
                    code: 'PRJ-2026-0042',
                    company_id: 'comp-1',
                    company_name: 'Northwind Logistics',
                    company_domain: 'northwind.internal',
                    primary_deal_id: 'deal-won-1',
                    primary_deal_title: 'Northwind Regional WMS Rollout',
                    deal_handoff_id: 'hnd-1',
                    owner_id: 'usr-pm-1',
                    owner_name: 'Jordan Mercer',
                    account_manager_id: 'usr-am-1',
                    account_manager_name: 'Priya Nair',
                    department_id: 'dept-delivery',
                    department_name: 'Delivery',
                    name: 'Northwind Logistics — WMS Deployment',
                    description_json: null,
                    status: 'active',
                    health: 'on_track',
                    start_date: '2026-11-26',
                    target_end_date: '2026-12-30',
                    completed_at: null,
                    custom_fields: {},
                    has_masked_company: false,
                    has_masked_deal: false,
                    created_at: '2026-11-26T08:00:00Z',
                    updated_at: '2026-11-26T08:00:00Z',
                },
                stages: [stageTodo],
                columns: [{ stage: stageTodo, tasks_count: 2, tasks: [parentTask, subtask] }],
                tasks: [parentTask, subtask],
                collaborators: [],
                attachments: [],
                customFieldDefinitions: [],
                timeline: { items: [], next_cursor: null, has_more: false },
                assignableUsers: [makeSummaryUser('usr-pm-1', 'Jordan Mercer')],
                collaboratorAccessLevels: ['viewer', 'editor'],
                collaboratorRoleLabels: ['project_manager'],
                statuses: ['planning', 'active', 'on_hold', 'completed', 'cancelled'],
                healthOptions: ['on_track', 'at_risk', 'off_track'],
                stageCategories: ['backlog', 'todo', 'in_progress', 'review', 'done', 'cancelled'],
                taskPriorities: ['low', 'medium', 'high', 'urgent'],
                taskStatuses: ['todo', 'in_progress', 'blocked', 'review', 'done', 'cancelled'],
                can: {
                    update: true,
                    delete: true,
                    manageStages: true,
                    manageCollaborators: true,
                    createTask: true,
                },
            },
        });
        mountedWrappers.push(wsWrapper);
        await nextTick();

        mockEcho.emitPresenceHere([
            { id: 'usr-pm-1', name: 'Jordan Mercer' },
            { id: 'usr-dir-1', name: 'Elena Vasquez' },
        ]);
        await nextTick();
        expect(wsWrapper.get('[data-testid="project-co-viewers"]').text()).toContain('2 viewing');

        await wsWrapper.get('[data-testid="project-tab-list"]').trigger('click');
        await nextTick();
        expect(wsWrapper.find('[data-testid="project-task-list-table"]').exists()).toBe(true);

        const intakeWrapper = mount(HandoffIntakePage, {
            attachTo: document.body,
            props: {
                handoffs: [],
                statusCounts: {
                    pending_review: 0,
                    changes_requested: 0,
                    approved: 0,
                    provisioned: 0,
                    rejected: 0,
                },
                activeStatus: null,
                templates: [],
                assignablePms: [],
                assignableAms: [],
                can: { review: true, approve: true, submit: false },
            },
        });
        mountedWrappers.push(intakeWrapper);
        await nextTick();
        expect(intakeWrapper.get('[data-testid="handoff-review-empty"]').text()).toContain(
            'Select a handoff to inspect',
        );
    });

    it('opens HandoffSubmitDrawer only on transition into Won (Open -> Won) and not on same-stage Won -> Won reorder', async () => {
        const openStage: App.Modules.Crm.Pipelines.Data.PipelineStageData = {
            id: 'stg-open',
            pipeline_id: 'pipe-1',
            name: 'Negotiation',
            stage_type: 'open',
            win_probability: 75,
            rotting_days: 14,
            required_core_fields: [],
            required_custom_field_ids: [],
            sort_order: 1,
        };
        const wonStage: App.Modules.Crm.Pipelines.Data.PipelineStageData = {
            id: 'stg-won',
            pipeline_id: 'pipe-1',
            name: 'Closed Won',
            stage_type: 'won',
            win_probability: 100,
            rotting_days: null,
            required_core_fields: [],
            required_custom_field_ids: [],
            sort_order: 2,
        };

        const baseDeal = {
            company_id: 'comp-1',
            company_name: 'Northwind Logistics',
            primary_contact_id: null,
            primary_contact_name: null,
            pipeline_id: 'pipe-1',
            owner_id: 'usr-rep-1',
            owner_name: 'Devon Alvarez',
            account_manager_id: null,
            account_manager_name: null,
            amount_cents: 2500000,
            currency: 'USD',
            expected_close_date: '2026-12-15',
            stage_entered_at: '2026-11-20T10:00:00Z',
            lost_at: null,
            is_rotting: false,
            custom_fields: {},
            updated_at: '2026-11-25T10:00:00Z',
        };

        const openDeal: App.Modules.Crm.Deals.Data.DealCardData = {
            ...baseDeal,
            id: 'deal-open-1',
            stage_id: 'stg-open',
            stage_name: 'Negotiation',
            stage_type: 'open',
            title: 'Cascadia Cold Chain Phase 1',
            won_at: null,
            board_position: 65536,
            version: 1,
        };
        const wonDealA: App.Modules.Crm.Deals.Data.DealCardData = {
            ...baseDeal,
            id: 'deal-won-a',
            stage_id: 'stg-won',
            stage_name: 'Closed Won',
            stage_type: 'won',
            title: 'Boreal Freight Portal',
            won_at: '2026-11-24T10:00:00Z',
            board_position: 65536,
            version: 1,
        };
        const wonDealB: App.Modules.Crm.Deals.Data.DealCardData = {
            ...baseDeal,
            id: 'deal-won-b',
            stage_id: 'stg-won',
            stage_name: 'Closed Won',
            stage_type: 'won',
            title: 'Redwood Fleet Telemetry',
            won_at: '2026-11-25T10:00:00Z',
            board_position: 131072,
            version: 1,
        };

        const reorderedWonDetail: App.Modules.Crm.Deals.Data.DealDetailData = {
            ...wonDealA,
            company_domain: 'northwind.internal',
            primary_contact_email: null,
            pipeline_name: 'Enterprise Sales',
            stage_win_probability: 100,
            lost_reason: null,
            version: 2,
            created_at: '2026-11-01T10:00:00Z',
            has_masked_company: false,
            has_masked_primary_contact: false,
        };
        const transitionedWonDetail: App.Modules.Crm.Deals.Data.DealDetailData = {
            ...openDeal,
            stage_id: 'stg-won',
            stage_name: 'Closed Won',
            stage_type: 'won',
            won_at: '2026-11-26T00:00:00Z',
            company_domain: 'northwind.internal',
            primary_contact_email: null,
            pipeline_name: 'Enterprise Sales',
            stage_win_probability: 100,
            lost_reason: null,
            version: 2,
            created_at: '2026-11-01T10:00:00Z',
            has_masked_company: false,
            has_masked_primary_contact: false,
        };

        const moveSpy = vi
            .spyOn(crmApi, 'moveDealStage')
            .mockResolvedValueOnce(reorderedWonDetail)
            .mockResolvedValueOnce(transitionedWonDetail);

        const boardWrapper = mount(DealKanbanBoard, {
            attachTo: document.body,
            props: {
                board: {
                    pipeline: {
                        id: 'pipe-1',
                        name: 'Enterprise Sales',
                        slug: 'enterprise-sales',
                        department_id: null,
                        is_default: true,
                        is_active: true,
                        sort_order: 0,
                        stages: [openStage, wonStage],
                    },
                    columns: [
                        {
                            stage: openStage,
                            deals_count: 1,
                            total_amount_cents: openDeal.amount_cents,
                            deals: [openDeal],
                        },
                        {
                            stage: wonStage,
                            deals_count: 2,
                            total_amount_cents: wonDealA.amount_cents + wonDealB.amount_cents,
                            deals: [wonDealA, wonDealB],
                        },
                    ],
                    total_deals_count: 3,
                    total_amount_cents: openDeal.amount_cents * 3,
                },
                customFieldDefinitions: [],
                canMoveDeals: true,
                canSubmitHandoff: true,
            },
        });
        mountedWrappers.push(boardWrapper);

        // 1. Same-stage reorder inside Won (Won -> Won via drag/drop) must NOT open HandoffSubmitDrawer
        await boardWrapper.get('[data-testid="kanban-card-deal-won-a"]').trigger('dragstart');
        await boardWrapper.get('[data-testid="kanban-card-deal-won-b"]').trigger('drop');
        await flushPromises();
        await nextTick();

        expect(moveSpy).toHaveBeenCalledTimes(1);
        expect(boardWrapper.emitted('dealMoved')).toHaveLength(1);
        expect(document.body.querySelector('[data-testid="handoff-submit-drawer"]')).toBeNull();

        // 2. Stage transition into Won (Open -> Won) MUST open HandoffSubmitDrawer
        await boardWrapper.get('[data-testid="kanban-stage-select-deal-open-1"]').setValue('stg-won');
        await flushPromises();
        await nextTick();

        expect(moveSpy).toHaveBeenCalledTimes(2);
        expect(boardWrapper.emitted('dealMoved')).toHaveLength(2);
        expect(document.body.querySelector('[data-testid="handoff-submit-drawer"]')).not.toBeNull();
    });

    it('restricts ProjectKanbanBoard drag handles when can.update is false and shows offline banners on HandoffDetailPage and ProjectsIndexPage', async () => {
        const authStore = useAuthStore();
        authStore.setUser(makeUser(['projects.view', 'tasks.view', 'tasks.update']));

        const realtimeStore = useRealtimeStore();
        realtimeStore.status = 'disconnected';
        realtimeStore.lastError = 'Socket disconnected';

        const stageTodo = makeStage('stg-todo', 'To Do', 'todo', null, 1);
        const viewerTask = makeTask('t-viewer', { project_stage_id: 'stg-todo', title: 'Audit delivery checklist' });

        const viewerWorkspace = mount(ProjectWorkspacePage, {
            attachTo: document.body,
            props: {
                project: {
                    id: 'prj-1',
                    code: 'PRJ-2026-0042',
                    company_id: 'comp-1',
                    company_name: 'Northwind Logistics',
                    company_domain: 'northwind.internal',
                    primary_deal_id: 'deal-won-1',
                    primary_deal_title: 'Northwind Regional WMS Rollout',
                    deal_handoff_id: 'hnd-1',
                    owner_id: 'usr-pm-1',
                    owner_name: 'Jordan Mercer',
                    account_manager_id: 'usr-am-1',
                    account_manager_name: 'Priya Nair',
                    department_id: 'dept-delivery',
                    department_name: 'Delivery',
                    name: 'Northwind Logistics — WMS Deployment',
                    description_json: null,
                    status: 'active',
                    health: 'on_track',
                    start_date: '2026-11-26',
                    target_end_date: '2026-12-30',
                    completed_at: null,
                    custom_fields: {},
                    has_masked_company: false,
                    has_masked_deal: false,
                    created_at: '2026-11-26T08:00:00Z',
                    updated_at: '2026-11-26T08:00:00Z',
                },
                stages: [stageTodo],
                columns: [{ stage: stageTodo, tasks_count: 1, tasks: [viewerTask] }],
                tasks: [viewerTask],
                collaborators: [],
                attachments: [],
                customFieldDefinitions: [],
                timeline: { items: [], next_cursor: null, has_more: false },
                assignableUsers: [makeSummaryUser('usr-pm-1', 'Jordan Mercer')],
                collaboratorAccessLevels: ['viewer', 'editor'],
                collaboratorRoleLabels: ['project_manager'],
                statuses: ['planning', 'active', 'on_hold', 'completed', 'cancelled'],
                healthOptions: ['on_track', 'at_risk', 'off_track'],
                stageCategories: ['backlog', 'todo', 'in_progress', 'review', 'done', 'cancelled'],
                taskPriorities: ['low', 'medium', 'high', 'urgent'],
                taskStatuses: ['todo', 'in_progress', 'blocked', 'review', 'done', 'cancelled'],
                can: {
                    update: false,
                    delete: false,
                    manageStages: false,
                    manageCollaborators: false,
                    createTask: false,
                },
            },
        });
        mountedWrappers.push(viewerWorkspace);
        await nextTick();

        expect(
            viewerWorkspace.get('[data-testid="project-task-card-t-viewer"]').attributes('draggable'),
        ).toBe('false');

        const detailWrapper = mount(HandoffDetailPage, {
            attachTo: document.body,
            props: {
                handoff: makeHandoffDetail(),
                templates: [],
                assignablePms: [],
                assignableAms: [],
                can: {
                    resubmit: false,
                    requestChanges: false,
                    reject: false,
                    approve: false,
                    viewProject: false,
                },
            },
        });
        mountedWrappers.push(detailWrapper);
        await nextTick();
        expect(
            detailWrapper.get('[data-testid="handoff-detail-offline-banner"]').attributes('role'),
        ).toBe('status');

        const indexWrapper = mount(ProjectsIndexPage, {
            attachTo: document.body,
            props: {
                projects: {
                    data: [],
                    current_page: 1,
                    last_page: 1,
                    per_page: 25,
                    total: 0,
                },
                filters: {
                    search: null,
                    status: null,
                    health: null,
                    owner_id: null,
                    company_id: null,
                    sort_by: 'updated_at',
                    sort_dir: 'desc',
                    page: 1,
                    per_page: 25,
                },
                assignableUsers: [],
                companies: [],
                customFieldDefinitions: [],
                statuses: ['planning', 'active'],
                healthOptions: ['on_track', 'at_risk'],
                can: { create: false },
            },
        });
        mountedWrappers.push(indexWrapper);
        await nextTick();
        expect(
            indexWrapper.get('[data-testid="projects-index-offline-banner"]').attributes('role'),
        ).toBe('status');
    });

    it('renders restricted fallbacks and hides cross-silo deal/company links on HandoffDetailPage when deal, company, and provisioned_project are null', async () => {
        const maskedWrapper = mount(HandoffDetailPage, {
            attachTo: document.body,
            props: {
                handoff: makeHandoffDetail({
                    company_id: null,
                    deal: null,
                    company: null,
                    provisioned_project: null,
                }),
                templates: [],
                assignablePms: [],
                assignableAms: [],
                can: {
                    resubmit: false,
                    requestChanges: false,
                    reject: false,
                    approve: false,
                    viewProject: false,
                },
            },
        });
        mountedWrappers.push(maskedWrapper);
        await nextTick();

        expect(maskedWrapper.text()).toContain('Deal restricted');
        expect(maskedWrapper.get('[data-testid="handoff-restricted-company"]').text()).toBe(
            'Restricted client',
        );
        expect(maskedWrapper.find('[data-testid="handoff-view-deal-link"]').exists()).toBe(false);
        expect(maskedWrapper.find('[data-testid="handoff-company-link"]').exists()).toBe(false);
        expect(maskedWrapper.find('a[href="/app/crm/deals/deal-won-1"]').exists()).toBe(false);
        expect(maskedWrapper.find('a[href="/app/crm/companies/comp-1"]').exists()).toBe(false);
        expect(maskedWrapper.find('[data-testid="handoff-provisioned-project-card"]').exists()).toBe(
            false,
        );
    });
});
