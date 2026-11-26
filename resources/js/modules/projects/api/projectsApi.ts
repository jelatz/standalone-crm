import {
    ApiRequestError,
    crmHttpClient,
    extractFirstFieldErrors,
    ForbiddenRequestError,
    mapCrmApiError,
    OptimisticLockConflictError,
    ValidationRequestError,
    type FieldValidationErrors,
} from '@/modules/crm/api/crmApi';

export {
    ApiRequestError,
    crmHttpClient as projectsHttpClient,
    extractFirstFieldErrors,
    ForbiddenRequestError,
    mapCrmApiError as mapProjectsApiError,
    OptimisticLockConflictError,
    ValidationRequestError,
    type FieldValidationErrors,
};

type UndefinedAsOptional<T> = {
    [K in keyof T as undefined extends T[K] ? never : K]: T[K];
} & {
    [K in keyof T as undefined extends T[K] ? K : never]?: T[K];
};

async function requestData<T>(fn: () => Promise<{ data: T }>): Promise<T> {
    try {
        const response = await fn();
        return response.data;
    } catch (error) {
        throw mapCrmApiError(error);
    }
}

export const projectsApi = {
    // Handoffs & Templates
    submitHandoff(
        dealId: string,
        payload: App.Modules.Projects.Handoffs.Data.SubmitHandoffData,
    ) {
        return requestData<App.Modules.Projects.Handoffs.Data.DealHandoffDetailData>(() =>
            crmHttpClient.post(`/api/internal/crm/deals/${dealId}/handoffs`, payload),
        );
    },
    resubmitHandoff(
        handoffId: string,
        payload: App.Modules.Projects.Handoffs.Data.SubmitHandoffData,
    ) {
        return requestData<App.Modules.Projects.Handoffs.Data.DealHandoffDetailData>(() =>
            crmHttpClient.post(`/api/internal/handoffs/${handoffId}/resubmit`, payload),
        );
    },
    requestChanges(
        handoffId: string,
        payload: App.Modules.Projects.Handoffs.Data.ReviewHandoffData,
    ) {
        return requestData<App.Modules.Projects.Handoffs.Data.DealHandoffDetailData>(() =>
            crmHttpClient.post(`/api/internal/handoffs/${handoffId}/request-changes`, payload),
        );
    },
    rejectHandoff(
        handoffId: string,
        payload: App.Modules.Projects.Handoffs.Data.ReviewHandoffData,
    ) {
        return requestData<App.Modules.Projects.Handoffs.Data.DealHandoffDetailData>(() =>
            crmHttpClient.post(`/api/internal/handoffs/${handoffId}/reject`, payload),
        );
    },
    approveHandoff(
        handoffId: string,
        payload: UndefinedAsOptional<App.Modules.Projects.Handoffs.Data.ApproveHandoffData>,
    ) {
        return requestData<App.Modules.Projects.Handoffs.Data.DealHandoffDetailData>(() =>
            crmHttpClient.post(`/api/internal/handoffs/${handoffId}/approve`, payload),
        );
    },
    fetchTemplates(includeInactive = false) {
        return requestData<{
            templates: App.Modules.Projects.Templates.Data.ProjectTemplateSummaryData[];
        }>(() =>
            crmHttpClient.get('/api/internal/project-templates', {
                params: includeInactive ? { include_inactive: 1 } : undefined,
            }),
        );
    },

    // Projects
    createProject(payload: App.Modules.Projects.Projects.Data.CreateProjectData) {
        return requestData<App.Modules.Projects.Projects.Data.ProjectDetailData>(() =>
            crmHttpClient.post('/api/internal/projects', payload),
        );
    },
    updateProject(
        projectId: string,
        payload: UndefinedAsOptional<App.Modules.Projects.Projects.Data.UpdateProjectData>,
    ) {
        return requestData<App.Modules.Projects.Projects.Data.ProjectDetailData>(() =>
            crmHttpClient.patch(`/api/internal/projects/${projectId}`, payload),
        );
    },
    deleteProject(projectId: string) {
        return requestData<{ deleted: boolean }>(() =>
            crmHttpClient.delete(`/api/internal/projects/${projectId}`),
        );
    },
    manageStages(
        projectId: string,
        payload: App.Modules.Projects.Projects.Data.ManageProjectStagesData,
    ) {
        return requestData<{
            stages: App.Modules.Projects.Projects.Data.ProjectStageData[];
        }>(() => crmHttpClient.put(`/api/internal/projects/${projectId}/stages`, payload));
    },
    grantProjectCollaborator(
        projectId: string,
        payload: App.Modules.Core.Rbac.Data.GrantCollaboratorData,
    ) {
        return requestData<App.Modules.Core.Rbac.Data.EntityCollaboratorData>(() =>
            crmHttpClient.post(`/api/internal/projects/${projectId}/collaborators`, payload),
        );
    },
    revokeProjectCollaborator(projectId: string, userId: string) {
        return requestData<{ revoked: boolean }>(() =>
            crmHttpClient.delete(`/api/internal/projects/${projectId}/collaborators/${userId}`),
        );
    },

    // Tasks
    createTask(payload: App.Modules.Projects.Tasks.Data.CreateTaskData) {
        return requestData<App.Modules.Projects.Tasks.Data.TaskData>(() =>
            crmHttpClient.post('/api/internal/tasks', payload),
        );
    },
    updateTask(
        taskId: string,
        payload: UndefinedAsOptional<App.Modules.Projects.Tasks.Data.UpdateTaskData>,
    ) {
        return requestData<App.Modules.Projects.Tasks.Data.TaskData>(() =>
            crmHttpClient.patch(`/api/internal/tasks/${taskId}`, payload),
        );
    },
    moveTask(taskId: string, payload: App.Modules.Projects.Tasks.Data.MoveTaskData) {
        return requestData<App.Modules.Projects.Tasks.Data.TaskData>(() =>
            crmHttpClient.patch(`/api/internal/tasks/${taskId}/move`, payload),
        );
    },
    deleteTask(taskId: string, expectedVersion?: number | null) {
        return requestData<{ deleted: boolean }>(() =>
            crmHttpClient.delete(`/api/internal/tasks/${taskId}`, {
                data: expectedVersion ? { expected_version: expectedVersion } : undefined,
            }),
        );
    },
    toggleWatcher(
        taskId: string,
        payload: App.Modules.Projects.Tasks.Data.ToggleTaskWatcherData = {
            user_id: null,
            watching: null,
        },
    ) {
        return requestData<App.Modules.Projects.Tasks.Data.TaskData>(() =>
            crmHttpClient.post(`/api/internal/tasks/${taskId}/watchers`, payload),
        );
    },
};
