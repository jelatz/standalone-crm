<?php

namespace App\Modules\Core\Rbac\Models;

use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\CollaboratorRoleLabel;
use App\Modules\Core\Users\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EntityCollaborator extends Model
{
    use HasUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'entity_collaborators';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'collaboratable_type',
        'collaboratable_id',
        'user_id',
        'access_level',
        'role_label',
        'granted_by_id',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_level' => CollaboratorAccessLevel::class,
            'role_label' => CollaboratorRoleLabel::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function collaboratable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_id');
    }
}
