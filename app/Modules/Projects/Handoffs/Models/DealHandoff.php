<?php

namespace App\Modules\Projects\Handoffs\Models;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Timeline\Models\Activity;
use App\Modules\Projects\Handoffs\Enums\DealHandoffStatusEnum;
use App\Modules\Projects\Projects\Models\Project;
use App\Modules\Projects\Templates\Models\ProjectTemplate;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class DealHandoff extends Model
{
    use HasUuidV7;

    protected $table = 'deal_handoffs';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => DealHandoffStatusEnum::PendingReview->value,
        'handoff_checklist' => '[]',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'deal_id',
        'company_id',
        'submitted_by_id',
        'reviewed_by_id',
        'assigned_pm_id',
        'assigned_am_id',
        'project_template_id',
        'status',
        'scope_notes_json',
        'handoff_checklist',
        'target_kickoff_date',
        'review_feedback',
        'submitted_at',
        'reviewed_at',
        'provisioned_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DealHandoffStatusEnum::class,
            'scope_notes_json' => 'array',
            'handoff_checklist' => 'array',
            'target_kickoff_date' => 'date',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'provisioned_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedPm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_pm_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedAm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_am_id');
    }

    /**
     * @return BelongsTo<ProjectTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ProjectTemplate::class, 'project_template_id');
    }

    /**
     * @return HasOne<Project, $this>
     */
    public function project(): HasOne
    {
        return $this->hasOne(Project::class, 'deal_handoff_id');
    }

    /**
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'source');
    }
}
