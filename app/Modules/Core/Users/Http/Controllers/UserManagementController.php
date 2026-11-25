<?php

namespace App\Modules\Core\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Actions\CreateUserAction;
use App\Modules\Core\Users\Data\CreateUserData;
use App\Modules\Core\Users\Data\UserSummaryData;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class UserManagementController extends Controller
{
    public function index(): InertiaResponse
    {
        Gate::authorize('viewAny', User::class);

        $users = User::query()
            ->with(['department', 'roles'])
            ->latest('created_at')
            ->get()
            ->map(static fn (User $user): UserSummaryData => UserSummaryData::fromModel($user))
            ->values()
            ->all();

        $departments = Department::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Department $dept): array => [
                'id' => (string) $dept->id,
                'name' => $dept->name,
            ])
            ->values()
            ->all();

        $roles = array_map(
            static fn (RoleEnum $role): array => [
                'value' => $role->value,
                'label' => Str::headline($role->value),
            ],
            RoleEnum::cases(),
        );

        return Inertia::render('core/pages/UsersPage', [
            'users' => $users,
            'departments' => $departments,
            'roles' => $roles,
        ]);
    }

    public function store(CreateUserData $data, CreateUserAction $createUser): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $user = $createUser->execute($data);

        return redirect()
            ->route('admin.users.index')
            ->with('success', "User {$user->name} ({$user->email}) created.");
    }
}
