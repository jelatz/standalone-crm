<?php

namespace App\Modules\Core\Sops\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Sops\Actions\CreateSopAction;
use App\Modules\Core\Sops\Actions\ListSopsAction;
use App\Modules\Core\Sops\Actions\ReadSopAction;
use App\Modules\Core\Sops\Actions\UpdateSopAction;
use App\Modules\Core\Sops\Data\CreateSopData;
use App\Modules\Core\Sops\Data\SopModuleGroupData;
use App\Modules\Core\Sops\Data\UpdateSopData;
use App\Modules\Core\Sops\Exceptions\SopConflictException;
use App\Modules\Core\Sops\Exceptions\SopNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SopManagementController extends Controller
{
    public function index(
        Request $request,
        ListSopsAction $listSops,
        ReadSopAction $readSop,
    ): InertiaResponse {
        Gate::forUser($request->user())->authorize('admin.sops.manage');

        $modules = $listSops->execute();
        $initialDocument = null;

        foreach ($modules as $group) {
            if ($group->sops !== []) {
                $firstSop = $group->sops[0];
                $initialDocument = $readSop->execute($firstSop->module, $firstSop->slug);
                break;
            }
        }

        return Inertia::render('core/pages/SopsPage', [
            'moduleTitle' => 'SOPs',
            'modules' => array_map(
                static fn (SopModuleGroupData $group): array => $group->toArray(),
                $modules,
            ),
            'initialDocument' => $initialDocument?->toArray(),
        ]);
    }

    public function show(
        string $module,
        string $slug,
        Request $request,
        ReadSopAction $readSop,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('admin.sops.manage');

        try {
            $document = $readSop->execute($module, $slug);
        } catch (SopNotFoundException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json($document->toArray());
    }

    public function store(
        Request $request,
        CreateSopAction $createSop,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('admin.sops.manage');

        $data = CreateSopData::validateAndCreate($this->extractRawPayload($request));
        $document = $createSop->execute($data);

        return response()->json($document->toArray(), 201);
    }

    public function update(
        string $module,
        string $slug,
        Request $request,
        UpdateSopAction $updateSop,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('admin.sops.manage');

        $data = UpdateSopData::validateAndCreate($this->extractRawPayload($request));

        try {
            $document = $updateSop->execute($module, $slug, $data);
        } catch (SopNotFoundException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        } catch (SopConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'current_sha256' => $exception->currentDocument->sha256,
                'document' => $exception->currentDocument->toArray(),
            ], 409);
        }

        return response()->json($document->toArray());
    }

    /**
     * Read raw JSON payload when present so Markdown content preserves trailing newlines and whitespace.
     *
     * @return array<string, mixed>
     */
    private function extractRawPayload(Request $request): array
    {
        if ($request->isJson()) {
            $decoded = json_decode((string) $request->getContent(), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->all();
    }
}
