<?php

namespace App\Modules\Crm\Deals\Models;

use App\Modules\Core\Rbac\Models\EntityCollaborator;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Crm\Pipelines\Models\PipelineStage;
use App\Modules\Crm\Timeline\Models\ActivityLink;
use App\Support\Concerns\HasUuidV7;
use App\Support\Silo\HasSiloVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deal extends Model
{
    use HasSiloVisibility;
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'deals';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'amount_cents' => 0,
        'currency' => 'USD',
        'board_position' => 65536.0,
        'custom_fields' => '{}',
        'version' => 1,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'primary_contact_id',
        'pipeline_id',
        'stage_id',
        'owner_id',
        'account_manager_id',
        'title',
        'amount_cents',
        'currency',
        'expected_close_date',
        'win_probability',
        'stage_entered_at',
        'won_at',
        'lost_at',
        'lost_reason',
        'board_position',
        'custom_fields',
        'version',
    ];

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
            'amount_cents' => 'integer',
            'expected_close_date' => 'date',
            'win_probability' => 'integer',
            'stage_entered_at' => 'immutable_datetime',
            'won_at' => 'immutable_datetime',
            'lost_at' => 'immutable_datetime',
            'board_position' => 'float',
            'custom_fields' => 'array',
            'version' => 'integer',
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
     * @return BelongsTo<Contact, $this>
     */
    public function primaryContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'primary_contact_id');
    }

    /**
     * @return BelongsTo<Pipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }

    /**
     * @return BelongsTo<PipelineStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
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
     * @return BelongsToMany<Contact, $this>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'deal_contacts', 'deal_id', 'contact_id')
            ->withPivot('stakeholder_role');
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
