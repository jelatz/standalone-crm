<?php

namespace App\Modules\Core\Attachments\Actions;

use App\Modules\Core\Attachments\Data\PresignUploadRequestData;
use App\Modules\Core\Attachments\Data\PresignUploadResponseData;
use App\Modules\Core\Attachments\Enums\AttachmentScanStatus;
use App\Modules\Core\Attachments\Models\Attachment;
use App\Modules\Core\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CreatePresignedUploadAction
{
    public function execute(User $uploader, PresignUploadRequestData $data): PresignUploadResponseData
    {
        $trimmedFilename = trim($data->filename);
        $normalizedMime = strtolower(trim($data->mime_type));
        $normalizedSha256 = strtolower(trim($data->sha256));

        $this->validateInput($trimmedFilename, $normalizedMime, $data->size_bytes, $normalizedSha256);

        $attachmentId = (string) Str::uuid7();
        $now = now()->toImmutable();
        $expiresAt = $now->addMinutes(10);

        [$safeOriginalFilename, $sanitizedBasename] = $this->sanitizeFilename($trimmedFilename);
        $objectKey = sprintf(
            'uploads/%s/%s/%s/%s',
            $now->format('Y'),
            $now->format('m'),
            $attachmentId,
            $sanitizedBasename,
        );

        $diskName = 's3';
        $bucket = (string) (config('filesystems.disks.s3.bucket') ?: 'workspace-attachments');
        $filesystem = Storage::disk($diskName);

        if (! method_exists($filesystem, 'temporaryUploadUrl')) {
            throw new RuntimeException('Configured attachment disk does not support temporary upload URLs.');
        }

        /** @var array{url?: mixed, headers?: mixed} $presigned */
        $presigned = $filesystem->temporaryUploadUrl($objectKey, $expiresAt, [
            'ContentType' => $normalizedMime,
        ]);

        $uploadUrl = (string) ($presigned['url'] ?? '');
        if ($uploadUrl === '') {
            throw new RuntimeException('Failed to generate presigned upload URL.');
        }

        $headers = $this->normalizeHeaders(
            is_array($presigned['headers'] ?? null) ? $presigned['headers'] : [],
            $normalizedMime,
        );

        DB::transaction(function () use (
            $attachmentId,
            $uploader,
            $diskName,
            $bucket,
            $objectKey,
            $safeOriginalFilename,
            $normalizedMime,
            $data,
            $normalizedSha256,
            $now,
        ): void {
            $attachment = new Attachment([
                'uploaded_by_id' => $uploader->id,
                'disk' => $diskName,
                'bucket' => $bucket,
                'object_key' => $objectKey,
                'original_filename' => $safeOriginalFilename,
                'mime_type' => $normalizedMime,
                'size_bytes' => $data->size_bytes,
                'sha256' => $normalizedSha256,
                'scan_status' => AttachmentScanStatus::Clean,
                'created_at' => $now,
            ]);
            $attachment->id = $attachmentId;
            $attachment->save();
        });

        return new PresignUploadResponseData(
            attachment_id: $attachmentId,
            upload_url: $uploadUrl,
            headers: $headers,
            object_key: $objectKey,
            expires_at: $expiresAt->toAtomString(),
        );
    }

    protected function validateInput(string $filename, string $mimeType, int $sizeBytes, string $sha256): void
    {
        $errors = [];

        if ($filename === '' || mb_strlen($filename) > 255) {
            $errors['filename'] = ['The filename must be between 1 and 255 characters.'];
        }

        if (! PresignUploadRequestData::isAllowedMimeType($mimeType)) {
            $errors['mime_type'] = ['The selected mime_type is not an allowed file type.'];
        }

        if ($sizeBytes < 1 || $sizeBytes > PresignUploadRequestData::MAX_SIZE_BYTES) {
            $errors['size_bytes'] = ['The size_bytes must be between 1 byte and 50 MB.'];
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            $errors['sha256'] = ['The sha256 must be a valid 64-character hexadecimal digest.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function sanitizeFilename(string $filename): array
    {
        $rawBase = basename(str_replace(['\\', "\0"], ['/', ''], $filename));
        $safeOriginal = $rawBase !== '' && $rawBase !== '.' && $rawBase !== '..'
            ? Str::limit($rawBase, 255, '')
            : 'attachment';

        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '_', $safeOriginal) ?? 'attachment';
        $sanitized = ltrim($sanitized, '._-');
        if ($sanitized === '') {
            $sanitized = 'attachment';
        }

        return [$safeOriginal, Str::limit($sanitized, 180, '')];
    }

    /**
     * @param  array<mixed, mixed>  $rawHeaders
     * @return array<string, string>
     */
    protected function normalizeHeaders(array $rawHeaders, string $mimeType): array
    {
        $headers = [];

        foreach ($rawHeaders as $key => $value) {
            if (is_array($value)) {
                $headers[(string) $key] = implode(', ', array_map('strval', $value));
            } elseif ($value !== null) {
                $headers[(string) $key] = (string) $value;
            }
        }

        $hasContentType = false;
        foreach (array_keys($headers) as $headerName) {
            if (strtolower($headerName) === 'content-type') {
                $hasContentType = true;
                break;
            }
        }

        if (! $hasContentType) {
            $headers['Content-Type'] = $mimeType;
        }

        return $headers;
    }
}
