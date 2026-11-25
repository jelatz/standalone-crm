<?php

namespace App\Modules\Core\AsyncJobs\Models;

use App\Modules\Core\AsyncJobs\Enums\AsyncJobStatus;
use App\Modules\Core\Users\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AsyncJobRun extends Model
{
    use HasUuidV7;

    protected $table = 'async_job_runs';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'job_class',
        'queue',
        'subject_type',
        'subject_id',
        'triggered_by_id',
        'status',
        'attempt',
        'idempotency_key',
        'payload_summary',
        'error_code',
        'error_message',
        'queued_at',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AsyncJobStatus::class,
            'attempt' => 'integer',
            'payload_summary' => 'array',
            'queued_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
