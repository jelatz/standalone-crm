<?php

namespace App\Modules\Projects\Tasks\Models;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Timeline\Models\Activity;
use App\Modules\Projects\Projects\Models\Project;
use App\Modules\Projects\Projects\Models\ProjectStage;
use App\Modules\Projects\Tasks\Enums\TaskPriorityEnum;
use App\Modules\Projects\Tasks\Enums\TaskStatusEnum;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'tasks';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'priority' => TaskPriorityEnum::Medium->value,
        'status' => TaskStatusEnum::Todo->value,
        'board_position' => 65536.0,
        'version' => 1,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'project_stage_id',
        'parent_task_id',
        'linkable_type',
        'linkable_id',
        'creator_id',
        'assignee_id',
        'title',
        'description_json',
        'priority',
        'status',
        'board_position',
        'due_at',
        'completed_at',
        'estimated_minutes',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'description_json' => 'array',
            'priority' => TaskPriorityEnum::class,
            'status' => TaskStatusEnum::class,
            'board_position' => 'float',
            'due_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'estimated_minutes' => 'integer',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return BelongsTo<ProjectStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProjectStage::class, 'project_stage_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id')->orderBy('board_position');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_watchers', 'task_id', 'user_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'source');
    }
}
