<?php

namespace App\Modules\Crm\Leads\Actions;

use App\Modules\Crm\Leads\Enums\LeadSourceEnum;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\Ods;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Throwable;
use ZipArchive;

class ParseLeadSpreadsheetAction
{
    public const int MAX_ROWS = 1000;

    public const int MAX_COLUMNS = 100;

    public const int MAX_ZIP_ENTRIES = 200;

    public const int MAX_ZIP_UNCOMPRESSED_BYTES = 33554432; // 32 MB

    /**
     * @var array<string, string>
     */
    public const array EXTENSION_TO_READER = [
        'csv' => 'Csv',
        'tsv' => 'Csv',
        'txt' => 'Csv',
        'xls' => 'Xls',
        'xlsx' => 'Xlsx',
        'ods' => 'Ods',
    ];

    /**
     * @var list<string>
     */
    public const array BLOCKED_CSV_MIMES = [
        'text/html',
        'application/xhtml+xml',
        'text/xml',
        'application/xml',
        'image/svg+xml',
    ];

    /**
     * @var list<string>
     */
    public const array MAPPABLE_FIELDS = [
        'first_name',
        'last_name',
        'full_name',
        'email',
        'phone',
        'job_title',
        'company_name',
        'website',
        'source',
        'estimated_value',
        'estimated_value_cents',
        'currency',
    ];

    /**
     * @param  array<string, string|null>  $columnMappingOverride
     * @return array{
     *     headers: list<string>,
     *     detected_mapping: array<string, string|null>,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function execute(UploadedFile $file, array $columnMappingOverride = []): array
    {
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $expectedReader = self::EXTENSION_TO_READER[$extension] ?? null;

        if ($expectedReader === null) {
            throw ValidationException::withMessages([
                'file' => ['Only .csv, .tsv, .xls, .xlsx, or .ods files are supported.'],
            ]);
        }

        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw ValidationException::withMessages([
                'file' => ['Uploaded spreadsheet could not be read from temporary storage.'],
            ]);
        }

        if ($expectedReader === 'Csv') {
            $rawTable = $this->readCsvBounded($path, $extension);
        } else {
            $rawTable = $this->readWorkbookBounded($path, $expectedReader);
        }

        $nonEmptyRows = [];
        foreach ($rawTable as $rawRow) {
            if (! is_array($rawRow)) {
                continue;
            }

            $boundedRow = array_slice(array_values($rawRow), 0, self::MAX_COLUMNS);
            if ($this->rowHasAnyContent($boundedRow)) {
                $nonEmptyRows[] = $boundedRow;
            }
        }

        if ($nonEmptyRows === []) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded spreadsheet appears to be empty.'],
            ]);
        }

        $rawHeaderRow = array_shift($nonEmptyRows);
        $headers = $this->normalizeHeaders($rawHeaderRow);

        if ($nonEmptyRows === []) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded spreadsheet contains headers but zero data rows.'],
            ]);
        }

        if (count($nonEmptyRows) > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'file' => [sprintf('Uploaded file exceeds the maximum of %d rows per batch.', self::MAX_ROWS)],
            ]);
        }

        $detectedMapping = $this->detectMapping($headers);
        $effectiveMapping = $this->mergeMappingOverrides($detectedMapping, $headers, $columnMappingOverride);

        $headerIndexMap = array_flip($headers);
        $rows = [];

        foreach ($nonEmptyRows as $rawRow) {
            $rows[] = $this->mapSpreadsheetRow($rawRow, $headerIndexMap, $effectiveMapping);
        }

        return [
            'headers' => $headers,
            'detected_mapping' => $effectiveMapping,
            'rows' => $rows,
        ];
    }

    /**
     * @return list<list<mixed>>
     */
    protected function readCsvBounded(string $path, string $extension): array
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = mb_strtolower((string) $finfo->file($path));
        if (in_array($detectedMime, self::BLOCKED_CSV_MIMES, true)) {
            throw ValidationException::withMessages([
                'file' => ["Unsupported or disguised markup file ({$detectedMime}). Export a clean CSV or Excel file."],
            ]);
        }

        try {
            $identified = IOFactory::identify($path, ['Csv']);
            if ($identified !== 'Csv') {
                throw ValidationException::withMessages([
                    'file' => ['Uploaded file contents do not match CSV/TSV text format.'],
                ]);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'file' => ['Uploaded file contents do not match a valid CSV/TSV file.'],
            ]);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => ['Unable to open uploaded CSV file.'],
            ]);
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                return [];
            }

            if (preg_match('/<\s*(?:!doctype\s+html|html|head|body|table|script|svg|xml)\b/i', $firstLine) === 1) {
                throw ValidationException::withMessages([
                    'file' => ['Markup/HTML files are not accepted as CSV spreadsheets.'],
                ]);
            }

            $delimiter = $extension === 'tsv'
                ? "\t"
                : $this->detectCsvDelimiter($firstLine);

            rewind($handle);

            // Strip UTF-8 BOM cleanly if present
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            $rows = [];
            $maxLinesToRead = self::MAX_ROWS + 50;
            $nonEmptySeen = 0;

            while (($lineCount = count($rows)) < $maxLinesToRead && ($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $rows[] = $row;
                if ($this->rowHasAnyContent($row)) {
                    $nonEmptySeen++;
                    if ($nonEmptySeen > self::MAX_ROWS + 1) {
                        throw ValidationException::withMessages([
                            'file' => [sprintf('Uploaded file exceeds the maximum of %d rows per batch.', self::MAX_ROWS)],
                        ]);
                    }
                }
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return list<list<mixed>>
     */
    protected function readWorkbookBounded(string $path, string $expectedReader): array
    {
        if (in_array($expectedReader, ['Xlsx', 'Ods'], true)) {
            $this->inspectZipArchiveBounds($path);
        }

        try {
            $readerType = IOFactory::identify($path, [$expectedReader]);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'file' => ["File contents do not match expected {$expectedReader} format."],
            ]);
        }

        if ($readerType !== $expectedReader) {
            throw ValidationException::withMessages([
                'file' => ["Unsupported or disguised workbook ({$readerType}). Expected {$expectedReader}."],
            ]);
        }

        $spreadsheet = null;

        try {
            /** @var Xls|Xlsx|Ods $reader */
            $reader = IOFactory::createReader($expectedReader);
            $reader->setReadDataOnly(true);

            $worksheetInfo = $reader->listWorksheetInfo($path);
            $firstSheet = $worksheetInfo[0] ?? null;
            if (! is_array($firstSheet) || ! is_string($firstSheet['worksheetName'] ?? null)) {
                throw ValidationException::withMessages([
                    'file' => ['Workbook contains no readable worksheets.'],
                ]);
            }

            $reportedRows = (int) ($firstSheet['totalRows'] ?? 0);
            $reportedCols = (int) ($firstSheet['totalColumns'] ?? 0);

            if ($reportedRows > self::MAX_ROWS + 50) {
                throw ValidationException::withMessages([
                    'file' => [sprintf('Worksheet contains too many rows (%d); maximum is %d data rows per batch.', $reportedRows, self::MAX_ROWS)],
                ]);
            }

            if ($reportedCols > self::MAX_COLUMNS) {
                throw ValidationException::withMessages([
                    'file' => [sprintf('Worksheet exceeds the maximum supported column width (%d columns).', self::MAX_COLUMNS)],
                ]);
            }

            $reader->setLoadSheetsOnly([$firstSheet['worksheetName']]);
            $reader->setReadFilter(new class(self::MAX_ROWS + 50, self::MAX_COLUMNS) implements IReadFilter
            {
                public function __construct(
                    private int $maxRow,
                    private int $maxColIndex,
                ) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    if ($row > $this->maxRow) {
                        return false;
                    }

                    try {
                        return Coordinate::columnIndexFromString($columnAddress) <= $this->maxColIndex;
                    } catch (Throwable) {
                        return false;
                    }
                }
            });

            $spreadsheet = $reader->load($path);
            $sheet = $spreadsheet->getActiveSheet();

            /** @var list<list<mixed>> $rawTable */
            $rawTable = $sheet->toArray(null, false, false, false);

            return $rawTable;
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'file' => ['Spreadsheet could not be parsed. Verify the file is not corrupted or password-protected.'],
            ]);
        } finally {
            if ($spreadsheet !== null) {
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);
            }
        }
    }

    protected function inspectZipArchiveBounds(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw ValidationException::withMessages([
                'file' => ['Workbook archive is invalid or corrupted.'],
            ]);
        }

        try {
            $numFiles = $zip->numFiles;
            if ($numFiles > self::MAX_ZIP_ENTRIES) {
                throw ValidationException::withMessages([
                    'file' => ['Workbook contains too many archive entries and was rejected for safety.'],
                ]);
            }

            $totalUncompressed = 0;
            for ($i = 0; $i < $numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (! is_array($stat)) {
                    continue;
                }

                $entrySize = max(0, (int) ($stat['size'] ?? 0));
                $totalUncompressed += $entrySize;

                if ($totalUncompressed > self::MAX_ZIP_UNCOMPRESSED_BYTES) {
                    throw ValidationException::withMessages([
                        'file' => ['Workbook exceeds safe uncompressed size limits (32 MB).'],
                    ]);
                }
            }
        } finally {
            $zip->close();
        }
    }

    protected function detectCsvDelimiter(string $sampleLine): string
    {
        $counts = [
            ',' => substr_count($sampleLine, ','),
            ';' => substr_count($sampleLine, ';'),
            "\t" => substr_count($sampleLine, "\t"),
        ];

        arsort($counts);
        $topDelimiter = array_key_first($counts);

        return ($topDelimiter !== null && $counts[$topDelimiter] > 0) ? (string) $topDelimiter : ',';
    }

    /**
     * @param  list<mixed>  $row
     */
    protected function rowHasAnyContent(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim($this->stripUnsafeControlChars((string) $cell)) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $rawHeaderRow
     * @return list<string>
     */
    protected function normalizeHeaders(array $rawHeaderRow): array
    {
        $headers = [];
        $seen = [];

        foreach ($rawHeaderRow as $idx => $rawHeader) {
            $base = $this->sanitizeTextValue((string) ($rawHeader ?? ''), allowPhonePlus: false) ?? '';
            if ($base === '') {
                $base = 'Column '.($idx + 1);
            }

            $candidate = $base;
            $suffix = 2;
            while (isset($seen[$candidate])) {
                $candidate = "{$base} ({$suffix})";
                $suffix++;
            }

            $seen[$candidate] = true;
            $headers[] = $candidate;
        }

        return $headers;
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string|null>
     */
    protected function detectMapping(array $headers): array
    {
        $mapping = array_fill_keys(self::MAPPABLE_FIELDS, null);

        $aliases = [
            'first_name' => ['firstname', 'first', 'fname', 'givenname'],
            'last_name' => ['lastname', 'last', 'lname', 'surname', 'familyname'],
            'full_name' => ['fullname', 'name', 'leadname', 'contactname', 'prospectname'],
            'email' => ['email', 'emailaddress', 'workemail', 'businessemail', 'primaryemail'],
            'phone' => ['phone', 'phonenumber', 'mobile', 'workphone', 'telephone', 'directphone'],
            'job_title' => ['jobtitle', 'title', 'role', 'position'],
            'company_name' => ['companyname', 'company', 'organization', 'organisation', 'account', 'accountname', 'businessname'],
            'website' => ['website', 'websiteurl', 'companywebsite', 'domain', 'companydomain', 'url'],
            'source' => ['source', 'leadsource', 'channel', 'origin'],
            'estimated_value' => ['estimatedvalue', 'value', 'dealvalue', 'amount', 'estvalue', 'budget'],
            'estimated_value_cents' => ['estimatedvaluecents', 'valuecents', 'amountcents'],
            'currency' => ['currency', 'currencycode'],
        ];

        $usedHeaders = [];

        foreach ($aliases as $targetField => $normalizedAliases) {
            foreach ($headers as $header) {
                if (isset($usedHeaders[$header])) {
                    continue;
                }

                $normalizedHeader = preg_replace('/[^a-z0-9]/', '', mb_strtolower($header)) ?? '';
                if ($normalizedHeader !== '' && in_array($normalizedHeader, $normalizedAliases, true)) {
                    $mapping[$targetField] = $header;
                    $usedHeaders[$header] = true;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * @param  array<string, string|null>  $detected
     * @param  list<string>  $headers
     * @param  array<string, string|null>  $overrides
     * @return array<string, string|null>
     */
    protected function mergeMappingOverrides(array $detected, array $headers, array $overrides): array
    {
        $validHeaders = array_flip($headers);

        foreach ($overrides as $field => $headerName) {
            if (! array_key_exists($field, $detected)) {
                continue;
            }

            if ($headerName === null || trim((string) $headerName) === '') {
                $detected[$field] = null;
                continue;
            }

            $trimmedHeader = $this->stripUnsafeControlChars(trim((string) $headerName));
            if (isset($validHeaders[$trimmedHeader])) {
                $detected[$field] = $trimmedHeader;
            }
        }

        return $detected;
    }

    /**
     * @param  list<mixed>  $rawRow
     * @param  array<string, int>  $headerIndexMap
     * @param  array<string, string|null>  $mapping
     * @return array<string, mixed>
     */
    protected function mapSpreadsheetRow(array $rawRow, array $headerIndexMap, array $mapping): array
    {
        $readCell = function (string $field, bool $allowPhonePlus = false) use ($rawRow, $headerIndexMap, $mapping): ?string {
            $header = $mapping[$field] ?? null;
            if ($header === null || ! isset($headerIndexMap[$header])) {
                return null;
            }

            $raw = $rawRow[$headerIndexMap[$header]] ?? null;
            if ($raw === null) {
                return null;
            }

            $str = is_bool($raw) ? ($raw ? '1' : '0') : (string) $raw;

            return $this->sanitizeTextValue($str, $allowPhonePlus);
        };

        $firstName = $readCell('first_name');
        $lastName = $readCell('last_name');
        $fullName = $readCell('full_name');

        if (($firstName === null || $lastName === null) && $fullName !== null) {
            $parts = preg_split('/\s+/', $fullName) ?: [];
            if ($parts !== []) {
                $firstName ??= $parts[0];
                if ($lastName === null && count($parts) > 1) {
                    $lastName = implode(' ', array_slice($parts, 1));
                }
            }
        }

        $estimatedValueCents = null;
        $rawCents = $readCell('estimated_value_cents');
        $rawAmount = $readCell('estimated_value');

        if ($rawCents !== null) {
            $cleaned = preg_replace('/[,\s]/', '', $rawCents) ?? $rawCents;
            if (preg_match('/^\d{1,15}$/', $cleaned) === 1 && (int) $cleaned <= 999999999999999) {
                $estimatedValueCents = (int) $cleaned;
            } else {
                $estimatedValueCents = $rawCents;
            }
        } elseif ($rawAmount !== null) {
            $estimatedValueCents = $this->parseAmountToCents($rawAmount);
        }

        $source = $this->normalizeSource($readCell('source'));

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $readCell('email'),
            'phone' => $readCell('phone', allowPhonePlus: true),
            'job_title' => $readCell('job_title'),
            'company_name' => $readCell('company_name'),
            'website' => $readCell('website'),
            'source' => $source,
            'estimated_value_cents' => $estimatedValueCents,
            'currency' => strtoupper($readCell('currency') ?? 'USD'),
        ];
    }

    protected function sanitizeTextValue(string $raw, bool $allowPhonePlus): ?string
    {
        $cleaned = trim($this->stripUnsafeControlChars($raw));
        if ($cleaned === '') {
            return null;
        }

        // Defuse spreadsheet formula injection characters cleanly while preserving valid E.164 phones
        $isValidPhone = $allowPhonePlus && preg_match('/^\+[\d\s().-]{5,39}$/', $cleaned) === 1;
        if (! $isValidPhone && preg_match('/^[=+@\-\t\r\n]/', $cleaned) === 1) {
            $isPlainNumber = preg_match('/^-\d+(?:\.\d+)?$/', $cleaned) === 1;
            if (! $isPlainNumber) {
                $cleaned = trim(ltrim($cleaned, "=+@-\t\r\n"));
            }
        }

        return $cleaned === '' ? null : $cleaned;
    }

    protected function stripUnsafeControlChars(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
    }

    protected function parseAmountToCents(string $raw): int|string
    {
        $stripped = preg_replace('/[\s,$€£¥]/', '', trim($raw)) ?? '';
        if ($stripped === '') {
            return '';
        }

        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $stripped, $matches) !== 1) {
            return $raw;
        }

        $wholeDollars = ltrim($matches[1], '0');
        $wholeDollars = $wholeDollars === '' ? '0' : $wholeDollars;

        // Bound integer dollars <= 13 digits so total cents stays safely below PHP_INT_MAX and DTO cap
        if (strlen($wholeDollars) > 13) {
            return $raw;
        }

        $fraction = str_pad($matches[2] ?? '', 2, '0', STR_PAD_RIGHT);
        $cents = ((int) $wholeDollars * 100) + (int) $fraction;

        if ($cents > 999999999999999) {
            return $raw;
        }

        return $cents;
    }

    protected function normalizeSource(?string $rawSource): string
    {
        if ($rawSource === null || trim($rawSource) === '') {
            return LeadSourceEnum::Outbound->value;
        }

        $normalized = str_replace(['-', ' '], '_', mb_strtolower(trim($rawSource)));

        return match ($normalized) {
            'inbound', 'web', 'website', 'inbound_web', 'web_form' => LeadSourceEnum::InboundWeb->value,
            'outbound', 'cold', 'cold_email', 'research', 'prospecting' => LeadSourceEnum::Outbound->value,
            'referral', 'ref', 'word_of_mouth' => LeadSourceEnum::Referral->value,
            'event', 'conference', 'webinar', 'trade_show' => LeadSourceEnum::Event->value,
            'partner', 'channel_partner' => LeadSourceEnum::Partner->value,
            'other' => LeadSourceEnum::Other->value,
            default => $normalized,
        };
    }
}
