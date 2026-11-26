<?php

namespace App\Modules\Core\Rbac\Data;

use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\CollaboratorRoleLabel;
use App\Modules\Core\Rbac\Models\EntityCollaborator;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class EntityCollaboratorData extends Data
{
    public function __construct(
        public string $id,
        public string $user_id,
        public ?string $user_name,
        public ?string $user_email,
        public CollaboratorAccessLevel $access_level,
        public CollaboratorRoleLabel $role_label,
        public string $granted_by_id,
        public ?string $granted_by_name,
        public string $created_at,
    ) {}

    public static function fromModel(EntityCollaborator $collaborator): self
    {
        $user = $collaborator->relationLoaded('user') ? $collaborator->user : null;
        $grantedBy = $collaborator->relationLoaded('grantedBy') ? $collaborator->grantedBy : null;

        return new self(
            id: (string) $collaborator->id,
            user_id: (string) $collaborator->user_id,
            user_name: $user?->name,
            user_email: $user?->email,
            access_level: $collaborator->access_level,
            role_label: $collaborator->role_label,
            granted_by_id: (string) $collaborator->granted_by_id,
            granted_by_name: $grantedBy?->name,
            created_at: $collaborator->created_at?->toIso8601String() ?? now()->toIso8601String(),
        );
    }
}
