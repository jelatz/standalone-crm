<?php

namespace App\Providers;

use App\Modules\Core\Attachments\Models\Attachment;
use App\Modules\Core\Attachments\Policies\AttachmentPolicy;
use App\Modules\Core\Auth\Models\OauthIdentity;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\Team;
use App\Modules\Core\Users\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ModularDomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerMorphMap();
        $this->registerModulePolicies();
        $this->registerModuleRoutes();
    }

    protected function registerMorphMap(): void
    {
        $morphMap = [];

        if (class_exists(User::class)) {
            $morphMap['user'] = User::class;
        }
        if (class_exists(Department::class)) {
            $morphMap['department'] = Department::class;
        }
        if (class_exists(Team::class)) {
            $morphMap['team'] = Team::class;
        }
        if (class_exists(OauthIdentity::class)) {
            $morphMap['oauth_identity'] = OauthIdentity::class;
        }
        if (class_exists(Attachment::class)) {
            $morphMap['attachment'] = Attachment::class;
        }

        if ($morphMap !== []) {
            Relation::enforceMorphMap($morphMap);
        }
    }

    protected function registerModulePolicies(): void
    {
        $policies = [
            Attachment::class => AttachmentPolicy::class,
        ];

        foreach ($policies as $model => $policy) {
            if (class_exists($model) && class_exists($policy)) {
                Gate::policy($model, $policy);
            }
        }
    }

    protected function registerModuleRoutes(): void
    {
        foreach (glob(app_path('Modules/*/*/routes.php')) ?: [] as $routeFile) {
            Route::middleware('web')->group($routeFile);
        }
    }
}
