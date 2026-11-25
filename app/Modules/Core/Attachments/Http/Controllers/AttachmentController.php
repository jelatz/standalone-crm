<?php

namespace App\Modules\Core\Attachments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Attachments\Actions\CreatePresignedDownloadAction;
use App\Modules\Core\Attachments\Actions\CreatePresignedUploadAction;
use App\Modules\Core\Attachments\Data\PresignUploadRequestData;
use App\Modules\Core\Attachments\Http\Requests\DownloadAttachmentRequest;
use App\Modules\Core\Attachments\Models\Attachment;
use App\Modules\Core\Users\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AttachmentController extends Controller
{
    public function presign(
        PresignUploadRequestData $data,
        CreatePresignedUploadAction $createPresignedUpload,
        Request $request,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('create', Attachment::class);

        /** @var User $user */
        $user = $request->user();

        $response = $createPresignedUpload->execute($user, $data);

        return response()->json($response->toArray(), 201);
    }

    public function download(
        Attachment $attachment,
        DownloadAttachmentRequest $request,
        CreatePresignedDownloadAction $createPresignedDownload,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $url = $createPresignedDownload->execute(
            viewer: $user,
            attachment: $attachment,
            contextType: (string) $request->validated('context_type'),
            contextId: (string) $request->validated('context_id'),
        );

        return redirect()->away($url);
    }
}
