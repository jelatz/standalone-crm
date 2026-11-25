<?php

namespace App\Modules\Crm\Companies\Models;

use App\Modules\Core\Rbac\Models\EntityCollaborator;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Enums\CompanyLifecycleStageEnum;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Timeline\Models\ActivityLink;
use App\Support\Concerns\HasUuidV7;
use App\Support\Silo\HasSiloVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasSiloVisibility;
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'companies';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'lifecycle_stage' => CompanyLifecycleStageEnum::Prospect->value,
        'billing_address' => '{}',
        'custom_fields' => '{}',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'domain',
        'lifecycle_stage',
        'industry',
        'employee_range',
        'annual_revenue_cents',
        'billing_address',
        'owner_id',
        'account_manager_id',
        'custom_fields',
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
            'lifecycle_stage' => CompanyLifecycleStageEnum::class,
            'annual_revenue_cents' => 'integer',
            'billing_address' => 'array',
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
     * @return BelongsTo<User, $this>
     */
    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'company_id');
    }

    /**
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'company_id');
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
