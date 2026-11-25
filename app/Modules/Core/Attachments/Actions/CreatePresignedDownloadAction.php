<?php

namespace App\Modules\Core\Attachments\Actions;

use App\Modules\Core\Attachments\Enums\AttachmentScanStatus;
use App\Modules\Core\Attachments\Models\Attachment;
use App\Modules\Core\Attachments\Models\AttachmentLink;
use App\Modules\Core\Users\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class CreatePresignedDownloadAction
{
    public function execute(User $viewer, Attachment $attachment, string $contextType, string $contextId): string
    {
        if ($attachment->scan_status === AttachmentScanStatus::Quarantined) {
            throw new AuthorizationException('This attachment is quarantined and cannot be downloaded.');
        }

        if (! Str::isUuid($contextId)) {
            throw new AuthorizationException('Attachment is not linked to the requested context.');
        }

        $linkExists = AttachmentLink::query()
            ->where('attachment_id', $attachment->id)
            ->where('attachable_type', $contextType)
            ->where('attachable_id', $contextId)
            ->exists();

        if (! $linkExists) {
            throw new AuthorizationException('Attachment is not linked to the requested context.');
        }

        $modelClass = Relation::getMorphedModel($contextType);

        if ($modelClass === null || ! is_subclass_of($modelClass, Model::class)) {
            throw new AuthorizationException('Invalid attachment context type.');
        }

        /** @var Model $contextModel */
        $contextModel = $modelClass::query()->findOrFail($contextId);

        Gate::forUser($viewer)->authorize('view', [$attachment, $contextModel]);

        $filesystem = Storage::disk($attachment->disk);

        if (! method_exists($filesystem, 'temporaryUrl')) {
            throw new RuntimeException('Configured attachment disk does not support temporary download URLs.');
        }

        $safeDownloadName = trim((string) preg_replace(
            '/[^A-Za-z0-9._() -]+/',
            '_',
            str_replace(['"', '\\', "\r", "\n", "\0"], '', $attachment->original_filename),
        ));

        if ($safeDownloadName === '' || $safeDownloadName === '.' || $safeDownloadName === '..') {
            $safeDownloadName = 'attachment';
        }

        return (string) $filesystem->temporaryUrl(
            $attachment->object_key,
            now()->addMinutes(5),
            [
                'ResponseContentDisposition' => sprintf('attachment; filename="%s"', $safeDownloadName),
            ],
        );
    }
}
