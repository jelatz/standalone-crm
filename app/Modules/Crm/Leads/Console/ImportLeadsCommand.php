<?php

namespace App\Modules\Crm\Leads\Console;

use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Leads\Actions\ImportLeadsAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Throwable;

class ImportLeadsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'crm:leads:import
        {file : Path to JSON file containing an array of lead objects or {"leads": [...]}}
        {--owner= : Email address of the active CRM user who will own the imported leads}
        {--dry-run : Validate and check duplicates without inserting any records (default)}
        {--commit : Insert approved non-duplicate leads into the leads table with status=new}
        {--json : Output machine-readable JSON}';

    /**
     * @var string
     */
    protected $description = 'Validate, deduplicate, and import approved prospect batches into CRM Leads (status=new only).';

    public function handle(ImportLeadsAction $importLeads): int
    {
        $useDryRun = (bool) $this->option('dry-run');
        $useCommit = (bool) $this->option('commit');

        if ($useDryRun && $useCommit) {
            $this->error('Cannot pass both --dry-run and --commit.');

            return self::FAILURE;
        }

        $dryRun = ! $useCommit;

        $ownerEmail = trim((string) $this->option('owner'));
        if ($ownerEmail === '') {
            $this->error('The --owner=<email> option is required.');

            return self::FAILURE;
        }

        $actor = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($ownerEmail)])
            ->where('status', UserStatusEnum::Active->value)
            ->first();

        if (! $actor instanceof User) {
            $this->error("Active CRM user not found for email [{$ownerEmail}].");

            return self::FAILURE;
        }

        $filePath = trim((string) $this->argument('file'));
        if ($filePath === '' || ! is_file($filePath) || ! is_readable($filePath)) {
            $this->error("Readable JSON file not found at [{$filePath}].");

            return self::FAILURE;
        }

        $rawContents = file_get_contents($filePath);
        if (! is_string($rawContents)) {
            $this->error("Unable to read [{$filePath}].");

            return self::FAILURE;
        }

        try {
            $decoded = json_decode($rawContents, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->error("Invalid JSON in [{$filePath}].");

            return self::FAILURE;
        }

        if (! is_array($decoded)) {
            $this->error('Lead batch JSON must decode to an array of lead objects or {"leads": [...]}.');

            return self::FAILURE;
        }

        $rawRows = array_key_exists('leads', $decoded) && is_array($decoded['leads'])
            ? $decoded['leads']
            : $decoded;

        if (! array_is_list($rawRows)) {
            $this->error('Lead batch payload must be a list of lead objects.');

            return self::FAILURE;
        }

        try {
            $result = $importLeads->execute($actor, $rawRows, $dryRun);
        } catch (AuthorizationException $e) {
            $this->error($e->getMessage() ?: 'User is not authorized to create CRM leads.');

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Name', 'Company', 'Email', 'Source', 'Outcome', 'Notes / Lead ID'],
            array_map(
                static fn ($row): array => [
                    $row->row_index,
                    $row->full_name,
                    $row->company_name ?? '-',
                    $row->email ?? '-',
                    $row->source,
                    $row->outcome,
                    $row->lead_id ?? ($row->reason ?? ''),
                ],
                $result->rows,
            ),
        );

        if ($dryRun) {
            $this->info(sprintf(
                'Dry run complete (0 rows written): %d ready, %d duplicate, %d invalid. Re-run with --commit after user approval.',
                $result->ready_count,
                $result->duplicate_count,
                $result->invalid_count,
            ));
        } else {
            $this->info(sprintf(
                'Import complete: %d inserted into leads (status=new), %d duplicate skipped, %d invalid skipped.',
                $result->inserted_count,
                $result->duplicate_count,
                $result->invalid_count,
            ));
        }

        return self::SUCCESS;
    }
}
