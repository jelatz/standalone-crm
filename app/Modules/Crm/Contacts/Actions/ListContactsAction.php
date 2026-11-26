<?php

namespace App\Modules\Crm\Contacts\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Data\ContactFilterData;
use App\Modules\Crm\Contacts\Data\ContactListItemData;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\CustomFields\Actions\ApplyCustomFieldFiltersAction;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ListContactsAction
{
    public function __construct(
        protected ApplyCustomFieldFiltersAction $applyCustomFieldFilters,
    ) {}

    /**
     * @return LengthAwarePaginator<int, ContactListItemData>
     */
    public function execute(User $viewer, ContactFilterData $filter): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filter->per_page ?? 25), 100));
        $page = max(1, (int) ($filter->page ?? 1));

        $query = $this->buildQuery($viewer, $filter)
            ->with(['owner:id,name', 'company:id,name', 'emails']);

        /** @var LengthAwarePaginator<int, Contact> $paginator */
        $paginator = $query->paginate(perPage: $perPage, page: $page);

        $companyIds = collect($paginator->items())
            ->pluck('company_id')
            ->filter()
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        /** @var array<string, int|string> $visibleCompanyIds */
        $visibleCompanyIds = ($companyIds !== [] && Gate::forUser($viewer)->allows('viewAny', Company::class))
            ? Company::query()
                ->visibleTo($viewer)
                ->whereIn('id', $companyIds)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->flip()
                ->all()
            : [];

        return $paginator->through(
            static fn (Contact $contact): ContactListItemData => ContactListItemData::fromModel(
                $contact,
                $contact->company_id !== null && isset($visibleCompanyIds[(string) $contact->company_id]),
            ),
        );
    }

    /**
     * Build the silo-scoped, filtered, and sorted Contact query.
     *
     * @return Builder<Contact>
     */
    public function buildQuery(User $viewer, ContactFilterData $filter): Builder
    {
        $query = Contact::query()->visibleTo($viewer);

        if ($filter->search !== null && trim($filter->search) !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filter->search));
            $pattern = "%{$escaped}%";
            $likeOperator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';

            $query->where(function (Builder $sub) use ($pattern, $likeOperator): void {
                $sub->where('contacts.full_name', $likeOperator, $pattern)
                    ->orWhere('contacts.primary_email', $likeOperator, $pattern)
                    ->orWhereExists(function (QueryBuilder $emailQuery) use ($pattern, $likeOperator): void {
                        $emailQuery->selectRaw('1')
                            ->from('contact_emails')
                            ->whereColumn('contact_emails.contact_id', '=', 'contacts.id')
                            ->where('contact_emails.email', $likeOperator, $pattern);
                    });
            });
        }

        if ($filter->company_id !== null && trim($filter->company_id) !== '') {
            $query->where('contacts.company_id', $filter->company_id);
        }

        if ($filter->owner_id !== null && trim($filter->owner_id) !== '') {
            $query->where('contacts.owner_id', $filter->owner_id);
        }

        $this->applyCustomFieldFilters->execute(
            query: $query,
            entityType: CustomFieldEntityTypeEnum::Contact,
            filters: $filter->custom_fields,
            qualifiedColumn: 'contacts.custom_fields',
        );

        $sortBy = in_array($filter->sort_by, ContactFilterData::ALLOWED_SORT_FIELDS, true)
            ? $filter->sort_by
            : 'updated_at';

        $sortDir = $filter->sort_dir === 'asc' ? 'asc' : 'desc';

        return $query
            ->orderBy("contacts.{$sortBy}", $sortDir)
            ->orderBy('contacts.id', $sortDir);
    }
}
