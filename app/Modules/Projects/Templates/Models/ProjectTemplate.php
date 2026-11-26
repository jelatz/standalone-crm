<?php

namespace App\Modules\Projects\Templates\Models;

use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectTemplate extends Model
{
    use HasUuidV7;

    protected $table = 'project_templates';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'department_id',
        'default_stages',
        'description',
        'is_active',
        'created_by_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_stages' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return HasMany<ProjectTemplateTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTemplateTask::class, 'project_template_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<DealHandoff, $this>
     */
    public function dealHandoffs(): HasMany
    {
        return $this->hasMany(DealHandoff::class, 'project_template_id');
    }
}
