import axios, { type AxiosError } from 'axios';
import {
    ApiRequestError,
    crmHttpClient,
    extractFirstFieldErrors,
    mapCrmApiError,
} from '@/modules/crm/api/crmApi';

export { ApiRequestError, extractFirstFieldErrors };

export class SopConflictError extends ApiRequestError {
    public readonly currentSha256: string;
    public readonly document: App.Modules.Core.Sops.Data.SopDocumentData;

    public constructor(
        message: string,
        currentSha256: string,
        document: App.Modules.Core.Sops.Data.SopDocumentData,
    ) {
        super(message, 409);
        this.name = 'SopConflictError';
        this.currentSha256 = currentSha256;
        this.document = document;
    }
}

interface SopConflictPayload {
    message?: string;
    current_sha256?: string;
    document?: App.Modules.Core.Sops.Data.SopDocumentData;
}

async function requestSopData<T>(fn: () => Promise<{ data: T }>): Promise<T> {
    try {
        const response = await fn();
        return response.data;
    } catch (error) {
        if (axios.isAxiosError(error)) {
            const axiosErr = error as AxiosError<SopConflictPayload>;
            const status = axiosErr.response?.status;
            const data = axiosErr.response?.data;

            if (status === 409 && data?.document) {
                throw new SopConflictError(
                    data.message ?? 'This SOP was modified on disk since you opened it.',
                    String(data.current_sha256 ?? data.document.sha256 ?? ''),
                    data.document,
                );
            }
        }

        throw mapCrmApiError(error);
    }
}

export const sopsApi = {
    readSop(module: string, slug: string) {
        return requestSopData<App.Modules.Core.Sops.Data.SopDocumentData>(() =>
            crmHttpClient.get(
                `/api/internal/admin/sops/${encodeURIComponent(module)}/${encodeURIComponent(slug)}`,
            ),
        );
    },

    createSop(payload: App.Modules.Core.Sops.Data.CreateSopData) {
        return requestSopData<App.Modules.Core.Sops.Data.SopDocumentData>(() =>
            crmHttpClient.post('/api/internal/admin/sops', payload),
        );
    },

    updateSop(
        module: string,
        slug: string,
        payload: App.Modules.Core.Sops.Data.UpdateSopData,
    ) {
        return requestSopData<App.Modules.Core.Sops.Data.SopDocumentData>(() =>
            crmHttpClient.put(
                `/api/internal/admin/sops/${encodeURIComponent(module)}/${encodeURIComponent(slug)}`,
                payload,
            ),
        );
    },
};
