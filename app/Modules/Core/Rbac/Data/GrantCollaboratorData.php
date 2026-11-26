<?php

namespace App\Modules\Core\Rbac\Data;

use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\CollaboratorRoleLabel;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class GrantCollaboratorData extends Data
{
    public function __construct(
        public string $user_id,
        public CollaboratorAccessLevel $access_level,
        public CollaboratorRoleLabel $role_label,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'uuid',
                Rule::exists('users', 'id')
                    ->whereNull('deleted_at')
                    ->where('status', UserStatusEnum::Active->value),
            ],
            'access_level' => ['required', Rule::enum(CollaboratorAccessLevel::class)],
            'role_label' => ['required', Rule::enum(CollaboratorRoleLabel::class)],
        ];
    }
}
