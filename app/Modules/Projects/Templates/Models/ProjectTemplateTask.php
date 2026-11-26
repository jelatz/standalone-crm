<?php

namespace App\Modules\Projects\Templates\Models;

use App\Modules\Projects\Templates\Enums\TemplateAssigneeRoleHintEnum;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectTemplateTask extends Model
{
    use HasUuidV7;

    protected $table = 'project_template_tasks';

    public $timestamps = false;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_template_id',
        'parent_template_task_id',
        'stage_key',
        'title',
        'description_json',
        'relative_due_days',
        'assignee_role_hint',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'description_json' => 'array',
            'relative_due_days' => 'integer',
            'assignee_role_hint' => TemplateAssigneeRoleHintEnum::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProjectTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ProjectTemplate::class, 'project_template_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_template_task_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_template_task_id')->orderBy('sort_order');
    }
}
