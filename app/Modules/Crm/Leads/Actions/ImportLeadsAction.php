<?php

namespace App\Modules\Crm\Leads\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Models\ContactEmail;
use App\Modules\Crm\CustomFields\Actions\ValidateEntityCustomFieldsAction;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use App\Modules\Crm\Leads\Data\ImportLeadRowPayloadData;
use App\Modules\Crm\Leads\Data\ImportLeadRowResultData;
use App\Modules\Crm\Leads\Data\ImportLeadsResultData;
use App\Modules\Crm\Leads\Data\UpsertLeadData;
use App\Modules\Crm\Leads\Enums\LeadSourceEnum;
use App\Modules\Crm\Leads\Enums\LeadStatusEnum;
use App\Modules\Crm\Leads\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ImportLeadsAction
{
    public function __construct(
        protected CreateLeadAction $createLead,
        protected ValidateEntityCustomFieldsAction $validateCustomFields,
    ) {}

    /**
     * Validate, deduplicate, and optionally insert sourced leads as `new` prospects only.
     * Never creates companies, contacts, or deals.
     *
     * @param  list<mixed>  $rawRows
     */
    public function execute(User $actor, array $rawRows, bool $dryRun = true): ImportLeadsResultData
    {
        Gate::forUser($actor)->authorize('create', Lead::class);

        $normalizedRows = array_values($rawRows);

        if ($dryRun) {
            return $this->processBatch($actor, $normalizedRows, dryRun: true);
        }

        return DB::transaction(function () use ($actor, $normalizedRows): ImportLeadsResultData {
            if (DB::getDriverName() === 'pgsql') {
                // Serialize concurrent commits across the same user/silo to eliminate TOCTOU duplicate inserts
                DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['crm_lead_import:'.(string) $actor->id]);
            }

            return $this->processBatch($actor, $normalizedRows, dryRun: false);
        });
    }

    /**
     * @param  list<mixed>  $rawRows
     */
    protected function processBatch(User $actor, array $rawRows, bool $dryRun): ImportLeadsResultData
    {
        $activeLeadCustomFields = CustomFieldDefinition::query()
            ->where('entity_type', CustomFieldEntityTypeEnum::Lead->value)
            ->whereNull('pipeline_id')
            ->where('is_active', true)
            ->get(['key', 'validation_rules']);

        $hasActiveLeadCustomFields = $activeLeadCustomFields->isNotEmpty();
        $hasRequiredLeadCustomFields = $activeLeadCustomFields->contains(
            static fn (CustomFieldDefinition $def): bool => (bool) ($def->validation_rules['required'] ?? false),
        );

        $lookupSets = $this->loadCandidateDedupeLookup($actor, $rawRows);

        $batchEmails = [];
        $batchPeople = [];
        $batchPhones = [];

        /** @var list<array{row_index: int, dto: UpsertLeadData, payload: ImportLeadRowPayloadData, full_name: string, company_name: ?string, email: ?string, source: string}> $readyPayloads */
        $readyPayloads = [];
        /** @var array<int, ImportLeadRowResultData> $resultsByIndex */
        $resultsByIndex = [];

        foreach ($rawRows as $zeroIndex => $rawRow) {
            $rowIndex = $zeroIndex + 1;

            if (! is_array($rawRow)) {
                $resultsByIndex[$rowIndex] = new ImportLeadRowResultData(
                    row_index: $rowIndex,
                    full_name: '(invalid row)',
                    company_name: null,
                    email: null,
                    source: LeadSourceEnum::Outbound->value,
                    outcome: 'invalid',
                    reason: 'Each row must be a JSON object.',
                );

                continue;
            }

            $firstName = self::trimmedString($rawRow['first_name'] ?? null);
            $lastName = self::trimmedString($rawRow['last_name'] ?? null);
            $fullName = trim(($firstName ?? '').' '.($lastName ?? '')) ?: '(unnamed)';
            $companyName = self::trimmedString($rawRow['company_name'] ?? null);
            $rawEmail = self::trimmedString($rawRow['email'] ?? null);
            $email = $rawEmail !== null ? mb_strtolower($rawEmail) : null;
            $phone = self::trimmedString($rawRow['phone'] ?? null);
            $jobTitle = self::trimmedString($rawRow['job_title'] ?? null);
            $website = self::trimmedString($rawRow['website'] ?? null);

            $rawSource = self::trimmedString($rawRow['source'] ?? null);
            $sourceEnum = $rawSource !== null
                ? LeadSourceEnum::tryFrom($rawSource)
                : LeadSourceEnum::Outbound;

            $sourceLabel = $rawSource ?? LeadSourceEnum::Outbound->value;

            $validationError = $this->validateRow(
                row: $rawRow,
                firstName: $firstName,
                lastName: $lastName,
                companyName: $companyName,
                email: $email,
                phone: $phone,
                jobTitle: $jobTitle,
                website: $website,
                sourceEnum: $sourceEnum,
                hasActiveLeadCustomFields: $hasActiveLeadCustomFields,
                hasRequiredLeadCustomFields: $hasRequiredLeadCustomFields,
            );

            if ($validationError !== null) {
                $resultsByIndex[$rowIndex] = new ImportLeadRowResultData(
                    row_index: $rowIndex,
                    full_name: $fullName,
                    company_name: $companyName,
                    email: $email,
                    source: $sourceLabel,
                    outcome: 'invalid',
                    reason: $validationError,
                );

                continue;
            }

            /** @var string $firstName */
            /** @var string $lastName */
            /** @var LeadSourceEnum $sourceEnum */
            $personKey = self::personCompanyKey($firstName, $lastName, $companyName);
            $websiteDomain = self::normalizeDomain($website);
            $normalizedPhone = self::normalizePhone($phone);

            $duplicateReason = $this->detectDuplicateReason(
                email: $email,
                personKey: $personKey,
                websiteDomain: $websiteDomain,
                normalizedPhone: $normalizedPhone,
                batchEmails: $batchEmails,
                batchPeople: $batchPeople,
                batchPhones: $batchPhones,
                existingLeadEmails: $lookupSets['leadEmails'],
                existingContactEmails: $lookupSets['contactEmails'],
                existingLeadPeople: $lookupSets['leadPeople'],
                existingCompanyDomains: $lookupSets['companyDomains'],
                existingLeadPhones: $lookupSets['leadPhones'],
                existingLeadWebsites: $lookupSets['leadWebsites'],
            );

            if ($duplicateReason !== null) {
                $resultsByIndex[$rowIndex] = new ImportLeadRowResultData(
                    row_index: $rowIndex,
                    full_name: $fullName,
                    company_name: $companyName,
                    email: $email,
                    source: $sourceEnum->value,
                    outcome: 'duplicate',
                    reason: $duplicateReason,
                );

                continue;
            }

            if ($email !== null) {
                $batchEmails[$email] = true;
            }
            if ($personKey !== null) {
                $batchPeople[$personKey] = true;
            }
            if ($normalizedPhone !== null) {
                $batchPhones[$normalizedPhone] = true;
            }

            $estimatedValueCents = null;
            if (array_key_exists('estimated_value_cents', $rawRow) && $rawRow['estimated_value_cents'] !== null && $rawRow['estimated_value_cents'] !== '') {
                $estimatedValueCents = (int) $rawRow['estimated_value_cents'];
            }

            $currency = strtoupper(self::trimmedString($rawRow['currency'] ?? null) ?? 'USD');
            $customFields = is_array($rawRow['custom_fields'] ?? null) ? $rawRow['custom_fields'] : [];

            $dto = new UpsertLeadData(
                first_name: $firstName,
                last_name: $lastName,
                email: $email,
                phone: $phone,
                job_title: $jobTitle,
                company_name: $companyName,
                website: $website,
                status: LeadStatusEnum::New,
                source: $sourceEnum,
                estimated_value_cents: $estimatedValueCents,
                currency: $currency,
                custom_fields: $customFields,
            );

            $payloadData = new ImportLeadRowPayloadData(
                first_name: $firstName,
                last_name: $lastName,
                email: $email,
                phone: $phone,
                job_title: $jobTitle,
                company_name: $companyName,
                website: $website,
                source: $sourceEnum->value,
                estimated_value_cents: $estimatedValueCents,
                currency: $currency,
                custom_fields: $customFields,
            );

            $readyPayloads[] = [
                'row_index' => $rowIndex,
                'dto' => $dto,
                'payload' => $payloadData,
                'full_name' => $fullName,
                'company_name' => $companyName,
                'email' => $email,
                'source' => $sourceEnum->value,
            ];
        }

        if ($dryRun) {
            foreach ($readyPayloads as $item) {
                $resultsByIndex[$item['row_index']] = new ImportLeadRowResultData(
                    row_index: $item['row_index'],
                    full_name: $item['full_name'],
                    company_name: $item['company_name'],
                    email: $item['email'],
                    source: $item['source'],
                    outcome: 'ready',
                    payload: $item['payload'],
                );
            }
        } elseif ($readyPayloads !== []) {
            foreach ($readyPayloads as $item) {
                $lead = $this->createLead->execute($actor, $item['dto']);
                $resultsByIndex[$item['row_index']] = new ImportLeadRowResultData(
                    row_index: $item['row_index'],
                    full_name: $item['full_name'],
                    company_name: $item['company_name'],
                    email: $item['email'],
                    source: $item['source'],
                    outcome: 'inserted',
                    lead_id: (string) $lead->id,
                    payload: $item['payload'],
                );
            }
        }

        ksort($resultsByIndex);
        $rows = array_values($resultsByIndex);

        $readyCount = 0;
        $insertedCount = 0;
        $duplicateCount = 0;
        $invalidCount = 0;

        foreach ($rows as $row) {
            match ($row->outcome) {
                'ready' => $readyCount++,
                'inserted' => $insertedCount++,
                'duplicate' => $duplicateCount++,
                'invalid' => $invalidCount++,
                default => null,
            };
        }

        return new ImportLeadsResultData(
            dry_run: $dryRun,
            total_rows: count($rows),
            ready_count: $readyCount,
            inserted_count: $insertedCount,
            duplicate_count: $duplicateCount,
            invalid_count: $invalidCount,
            rows: $rows,
        );
    }

    /**
     * Load only candidate-relevant deduplication sets from Silo-visible records (avoids full-table preloads).
     *
     * @param  list<mixed>  $rawRows
     * @return array{
     *     leadEmails: array<string, bool>,
     *     contactEmails: array<string, bool>,
     *     leadPeople: array<string, bool>,
     *     companyDomains: array<string, bool>,
     *     leadPhones: array<string, bool>,
     *     leadWebsites: array<string, bool>
     * }
     */
    protected function loadCandidateDedupeLookup(User $actor, array $rawRows): array
    {
        $candidateEmails = [];
        $candidateLastNames = [];
        $candidateDomains = [];
        $candidatePhones = [];

        foreach ($rawRows as $rawRow) {
            if (! is_array($rawRow)) {
                continue;
            }

            $email = self::trimmedString($rawRow['email'] ?? null);
            if ($email !== null) {
                $candidateEmails[] = mb_strtolower($email);
            }

            $lastName = self::trimmedString($rawRow['last_name'] ?? null);
            if ($lastName !== null && self::trimmedString($rawRow['company_name'] ?? null) !== null) {
                $candidateLastNames[] = mb_strtolower($lastName);
            }

            $domain = self::normalizeDomain(self::trimmedString($rawRow['website'] ?? null));
            if ($domain !== null) {
                $candidateDomains[] = $domain;
            }

            $rawPhone = self::trimmedString($rawRow['phone'] ?? null);
            if ($rawPhone !== null) {
                $candidatePhones[] = $rawPhone;
            }
        }

        $candidateEmails = array_values(array_unique($candidateEmails));
        $candidateLastNames = array_values(array_unique($candidateLastNames));
        $candidateDomains = array_values(array_unique($candidateDomains));
        $candidatePhones = array_values(array_unique($candidatePhones));

        $leadEmails = [];
        if ($candidateEmails !== []) {
            Lead::query()
                ->visibleTo($actor)
                ->whereNotNull('email')
                ->whereIn(DB::raw('LOWER(email)'), $candidateEmails)
                ->get(['email'])
                ->each(static function (Lead $lead) use (&$leadEmails): void {
                    if (is_string($lead->email) && trim($lead->email) !== '') {
                        $leadEmails[mb_strtolower(trim($lead->email))] = true;
                    }
                });
        }

        $contactEmails = [];
        if ($candidateEmails !== []) {
            ContactEmail::query()
                ->whereHas('contact', static fn ($q) => $q->visibleTo($actor))
                ->whereIn(DB::raw('LOWER(email)'), $candidateEmails)
                ->get(['email'])
                ->each(static function (ContactEmail $contactEmail) use (&$contactEmails): void {
                    if (trim($contactEmail->email) !== '') {
                        $contactEmails[mb_strtolower(trim($contactEmail->email))] = true;
                    }
                });
        }

        $leadPeople = [];
        if ($candidateLastNames !== []) {
            Lead::query()
                ->visibleTo($actor)
                ->whereNotNull('company_name')
                ->whereIn(DB::raw('LOWER(last_name)'), $candidateLastNames)
                ->get(['first_name', 'last_name', 'company_name'])
                ->each(static function (Lead $lead) use (&$leadPeople): void {
                    $key = self::personCompanyKey($lead->first_name, $lead->last_name, $lead->company_name);
                    if ($key !== null) {
                        $leadPeople[$key] = true;
                    }
                });
        }

        $companyDomains = [];
        if ($candidateDomains !== []) {
            $domainVariants = [];
            foreach ($candidateDomains as $dom) {
                $domainVariants[] = $dom;
                $domainVariants[] = 'www.'.$dom;
            }

            Company::query()
                ->visibleTo($actor)
                ->whereNotNull('domain')
                ->whereIn(DB::raw('LOWER(domain)'), array_values(array_unique($domainVariants)))
                ->get(['domain'])
                ->each(static function (Company $company) use (&$companyDomains): void {
                    $normalized = self::normalizeDomain($company->domain);
                    if ($normalized !== null) {
                        $companyDomains[$normalized] = true;
                    }
                });
        }

        $leadPhones = [];
        if ($candidatePhones !== []) {
            Lead::query()
                ->visibleTo($actor)
                ->whereIn('phone', $candidatePhones)
                ->get(['phone'])
                ->each(static function (Lead $lead) use (&$leadPhones): void {
                    $norm = self::normalizePhone($lead->phone);
                    if ($norm !== null) {
                        $leadPhones[$norm] = true;
                    }
                });
        }

        $leadWebsites = [];
        if ($candidateDomains !== []) {
            Lead::query()
                ->visibleTo($actor)
                ->whereNotNull('website')
                ->where(static function ($q) use ($candidateDomains): void {
                    foreach ($candidateDomains as $dom) {
                        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $dom);
                        $q->orWhereRaw('LOWER(website) LIKE ?', ['%'.$escaped.'%']);
                    }
                })
                ->get(['website'])
                ->each(static function (Lead $lead) use (&$leadWebsites): void {
                    $norm = self::normalizeDomain($lead->website);
                    if ($norm !== null) {
                        $leadWebsites[$norm] = true;
                    }
                });
        }

        return [
            'leadEmails' => $leadEmails,
            'contactEmails' => $contactEmails,
            'leadPeople' => $leadPeople,
            'companyDomains' => $companyDomains,
            'leadPhones' => $leadPhones,
            'leadWebsites' => $leadWebsites,
        ];
    }

    /**
     * @param  array<mixed, mixed>  $row
     */
    protected function validateRow(
        array $row,
        ?string $firstName,
        ?string $lastName,
        ?string $companyName,
        ?string $email,
        ?string $phone,
        ?string $jobTitle,
        ?string $website,
        ?LeadSourceEnum $sourceEnum,
        bool $hasActiveLeadCustomFields,
        bool $hasRequiredLeadCustomFields,
    ): ?string {
        if ($firstName === null || mb_strlen($firstName) > 100) {
            return 'first_name is required (1-100 characters).';
        }

        if ($lastName === null || mb_strlen($lastName) > 100) {
            return 'last_name is required (1-100 characters).';
        }

        if ($companyName !== null && mb_strlen($companyName) > 200) {
            return 'company_name must be at most 200 characters.';
        }

        if ($email !== null && (mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            return 'email is not a valid email address.';
        }

        if ($phone !== null && mb_strlen($phone) > 40) {
            return 'phone must be at most 40 characters.';
        }

        if ($jobTitle !== null && mb_strlen($jobTitle) > 120) {
            return 'job_title must be at most 120 characters.';
        }

        if ($website !== null) {
            if (mb_strlen($website) > 255 || ! self::isSafeWebsiteValue($website)) {
                return 'website must be a valid domain or http(s) URL.';
            }
        }

        if ($email === null && $phone === null && $website === null) {
            return 'At least one reachable contact channel (email, phone, or website) is required.';
        }

        $rawStatus = self::trimmedString($row['status'] ?? null);
        if ($rawStatus !== null && mb_strtolower($rawStatus) !== LeadStatusEnum::New->value) {
            return 'Generated leads must enter with status=new and cannot be pre-converted.';
        }

        if ($sourceEnum === null) {
            return 'Invalid lead source; expected one of inbound_web, outbound, referral, event, partner, other.';
        }

        if (array_key_exists('estimated_value_cents', $row) && $row['estimated_value_cents'] !== null && $row['estimated_value_cents'] !== '') {
            if (! is_int($row['estimated_value_cents']) && ! (is_string($row['estimated_value_cents']) && preg_match('/^\d{1,15}$/', trim($row['estimated_value_cents'])) === 1)) {
                return 'estimated_value_cents must be a non-negative integer.';
            }

            $cents = (int) $row['estimated_value_cents'];
            if ($cents < 0 || $cents > 999999999999999) {
                return 'estimated_value_cents is out of allowed range.';
            }
        }

        $currency = self::trimmedString($row['currency'] ?? null);
        if ($currency !== null && mb_strlen($currency) !== 3) {
            return 'currency must be a 3-letter ISO code.';
        }

        $customFields = $row['custom_fields'] ?? [];
        if (! is_array($customFields)) {
            return 'custom_fields must be an object.';
        }

        if (($customFields !== [] && $hasActiveLeadCustomFields) || $hasRequiredLeadCustomFields) {
            try {
                $this->validateCustomFields->execute(
                    entityType: CustomFieldEntityTypeEnum::Lead,
                    values: $customFields,
                    pipelineId: null,
                    partial: false,
                );
            } catch (ValidationException $e) {
                return collect($e->errors())->flatten()->first() ?? 'Invalid custom_fields value.';
            }
        }

        return null;
    }

    /**
     * @param  array<string, bool>  $batchEmails
     * @param  array<string, bool>  $batchPeople
     * @param  array<string, bool>  $batchPhones
     * @param  array<string, bool>  $existingLeadEmails
     * @param  array<string, bool>  $existingContactEmails
     * @param  array<string, bool>  $existingLeadPeople
     * @param  array<string, bool>  $existingCompanyDomains
     * @param  array<string, bool>  $existingLeadPhones
     * @param  array<string, bool>  $existingLeadWebsites
     */
    protected function detectDuplicateReason(
        ?string $email,
        ?string $personKey,
        ?string $websiteDomain,
        ?string $normalizedPhone,
        array $batchEmails,
        array $batchPeople,
        array $batchPhones,
        array $existingLeadEmails,
        array $existingContactEmails,
        array $existingLeadPeople,
        array $existingCompanyDomains,
        array $existingLeadPhones,
        array $existingLeadWebsites,
    ): ?string {
        if ($email !== null && isset($batchEmails[$email])) {
            return 'Duplicate email within import batch.';
        }

        if ($personKey !== null && isset($batchPeople[$personKey])) {
            return 'Duplicate person and company within import batch.';
        }

        if ($email !== null && isset($existingLeadEmails[$email])) {
            return 'Email already exists in Leads.';
        }

        if ($email !== null && isset($existingContactEmails[$email])) {
            return 'Email already exists in Contacts.';
        }

        if ($personKey !== null && isset($existingLeadPeople[$personKey])) {
            return 'Lead name and company already exist in Leads.';
        }

        if ($websiteDomain !== null && isset($existingCompanyDomains[$websiteDomain])) {
            return "Website domain ({$websiteDomain}) already exists in Companies.";
        }

        if ($email === null && $normalizedPhone !== null) {
            if (isset($batchPhones[$normalizedPhone])) {
                return 'Duplicate phone number within import batch.';
            }
            if (isset($existingLeadPhones[$normalizedPhone])) {
                return 'Phone number already exists in Leads.';
            }
        }

        if ($email === null && $personKey === null && $websiteDomain !== null && isset($existingLeadWebsites[$websiteDomain])) {
            return "Website domain ({$websiteDomain}) already exists in Leads.";
        }

        return null;
    }

    public static function isSafeWebsiteValue(string $website): bool
    {
        $trimmed = trim($website);
        if ($trimmed === '' || preg_match('/[\s\x00-\x1F\x7F]/', $trimmed) === 1) {
            return false;
        }

        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $trimmed, $schemeMatch) === 1) {
            $scheme = mb_strtolower($schemeMatch[1]);
            if (! in_array($scheme, ['http', 'https'], true)) {
                return false;
            }
            $candidate = $trimmed;
        } else {
            $candidate = 'https://'.$trimmed;
        }

        if (filter_var($candidate, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $host = parse_url($candidate, PHP_URL_HOST);

        return is_string($host) && $host !== '' && str_contains($host, '.');
    }

    protected static function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
        $trimmed = trim($cleaned);

        return $trimmed === '' ? null : $trimmed;
    }

    protected static function personCompanyKey(string $firstName, string $lastName, ?string $companyName): ?string
    {
        if ($companyName === null || trim($companyName) === '') {
            return null;
        }

        return mb_strtolower(trim($firstName)).'|'.mb_strtolower(trim($lastName)).'|'.mb_strtolower(trim($companyName));
    }

    protected static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digitsOrPlus = preg_replace('/[^\d+]/', '', trim($phone)) ?? '';

        return mb_strlen($digitsOrPlus) >= 5 ? $digitsOrPlus : null;
    }

    protected static function normalizeDomain(?string $urlOrDomain): ?string
    {
        if ($urlOrDomain === null || trim($urlOrDomain) === '') {
            return null;
        }

        $candidate = trim($urlOrDomain);
        if (! str_contains($candidate, '://')) {
            $candidate = 'https://'.$candidate;
        }

        $host = parse_url($candidate, PHP_URL_HOST);
        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        $normalized = mb_strtolower(trim($host));
        if (str_starts_with($normalized, 'www.')) {
            $normalized = substr($normalized, 4);
        }

        return $normalized !== '' ? $normalized : null;
    }
}
