<?php

namespace App\Http\Middleware;

use App\Modules\Core\Auth\Data\AuthenticatedUserData;
use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Handoffs\Enums\DealHandoffStatusEnum;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn (): ?AuthenticatedUserData => $request->user() !== null
                    ? AuthenticatedUserData::fromModel($request->user())
                    : null,
            ],
            'flash' => [
                'success' => fn (): ?string => $request->session()->get('success'),
            ],
            'unreadCounts' => [
                'handoffs' => function () use ($request): int {
                    $user = $request->user();

                    if (
                        ! $user instanceof User
                        || ! $user->exists
                        || $user->trashed()
                        || $user->status !== UserStatusEnum::Active
                        || ! $user->checkPermissionTo(PermissionEnum::HandoffsReview->value)
                    ) {
                        return 0;
                    }

                    return DealHandoff::query()
                        ->where('status', DealHandoffStatusEnum::PendingReview->value)
                        ->count();
                },
            ],
        ];
    }
}
