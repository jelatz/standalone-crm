import axios, { AxiosError, type AxiosInstance } from 'axios';

export type FieldValidationErrors = Record<string, string[]>;

export class ApiRequestError extends Error {
    public readonly status: number;

    public constructor(message: string, status: number) {
        super(message);
        this.name = 'ApiRequestError';
        this.status = status;
    }
}

export class ForbiddenRequestError extends ApiRequestError {
    public constructor(message: string = 'You do not have permission to perform this action.') {
        super(message, 403);
        this.name = 'ForbiddenRequestError';
    }
}

export class ValidationRequestError extends ApiRequestError {
    public readonly errors: FieldValidationErrors;

    public constructor(message: string, errors: FieldValidationErrors = {}, status: number = 422) {
        super(message, status);
        this.name = 'ValidationRequestError';
        this.errors = errors;
    }
}

export class OptimisticLockConflictError<TEntity = Record<string, unknown>> extends ApiRequestError {
    public readonly currentVersion: number;
    public readonly entity: TEntity;

    public constructor(message: string, currentVersion: number, entity: TEntity) {
        super(message, 409);
        this.name = 'OptimisticLockConflictError';
        this.currentVersion = currentVersion;
        this.entity = entity;
    }
}

export class StageGateValidationError extends ValidationRequestError {
    public readonly missingCoreFields: string[];
    public readonly missingCustomFields: string[];

    public constructor(
        message: string,
        errors: FieldValidationErrors,
        missingCoreFields: string[],
        missingCustomFields: string[],
    ) {
        super(message, errors, 422);
        this.name = 'StageGateValidationError';
        this.missingCoreFields = missingCoreFields;
        this.missingCustomFields = missingCustomFields;
    }
}

interface ConflictPayload {
    message?: string;
    current_version?: number;
    entity?: Record<string, unknown>;
}

interface ValidationPayload {
    message?: string;
    errors?: Record<string, string[] | string>;
    missing_core_fields?: string[];
    missing_custom_fields?: string[];
}

function readCsrfToken(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
    if (meta?.content) {
        return meta.content;
    }

    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : null;
}

const httpClient: AxiosInstance = axios.create({
    withCredentials: true,
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

httpClient.interceptors.request.use((config) => {
    const token = readCsrfToken();
    if (token && config.headers) {
        config.headers['X-CSRF-TOKEN'] = token;
    }

    return config;
});

function normalizeErrorsMap(rawErrors?: Record<string, string[] | string>): FieldValidationErrors {
    if (!rawErrors || typeof rawErrors !== 'object') {
        return {};
    }

    const normalized: FieldValidationErrors = {};
    for (const [key, value] of Object.entries(rawErrors)) {
        if (Array.isArray(value)) {
            normalized[key] = value.map(String);
        } else if (typeof value === 'string') {
            normalized[key] = [value];
        }
    }

    return normalized;
}

export function extractFirstFieldErrors(errors: FieldValidationErrors): Record<string, string> {
    const mapped: Record<string, string> = {};
    for (const [key, messages] of Object.entries(errors)) {
        if (messages.length > 0) {
            mapped[key] = messages[0];
        }
    }
    return mapped;
}

export function mapCrmApiError(error: unknown): Error {
    if (error instanceof ApiRequestError) {
        return error;
    }

    if (axios.isAxiosError(error)) {
        const axiosErr = error as AxiosError<ConflictPayload & ValidationPayload>;
        const status = axiosErr.response?.status ?? 500;
        const data = axiosErr.response?.data;

        if (status === 409) {
            return new OptimisticLockConflictError(
                data?.message ?? 'This record was modified by another user. Refresh and try again.',
                Number(data?.current_version ?? 0),
                data?.entity ?? {},
            );
        }

        if (status === 422) {
            const errors = normalizeErrorsMap(data?.errors);
            const missingCore = Array.isArray(data?.missing_core_fields)
                ? data.missing_core_fields.map(String)
                : [];
            const missingCustom = Array.isArray(data?.missing_custom_fields)
                ? data.missing_custom_fields.map(String)
                : [];

            if (missingCore.length > 0 || missingCustom.length > 0) {
                return new StageGateValidationError(
                    data?.message ?? 'Complete all required stage gate fields before moving to this stage.',
                    errors,
                    missingCore,
                    missingCustom,
                );
            }

            return new ValidationRequestError(
                data?.message ?? 'Validation failed. Review the highlighted fields.',
                errors,
                422,
            );
        }

        if (status === 403) {
            return new ForbiddenRequestError(
                data?.message ?? 'You do not have permission to perform this action.',
            );
        }

        return new ApiRequestError(
            data?.message ?? axiosErr.message ?? 'An unexpected request error occurred.',
            status,
        );
    }

    if (error instanceof Error) {
        return error;
    }

    return new ApiRequestError('An unexpected request error occurred.', 500);
}

async function requestData<T>(fn: () => Promise<{ data: T }>): Promise<T> {
    try {
        const response = await fn();
        return response.data;
    } catch (error) {
        throw mapCrmApiError(error);
    }
}

export interface TimelineQueryParams {
    linkable_type: 'company' | 'contact' | 'deal';
    linkable_id: string;
    cursor?: string | null;
    limit?: number;
}

export const crmApi = {
    // Companies
    createCompany(payload: App.Modules.Crm.Companies.Data.UpsertCompanyData) {
        return requestData<App.Modules.Crm.Companies.Data.CompanyDetailData>(() =>
            httpClient.post('/api/internal/crm/companies', payload),
        );
    },
    updateCompany(companyId: string, payload: App.Modules.Crm.Companies.Data.UpsertCompanyData) {
        return requestData<App.Modules.Crm.Companies.Data.CompanyDetailData>(() =>
            httpClient.patch(`/api/internal/crm/companies/${companyId}`, payload),
        );
    },
    deleteCompany(companyId: string) {
        return requestData<{ deleted: boolean }>(() =>
            httpClient.delete(`/api/internal/crm/companies/${companyId}`),
        );
    },
    grantCompanyCollaborator(companyId: string, payload: App.Modules.Core.Rbac.Data.GrantCollaboratorData) {
        return requestData<App.Modules.Core.Rbac.Data.EntityCollaboratorData>(() =>
            httpClient.post(`/api/internal/crm/companies/${companyId}/collaborators`, payload),
        );
    },
    revokeCompanyCollaborator(companyId: string, userId: string) {
        return requestData<{ revoked: boolean }>(() =>
            httpClient.delete(`/api/internal/crm/companies/${companyId}/collaborators/${userId}`),
        );
    },

    // Contacts
    createContact(payload: App.Modules.Crm.Contacts.Data.UpsertContactData) {
        return requestData<App.Modules.Crm.Contacts.Data.ContactDetailData>(() =>
            httpClient.post('/api/internal/crm/contacts', payload),
        );
    },
    updateContact(contactId: string, payload: App.Modules.Crm.Contacts.Data.UpsertContactData) {
        return requestData<App.Modules.Crm.Contacts.Data.ContactDetailData>(() =>
            httpClient.patch(`/api/internal/crm/contacts/${contactId}`, payload),
        );
    },
    deleteContact(contactId: string) {
        return requestData<{ deleted: boolean }>(() =>
            httpClient.delete(`/api/internal/crm/contacts/${contactId}`),
        );
    },
    grantContactCollaborator(contactId: string, payload: App.Modules.Core.Rbac.Data.GrantCollaboratorData) {
        return requestData<App.Modules.Core.Rbac.Data.EntityCollaboratorData>(() =>
            httpClient.post(`/api/internal/crm/contacts/${contactId}/collaborators`, payload),
        );
    },
    revokeContactCollaborator(contactId: string, userId: string) {
        return requestData<{ revoked: boolean }>(() =>
            httpClient.delete(`/api/internal/crm/contacts/${contactId}/collaborators/${userId}`),
        );
    },

    // Deals
    createDeal(payload: App.Modules.Crm.Deals.Data.CreateDealData) {
        return requestData<App.Modules.Crm.Deals.Data.DealDetailData>(() =>
            httpClient.post('/api/internal/crm/deals', payload),
        );
    },
    updateDeal(dealId: string, payload: App.Modules.Crm.Deals.Data.UpdateDealData) {
        return requestData<App.Modules.Crm.Deals.Data.DealDetailData>(() =>
            httpClient.patch(`/api/internal/crm/deals/${dealId}`, payload),
        );
    },
    moveDealStage(dealId: string, payload: App.Modules.Crm.Deals.Data.MoveDealStageData) {
        return requestData<App.Modules.Crm.Deals.Data.DealDetailData>(() =>
            httpClient.patch(`/api/internal/crm/deals/${dealId}/move-stage`, payload),
        );
    },
    deleteDeal(dealId: string) {
        return requestData<{ deleted: boolean }>(() =>
            httpClient.delete(`/api/internal/crm/deals/${dealId}`),
        );
    },
    syncDealContacts(dealId: string, payload: App.Modules.Crm.Deals.Data.SyncDealContactsData) {
        return requestData<{ contacts: App.Modules.Crm.Deals.Data.DealStakeholderData[] }>(() =>
            httpClient.put(`/api/internal/crm/deals/${dealId}/contacts`, payload),
        );
    },
    grantDealCollaborator(dealId: string, payload: App.Modules.Core.Rbac.Data.GrantCollaboratorData) {
        return requestData<App.Modules.Core.Rbac.Data.EntityCollaboratorData>(() =>
            httpClient.post(`/api/internal/crm/deals/${dealId}/collaborators`, payload),
        );
    },
    revokeDealCollaborator(dealId: string, userId: string) {
        return requestData<{ revoked: boolean }>(() =>
            httpClient.delete(`/api/internal/crm/deals/${dealId}/collaborators/${userId}`),
        );
    },

    // Collaborators generic helper
    grantCollaborator(
        entityType: 'company' | 'contact' | 'deal',
        entityId: string,
        payload: App.Modules.Core.Rbac.Data.GrantCollaboratorData,
    ) {
        if (entityType === 'company') {
            return crmApi.grantCompanyCollaborator(entityId, payload);
        }
        if (entityType === 'contact') {
            return crmApi.grantContactCollaborator(entityId, payload);
        }
        return crmApi.grantDealCollaborator(entityId, payload);
    },
    revokeCollaborator(
        entityType: 'company' | 'contact' | 'deal',
        entityId: string,
        userId: string,
    ) {
        if (entityType === 'company') {
            return crmApi.revokeCompanyCollaborator(entityId, userId);
        }
        if (entityType === 'contact') {
            return crmApi.revokeContactCollaborator(entityId, userId);
        }
        return crmApi.revokeDealCollaborator(entityId, userId);
    },

    // Notes
    createNote(payload: App.Modules.Crm.Notes.Data.CreateNoteData) {
        return requestData<App.Modules.Crm.Notes.Data.NoteData>(() =>
            httpClient.post('/api/internal/crm/notes', payload),
        );
    },
    updateNote(noteId: string, payload: App.Modules.Crm.Notes.Data.UpdateNoteData) {
        return requestData<App.Modules.Crm.Notes.Data.NoteData>(() =>
            httpClient.patch(`/api/internal/crm/notes/${noteId}`, payload),
        );
    },
    deleteNote(noteId: string) {
        return requestData<{ deleted: boolean }>(() =>
            httpClient.delete(`/api/internal/crm/notes/${noteId}`),
        );
    },
    togglePinNote(noteId: string, isPinned?: boolean) {
        return requestData<App.Modules.Crm.Notes.Data.NoteData>(() =>
            httpClient.patch(
                `/api/internal/crm/notes/${noteId}/pin`,
                isPinned === undefined ? {} : { is_pinned: isPinned },
            ),
        );
    },

    // Timeline
    fetchTimeline(params: TimelineQueryParams) {
        return requestData<App.Modules.Crm.Timeline.Data.TimelinePageData>(() =>
            httpClient.get('/api/internal/crm/timeline', {
                params: {
                    linkable_type: params.linkable_type,
                    linkable_id: params.linkable_id,
                    ...(params.cursor ? { cursor: params.cursor } : {}),
                    ...(params.limit ? { limit: params.limit } : {}),
                },
            }),
        );
    },

    // Pipelines & Stages
    createPipeline(payload: App.Modules.Crm.Pipelines.Data.UpsertPipelineData) {
        return requestData<App.Modules.Crm.Pipelines.Data.PipelineData>(() =>
            httpClient.post('/api/internal/crm/pipelines', payload),
        );
    },
    updatePipeline(pipelineId: string, payload: App.Modules.Crm.Pipelines.Data.UpsertPipelineData) {
        return requestData<App.Modules.Crm.Pipelines.Data.PipelineData>(() =>
            httpClient.patch(`/api/internal/crm/pipelines/${pipelineId}`, payload),
        );
    },
    createPipelineStage(pipelineId: string, payload: App.Modules.Crm.Pipelines.Data.UpsertPipelineStageData) {
        return requestData<App.Modules.Crm.Pipelines.Data.PipelineStageData>(() =>
            httpClient.post(`/api/internal/crm/pipelines/${pipelineId}/stages`, payload),
        );
    },
    updatePipelineStage(
        pipelineId: string,
        stageId: string,
        payload: App.Modules.Crm.Pipelines.Data.UpsertPipelineStageData,
    ) {
        return requestData<App.Modules.Crm.Pipelines.Data.PipelineStageData>(() =>
            httpClient.patch(`/api/internal/crm/pipelines/${pipelineId}/stages/${stageId}`, payload),
        );
    },
    deletePipelineStage(pipelineId: string, stageId: string) {
        return requestData<{ deleted: boolean }>(() =>
            httpClient.delete(`/api/internal/crm/pipelines/${pipelineId}/stages/${stageId}`),
        );
    },

    // Custom Field Definitions
    createCustomField(payload: App.Modules.Crm.CustomFields.Data.UpsertCustomFieldDefinitionData) {
        return requestData<App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData>(() =>
            httpClient.post('/api/internal/crm/custom-fields', payload),
        );
    },
    updateCustomField(
        definitionId: string,
        payload: App.Modules.Crm.CustomFields.Data.UpsertCustomFieldDefinitionData,
    ) {
        return requestData<App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData>(() =>
            httpClient.patch(`/api/internal/crm/custom-fields/${definitionId}`, payload),
        );
    },
    deleteCustomField(definitionId: string) {
        return requestData<{ deleted: boolean }>(() =>
            httpClient.delete(`/api/internal/crm/custom-fields/${definitionId}`),
        );
    },
};
