<?php

namespace App\Modules\Crm\Deals\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\CustomFields\Data\CustomFieldDefinitionData;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use App\Modules\Crm\Pipelines\Data\PipelineData;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use Illuminate\Support\Facades\Gate;

class LoadDealContextAction
{
    /**
     * @return list<PipelineData>
     */
    public function activePipelines(): array
    {
        return Pipeline::query()
            ->with('stages.requiredCustomFields')
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(static fn (Pipeline $pipeline): PipelineData => PipelineData::fromModel($pipeline))
            ->values()
            ->all();
    }

    /**
     * @return list<CustomFieldDefinitionData>
     */
    public function dealCustomFieldDefinitions(?string $pipelineId = null, bool $filterableOnly = false): array
    {
        return CustomFieldDefinition::query()
            ->where('entity_type', CustomFieldEntityTypeEnum::Deal->value)
            ->where('is_active', true)
            ->when(
                $pipelineId !== null,
                static fn ($q) => $q->where(static function ($sub) use ($pipelineId): void {
                    $sub->whereNull('pipeline_id')->orWhere('pipeline_id', $pipelineId);
                }),
                static fn ($q) => $q->whereNull('pipeline_id'),
            )
            ->when($filterableOnly, static fn ($q) => $q->where('is_filterable', true))
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->map(static fn (CustomFieldDefinition $definition): CustomFieldDefinitionData => CustomFieldDefinitionData::fromModel($definition))
            ->values()
            ->all();
    }

    /**
     * Load minimal silo-scoped contacts list gated by ContactPolicy::viewAny.
     *
     * @return list<array{id: string, full_name: string, primary_email: ?string, company_id: ?string}>
     */
    public function visibleContactSummaries(User $viewer): array
    {
        if (! Gate::forUser($viewer)->allows('viewAny', Contact::class)) {
            return [];
        }

        return Contact::query()
            ->visibleTo($viewer)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'full_name', 'primary_email', 'company_id'])
            ->map(static fn (Contact $contact): array => [
                'id' => (string) $contact->id,
                'full_name' => $contact->full_name ?? trim($contact->first_name.' '.$contact->last_name),
                'primary_email' => $contact->primary_email,
                'company_id' => $contact->company_id !== null ? (string) $contact->company_id : null,
            ])
            ->values()
            ->all();
    }
}
