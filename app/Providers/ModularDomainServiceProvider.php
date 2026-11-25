<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
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

        if (class_exists(\App\Modules\Core\Users\Models\User::class)) {
            $morphMap['user'] = \App\Modules\Core\Users\Models\User::class;
        }
        if (class_exists(\App\Modules\Core\Users\Models\Department::class)) {
            $morphMap['department'] = \App\Modules\Core\Users\Models\Department::class;
        }
        if (class_exists(\App\Modules\Core\Users\Models\Team::class)) {
            $morphMap['team'] = \App\Modules\Core\Users\Models\Team::class;
        }
        if (class_exists(\App\Modules\Core\Attachments\Models\Attachment::class)) {
            $morphMap['attachment'] = \App\Modules\Core\Attachments\Models\Attachment::class;
        }

        if ($morphMap !== []) {
            Relation::enforceMorphMap($morphMap);
        }
    }

    protected function registerModulePolicies(): void
    {
        $policies = [
            \App\Modules\Core\Attachments\Models\Attachment::class => \App\Modules\Core\Attachments\Policies\AttachmentPolicy::class,
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
            $this->loadRoutesFrom($routeFile);
        }
    }
}
