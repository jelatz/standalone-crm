<?php

namespace App\Modules\Projects\Projects\Models;

use App\Modules\Core\Rbac\Models\EntityCollaborator;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Timeline\Models\ActivityLink;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Modules\Projects\Projects\Enums\ProjectHealthEnum;
use App\Modules\Projects\Projects\Enums\ProjectStatusEnum;
use App\Modules\Projects\Tasks\Models\Task;
use App\Support\Concerns\HasUuidV7;
use App\Support\Silo\HasSiloVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasSiloVisibility;
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'projects';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ProjectStatusEnum::Planning->value,
        'health' => ProjectHealthEnum::OnTrack->value,
        'custom_fields' => '{}',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'company_id',
        'primary_deal_id',
        'deal_handoff_id',
        'owner_id',
        'account_manager_id',
        'department_id',
        'name',
        'description_json',
        'status',
        'health',
        'start_date',
        'target_end_date',
        'completed_at',
        'custom_fields',
    ];

    public function siloOwnerColumn(): string
    {
        return 'owner_id';
    }

    public function accountManagerColumn(): ?string
    {
        return 'account_manager_id';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'description_json' => 'array',
            'status' => ProjectStatusEnum::class,
            'health' => ProjectHealthEnum::class,
            'start_date' => 'date',
            'target_end_date' => 'date',
            'completed_at' => 'immutable_datetime',
            'custom_fields' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function primaryDeal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'primary_deal_id');
    }

    /**
     * @return BelongsTo<DealHandoff, $this>
     */
    public function dealHandoff(): BelongsTo
    {
        return $this->belongsTo(DealHandoff::class, 'deal_handoff_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * @return BelongsToMany<Deal, $this>
     */
    public function deals(): BelongsToMany
    {
        return $this->belongsToMany(Deal::class, 'deal_project', 'project_id', 'deal_id');
    }

    /**
     * @return HasMany<ProjectStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(ProjectStage::class, 'project_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'project_id');
    }

    /**
     * @return MorphMany<EntityCollaborator, $this>
     */
    public function collaborators(): MorphMany
    {
        return $this->morphMany(EntityCollaborator::class, 'collaboratable');
    }

    /**
     * @return MorphMany<ActivityLink, $this>
     */
    public function activityLinks(): MorphMany
    {
        return $this->morphMany(ActivityLink::class, 'linkable');
    }
}
