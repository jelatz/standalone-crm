<?php

namespace App\Modules\Core\Users\Models;

use App\Modules\Core\Auth\Models\OauthIdentity;
use App\Modules\Core\Rbac\Models\EntityCollaborator;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;
    use HasUuidV7;
    use Notifiable;
    use SoftDeletes;

    protected string $guard_name = 'web';

    protected $rememberTokenName = '';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_code',
        'name',
        'email',
        'avatar_key',
        'department_id',
        'primary_team_id',
        'manager_id',
        'job_title',
        'status',
        'timezone',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UserStatusEnum::class,
            'last_seen_at' => 'datetime',
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
     * @return BelongsTo<Team, $this>
     */
    public function primaryTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'primary_team_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    /**
     * @return HasMany<UserHierarchyClosure, $this>
     */
    public function ancestorClosures(): HasMany
    {
        return $this->hasMany(UserHierarchyClosure::class, 'descendant_id');
    }

    /**
     * @return HasMany<UserHierarchyClosure, $this>
     */
    public function descendantClosures(): HasMany
    {
        return $this->hasMany(UserHierarchyClosure::class, 'ancestor_id');
    }

    /**
     * @return HasMany<EntityCollaborator, $this>
     */
    public function entityCollaborations(): HasMany
    {
        return $this->hasMany(EntityCollaborator::class, 'user_id');
    }

    /**
     * @return HasMany<OauthIdentity, $this>
     */
    public function oauthIdentities(): HasMany
    {
        return $this->hasMany(OauthIdentity::class, 'user_id');
    }
}
