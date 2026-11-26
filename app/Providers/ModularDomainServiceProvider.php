<?php

namespace App\Providers;

use App\Modules\Core\AsyncJobs\Models\AsyncJobRun;
use App\Modules\Core\Attachments\Models\Attachment;
use App\Modules\Core\Attachments\Policies\AttachmentPolicy;
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
use App\Modules\Crm\Notes\Models\Note;
use App\Modules\Crm\Notes\Policies\NotePolicy;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Crm\Pipelines\Models\PipelineStage;
use App\Modules\Crm\Pipelines\Policies\PipelinePolicy;
use App\Modules\Crm\Timeline\Models\Activity;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Modules\Projects\Handoffs\Policies\DealHandoffPolicy;
use App\Modules\Projects\Projects\Models\Project;
use App\Modules\Projects\Projects\Models\ProjectStage;
use App\Modules\Projects\Projects\Policies\ProjectPolicy;
use App\Modules\Projects\Tasks\Models\Task;
use App\Modules\Projects\Tasks\Policies\TaskPolicy;
use App\Modules\Projects\Templates\Models\ProjectTemplate;
use App\Modules\Projects\Templates\Policies\ProjectTemplatePolicy;
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
        if (class_exists(ProjectTemplate::class)) {
            $morphMap['project_template'] = ProjectTemplate::class;
        }
        if (class_exists(DealHandoff::class)) {
            $morphMap['deal_handoff'] = DealHandoff::class;
        }
        if (class_exists(Project::class)) {
            $morphMap['project'] = Project::class;
        }
        if (class_exists(ProjectStage::class)) {
            $morphMap['project_stage'] = ProjectStage::class;
        }
        if (class_exists(Task::class)) {
            $morphMap['task'] = Task::class;
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
            Company::class => CompanyPolicy::class,
            Contact::class => ContactPolicy::class,
            Deal::class => DealPolicy::class,
            Pipeline::class => PipelinePolicy::class,
            CustomFieldDefinition::class => CustomFieldDefinitionPolicy::class,
            Note::class => NotePolicy::class,
            ProjectTemplate::class => ProjectTemplatePolicy::class,
            DealHandoff::class => DealHandoffPolicy::class,
            Project::class => ProjectPolicy::class,
            Task::class => TaskPolicy::class,
        ];

        foreach ($policies as $model => $policy) {
            if (class_exists($model) && class_exists($policy)) {
                Gate::policy($model, $policy);
            }
        }
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
