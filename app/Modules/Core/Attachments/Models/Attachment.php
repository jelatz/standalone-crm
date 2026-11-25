<?php

namespace App\Modules\Core\Attachments\Models;

use App\Modules\Core\Attachments\Enums\AttachmentScanStatus;
use App\Modules\Core\Users\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attachment extends Model
{
    use HasUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'attachments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uploaded_by_id',
        'disk',
        'bucket',
        'object_key',
        'original_filename',
        'mime_type',
        'size_bytes',
        'sha256',
        'scan_status',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'scan_status' => AttachmentScanStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /**
     * @return HasMany<AttachmentLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(AttachmentLink::class, 'attachment_id');
    }
}
