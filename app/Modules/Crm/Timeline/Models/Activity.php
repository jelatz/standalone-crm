<?php

namespace App\Modules\Crm\Timeline\Models;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Activity extends Model
{
    use HasUuidV7;

    protected $table = 'activities';

    public $timestamps = false;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'visibility_mode' => 'source_policy',
        'metadata' => '{}',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'actor_id',
        'activity_type',
        'source_type',
        'source_id',
        'visibility_mode',
        'summary',
        'metadata',
        'occurred_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activity_type' => ActivityTypeEnum::class,
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<ActivityLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(ActivityLink::class, 'activity_id');
    }
}
