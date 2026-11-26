<?php

namespace App\Modules\Crm\Leads\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Leads\Actions\ImportLeadsAction;
use App\Modules\Crm\Leads\Actions\ParseLeadSpreadsheetAction;
use App\Modules\Crm\Leads\Data\CommitLeadImportData;
use App\Modules\Crm\Leads\Data\ImportLeadRowPayloadData;
use App\Modules\Crm\Leads\Data\ImportLeadsPreviewData;
use App\Modules\Crm\Leads\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class LeadImportController extends Controller
{
    public function template(Request $request): StreamedResponse
    {
        Gate::forUser($request->user())->authorize('create', Lead::class);

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return response()->streamDownload(static function (): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'first_name',
                'last_name',
                'email',
                'phone',
                'job_title',
                'company_name',
                'website',
                'source',
                'estimated_value',
                'currency',
            ]);
            fputcsv($handle, [
                'Alex',
                'Rivera',
                'alex.rivera@example.com',
                '+1-415-555-0190',
                'VP Operations',
                'Northwind Logistics',
                'https://northwind.example',
                'outbound',
                '45000',
                'USD',
            ]);
            fclose($handle);
        }, 'leads-import-template.csv', $headers);
    }

    public function preview(
        Request $request,
        ParseLeadSpreadsheetAction $parseSpreadsheet,
        ImportLeadsAction $importLeads,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('create', Lead::class);

        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'column_mapping' => ['nullable'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $columnMappingOverride = $this->parseColumnMappingOverride($request->input('column_mapping'));

        $parsed = $parseSpreadsheet->execute($file, $columnMappingOverride);

        /** @var User $actor */
        $actor = $request->user();
        $dryRunResult = $importLeads->execute($actor, $parsed['rows'], dryRun: true);

        $preview = new ImportLeadsPreviewData(
            headers: $parsed['headers'],
            detected_mapping: $parsed['detected_mapping'],
            mappable_fields: ParseLeadSpreadsheetAction::MAPPABLE_FIELDS,
            total_rows: $dryRunResult->total_rows,
            ready_count: $dryRunResult->ready_count,
            duplicate_count: $dryRunResult->duplicate_count,
            invalid_count: $dryRunResult->invalid_count,
            rows: $dryRunResult->rows,
        );

        return response()->json($preview->toArray(), 200);
    }

    public function commit(
        Request $request,
        ImportLeadsAction $importLeads,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('create', Lead::class);

        $data = CommitLeadImportData::validateAndCreate($request->all());

        /** @var User $actor */
        $actor = $request->user();

        $rawRows = array_map(
            static fn (ImportLeadRowPayloadData $row): array => [
                'first_name' => $row->first_name,
                'last_name' => $row->last_name,
                'email' => $row->email,
                'phone' => $row->phone,
                'job_title' => $row->job_title,
                'company_name' => $row->company_name,
                'website' => $row->website,
                'source' => $row->source,
                'estimated_value_cents' => $row->estimated_value_cents,
                'currency' => $row->currency,
                'custom_fields' => $row->custom_fields,
            ],
            $data->rows,
        );

        $result = $importLeads->execute($actor, $rawRows, dryRun: false);

        $statusCode = $result->inserted_count > 0 ? 201 : 200;

        return response()->json($result->toArray(), $statusCode);
    }

    /**
     * @return array<string, string|null>
     */
    protected function parseColumnMappingOverride(mixed $rawMapping): array
    {
        if ($rawMapping === null || $rawMapping === '') {
            return [];
        }

        if (is_string($rawMapping)) {
            try {
                $decoded = json_decode($rawMapping, true, 16, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    'column_mapping' => ['Invalid column_mapping JSON payload.'],
                ]);
            }

            $rawMapping = $decoded;
        }

        if (! is_array($rawMapping)) {
            throw ValidationException::withMessages([
                'column_mapping' => ['column_mapping must be a key-value map.'],
            ]);
        }

        $sanitized = [];
        foreach ($rawMapping as $field => $header) {
            if (! is_string($field)) {
                continue;
            }

            $sanitized[$field] = is_string($header) ? $header : null;
        }

        return $sanitized;
    }
}
