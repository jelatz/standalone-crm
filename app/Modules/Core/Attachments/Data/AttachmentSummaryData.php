<?php

namespace App\Modules\Core\Attachments\Data;

use App\Modules\Core\Attachments\Enums\AttachmentScanStatus;
use App\Modules\Core\Attachments\Models\Attachment;
use App\Modules\Core\Attachments\Models\AttachmentLink;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class AttachmentSummaryData extends Data
{
    public function __construct(
        public string $id,
        public string $original_filename,
        public string $mime_type,
        public int $size_bytes,
        public AttachmentScanStatus $scan_status,
        public string $uploaded_by_id,
        public ?string $uploaded_by_name,
        public string $created_at,
        public string $download_url,
    ) {}

    public static function fromLink(AttachmentLink $link): self
    {
        /** @var Attachment $attachment */
        $attachment = $link->attachment;
        $uploader = $attachment->relationLoaded('uploadedBy') ? $attachment->uploadedBy : null;

        return new self(
            id: (string) $attachment->id,
            original_filename: $attachment->original_filename,
            mime_type: $attachment->mime_type,
            size_bytes: $attachment->size_bytes,
            scan_status: $attachment->scan_status,
            uploaded_by_id: (string) $attachment->uploaded_by_id,
            uploaded_by_name: $uploader?->name,
            created_at: $attachment->created_at?->toIso8601String() ?? now()->toIso8601String(),
            download_url: route('attachments.download', [
                'attachment' => (string) $attachment->id,
                'context_type' => $link->attachable_type,
                'context_id' => (string) $link->attachable_id,
            ], false),
        );
    }
}
