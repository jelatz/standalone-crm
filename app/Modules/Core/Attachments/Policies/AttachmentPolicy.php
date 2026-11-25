<?php

namespace App\Modules\Core\Attachments\Policies;

use App\Modules\Core\Attachments\Enums\AttachmentScanStatus;
use App\Modules\Core\Attachments\Models\Attachment;
use App\Modules\Core\Attachments\Models\AttachmentLink;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class AttachmentPolicy
{
    public function create(User $user): bool
    {
        return ! $user->trashed()
            && $user->status === UserStatusEnum::Active
            && $user->roles()->exists();
    }

    public function view(User $viewer, Attachment $attachment, Model $contextModel): bool
    {
        if ($viewer->trashed() || $viewer->status !== UserStatusEnum::Active) {
            return false;
        }

        if ($attachment->scan_status !== AttachmentScanStatus::Clean) {
            return false;
        }

        $contextType = $contextModel->getMorphClass();
        $contextId = (string) $contextModel->getKey();

        if ($contextType === '' || $contextId === '') {
            return false;
        }

        $linkExists = AttachmentLink::query()
            ->where('attachment_id', $attachment->id)
            ->where('attachable_type', $contextType)
            ->where('attachable_id', $contextId)
            ->exists();

        if (! $linkExists) {
            return false;
        }

        return Gate::forUser($viewer)->allows('view', $contextModel);
    }
}
