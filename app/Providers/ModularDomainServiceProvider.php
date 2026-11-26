<?php

namespace App\Providers;

use App\Modules\Core\AsyncJobs\Models\AsyncJobRun;
use App\Modules\Core\Attachments\Models\Attachment;
use App\Modules\Core\Attachments\Policies\AttachmentPolicy;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\Team;
use App\Modules\Core\Users\Models\User;
use App\Modules\Core\Users\Policies\UserPolicy;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Companies\Policies\CompanyPolicy;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\Contacts\Policies\ContactPolicy;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use App\Modules\Crm\CustomFields\Policies\CustomFieldDefinitionPolicy;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Deals\Policies\DealPolicy;
use App\Modules\Crm\Leads\Console\ImportLeadsCommand;
use App\Modules\Crm\Leads\Models\Lead;
use App\Modules\Crm\Leads\Policies\LeadPolicy;
use App\Modules\Crm\Notes\Models\Note;
use App\Modules\Crm\Notes\Policies\NotePolicy;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Crm\Pipelines\Models\PipelineStage;
use App\Modules\Crm\Pipelines\Policies\PipelinePolicy;
use App\Modules\Crm\Timeline\Models\Activity;
use Illuminate\Contracts\Foundation\CachesRoutes;
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

        if ($this->app->runningInConsole() && class_exists(ImportLeadsCommand::class)) {
            $this->commands([
                ImportLeadsCommand::class,
            ]);
        }
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
        if (class_exists(Attachment::class)) {
            $morphMap['attachment'] = Attachment::class;
        }
        if (class_exists(AsyncJobRun::class)) {
            $morphMap['async_job_run'] = AsyncJobRun::class;
        }
        if (class_exists(Lead::class)) {
            $morphMap['lead'] = Lead::class;
        }
        if (class_exists(Company::class)) {
            $morphMap['company'] = Company::class;
        }
        if (class_exists(Contact::class)) {
            $morphMap['contact'] = Contact::class;
        }
        if (class_exists(Deal::class)) {
            $morphMap['deal'] = Deal::class;
        }
        if (class_exists(Note::class)) {
            $morphMap['note'] = Note::class;
        }
        if (class_exists(Pipeline::class)) {
            $morphMap['pipeline'] = Pipeline::class;
        }
        if (class_exists(PipelineStage::class)) {
            $morphMap['pipeline_stage'] = PipelineStage::class;
        }
        if (class_exists(CustomFieldDefinition::class)) {
            $morphMap['custom_field_definition'] = CustomFieldDefinition::class;
        }
        if (class_exists(Activity::class)) {
            $morphMap['activity'] = Activity::class;
        }

        if ($morphMap !== []) {
            Relation::enforceMorphMap($morphMap);
        }
    }

    protected function registerModulePolicies(): void
    {
        $policies = [
            Attachment::class => AttachmentPolicy::class,
            User::class => UserPolicy::class,
            Lead::class => LeadPolicy::class,
            Company::class => CompanyPolicy::class,
            Contact::class => ContactPolicy::class,
            Deal::class => DealPolicy::class,
            Pipeline::class => PipelinePolicy::class,
            CustomFieldDefinition::class => CustomFieldDefinitionPolicy::class,
            Note::class => NotePolicy::class,
        ];

        foreach ($policies as $model => $policy) {
            if (class_exists($model) && class_exists($policy)) {
                Gate::policy($model, $policy);
            }
        }

        Gate::define(
            'crm.overview.view',
            static fn (User $user): bool => $user->status === UserStatusEnum::Active
                && $user->deleted_at === null
                && $user->roles()->exists(),
        );

        Gate::define(
            'admin.sops.manage',
            static fn (User $user): bool => ! $user->trashed()
                && $user->status === UserStatusEnum::Active
                && $user->hasRole(RoleEnum::Superadmin->value),
        );
    }

    protected function registerModuleRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        foreach (glob(app_path('Modules/*/*/routes.php')) ?: [] as $routeFile) {
            Route::middleware('web')->group(fn () => $this->loadRoutesFrom($routeFile));
        }
    }
}
