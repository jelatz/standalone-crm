<?php

namespace App\Modules\Crm\CustomFields\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\CustomFields\Actions\DeleteCustomFieldDefinitionAction;
use App\Modules\Crm\CustomFields\Actions\UpsertCustomFieldDefinitionAction;
use App\Modules\Crm\CustomFields\Data\CustomFieldDefinitionData;
use App\Modules\Crm\CustomFields\Data\UpsertCustomFieldDefinitionData;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CustomFieldController extends Controller
{
    public function store(
        Request $request,
        UpsertCustomFieldDefinitionAction $upsertCustomField,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('create', CustomFieldDefinition::class);

        $data = UpsertCustomFieldDefinitionData::validateAndCreate($request->all());
        $definition = $upsertCustomField->execute($data);

        return response()->json(CustomFieldDefinitionData::fromModel($definition)->toArray(), 201);
    }

    public function update(
        CustomFieldDefinition $customFieldDefinition,
        Request $request,
        UpsertCustomFieldDefinitionAction $upsertCustomField,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('update', $customFieldDefinition);

        $data = UpsertCustomFieldDefinitionData::validateAndCreate($request->all());
        $updated = $upsertCustomField->execute($data, $customFieldDefinition);

        return response()->json(CustomFieldDefinitionData::fromModel($updated)->toArray(), 200);
    }

    public function destroy(
        CustomFieldDefinition $customFieldDefinition,
        Request $request,
        DeleteCustomFieldDefinitionAction $deleteCustomField,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('delete', $customFieldDefinition);

        $deleteCustomField->execute($customFieldDefinition);

        return response()->json(['deleted' => true], 200);
    }
}
