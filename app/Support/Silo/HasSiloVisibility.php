<?php

namespace App\Support\Silo;

use App\Modules\Core\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

trait HasSiloVisibility
{
    public function siloOwnerColumn(): string
    {
        return 'owner_id';
    }

    public function accountManagerColumn(): ?string
    {
        return null;
    }

    /**
     * Scope an Eloquent query to records visible within the viewer's strict silo.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        $scope = app(VisibleScopeResolver::class)->for($viewer);

        if ($scope->isUnrestrictedAdmin) {
            return $query;
        }

        $model = $query->getModel();
        $morphAlias = $model->getMorphClass();
        $qualifiedKey = $model->getQualifiedKeyName();
        $ownerColumn = $model->qualifyColumn($this->siloOwnerColumn());
        $accountManagerColumn = $this->accountManagerColumn();

        return $query->where(function (Builder $subQuery) use (
            $scope,
            $viewer,
            $morphAlias,
            $qualifiedKey,
            $ownerColumn,
            $accountManagerColumn,
            $model,
        ): void {
            $subQuery->whereIn($ownerColumn, $scope->siloUserIds);

            if ($accountManagerColumn !== null) {
                $subQuery->orWhereIn($model->qualifyColumn($accountManagerColumn), $scope->siloUserIds);
            }

            $subQuery->orWhereExists(function (QueryBuilder $collabQuery) use (
                $morphAlias,
                $qualifiedKey,
                $viewer,
            ): void {
                $collabQuery->selectRaw('1')
                    ->from('entity_collaborators')
                    ->whereColumn('entity_collaborators.collaboratable_id', '=', $qualifiedKey)
                    ->where('entity_collaborators.collaboratable_type', '=', $morphAlias)
                    ->where('entity_collaborators.user_id', '=', $viewer->id);
            });
        });
    }
}
