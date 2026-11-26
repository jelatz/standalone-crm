<?php

namespace App\Modules\Crm\Leads\Models;

use App\Modules\Core\Rbac\Models\EntityCollaborator;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Leads\Enums\LeadSourceEnum;
use App\Modules\Crm\Leads\Enums\LeadStatusEnum;
use App\Modules\Crm\Timeline\Models\ActivityLink;
use App\Support\Concerns\HasUuidV7;
use App\Support\Silo\HasSiloVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasSiloVisibility;
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'leads';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => LeadStatusEnum::New->value,
        'source' => LeadSourceEnum::InboundWeb->value,
        'currency' => 'USD',
        'custom_fields' => '{}',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'job_title',
        'company_name',
        'website',
        'status',
        'source',
        'estimated_value_cents',
        'currency',
        'disqualified_reason',
        'converted_at',
        'converted_company_id',
        'converted_contact_id',
        'converted_deal_id',
        'custom_fields',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeadStatusEnum::class,
            'source' => LeadSourceEnum::class,
            'estimated_value_cents' => 'integer',
            'converted_at' => 'immutable_datetime',
            'custom_fields' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function convertedCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'converted_company_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function convertedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'converted_contact_id');
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function convertedDeal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'converted_deal_id');
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
