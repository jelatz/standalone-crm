<?php

namespace App\Modules\Crm\Contacts\Models;

use App\Modules\Core\Rbac\Models\EntityCollaborator;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Timeline\Models\ActivityLink;
use App\Support\Concerns\HasUuidV7;
use App\Support\Silo\HasSiloVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use HasSiloVisibility;
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'contacts';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'phones' => '[]',
        'is_primary_contact' => false,
        'custom_fields' => '{}',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'owner_id',
        'first_name',
        'last_name',
        'primary_email',
        'phones',
        'job_title',
        'is_primary_contact',
        'custom_fields',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phones' => 'array',
            'is_primary_contact' => 'boolean',
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
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<ContactEmail, $this>
     */
    public function emails(): HasMany
    {
        return $this->hasMany(ContactEmail::class, 'contact_id');
    }

    /**
     * @return BelongsToMany<Deal, $this>
     */
    public function deals(): BelongsToMany
    {
        return $this->belongsToMany(Deal::class, 'deal_contacts', 'contact_id', 'deal_id')
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
