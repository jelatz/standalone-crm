<?php

namespace App\Modules\Core\Sops\Support;

use App\Modules\Core\Sops\Data\SopDocumentData;
use App\Modules\Core\Sops\Data\SopModuleGroupData;
use App\Modules\Core\Sops\Data\SopSummaryData;
use App\Modules\Core\Sops\Exceptions\SopConflictException;
use App\Modules\Core\Sops\Exceptions\SopNotFoundException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SopStorage
{
    /**
     * @var list<string>
     */
    private const CORE_MODULE_ORDER = ['leads', 'contacts', 'companies', 'deals'];

    public function __construct(
        private readonly ?string $rootPath = null,
        private readonly ?int $maxBytes = null,
    ) {}

    public function isValidSlug(string $slug): bool
    {
        return strlen($slug) >= 1
            && strlen($slug) <= 100
            && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
    }

    public function normalizeSlug(string $value): string
    {
        $trimmed = trim($value);
        $withoutExtension = preg_replace('/\.md$/i', '', $trimmed) ?? $trimmed;

        return Str::slug($withoutExtension);
    }

    /**
     * @return list<SopModuleGroupData>
     */
    public function listModuleGroups(): array
    {
        $canonicalRoot = $this->resolveCanonicalRoot();
        $entries = scandir($canonicalRoot);

        if ($entries === false) {
            throw new RuntimeException('Unable to scan SOP directory.');
        }

        $modules = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            if (! $this->isValidSlug($entry)) {
                continue;
            }

            $moduleDir = $canonicalRoot . DIRECTORY_SEPARATOR . $entry;

            if (is_link($moduleDir) || ! is_dir($moduleDir)) {
                continue;
            }

            $resolvedDir = realpath($moduleDir);
            if ($resolvedDir === false || $resolvedDir !== $canonicalRoot . DIRECTORY_SEPARATOR . $entry) {
                continue;
            }

            $modules[] = $entry;
        }

        usort($modules, function (string $a, string $b): int {
            $posA = array_search($a, self::CORE_MODULE_ORDER, true);
            $posB = array_search($b, self::CORE_MODULE_ORDER, true);

            if ($posA !== false && $posB !== false) {
                return $posA <=> $posB;
            }
            if ($posA !== false) {
                return -1;
            }
            if ($posB !== false) {
                return 1;
            }

            return strcmp($a, $b);
        });

        $groups = [];

        foreach ($modules as $module) {
            $sops = $this->listModuleSops($canonicalRoot, $module);
            $groups[] = new SopModuleGroupData(
                module: $module,
                label: Str::headline($module),
                sops: $sops,
            );
        }

        return $groups;
    }

    public function read(string $module, string $slug): SopDocumentData
    {
        [$canonicalRoot, $filePath] = $this->resolveExistingFilePath($module, $slug);

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new SopNotFoundException("Unable to read SOP [{$module}/{$slug}].");
        }

        $summary = $this->buildSummary($module, $slug, $filePath, $content);

        return new SopDocumentData(
            module: $summary->module,
            slug: $summary->slug,
            filename: $summary->filename,
            relative_path: $summary->relative_path,
            title: $summary->title,
            size_bytes: $summary->size_bytes,
            updated_at: $summary->updated_at,
            sha256: $summary->sha256,
            content: $content,
        );
    }

    public function create(string $moduleInput, string $nameInput, string $content): SopDocumentData
    {
        $this->assertSafeDisplayInput($moduleInput, 'module');
        $this->assertSafeDisplayInput($nameInput, 'name');

        $module = $this->normalizeSlug($moduleInput);
        $slug = $this->normalizeSlug($nameInput);

        $errors = [];

        if (! $this->isValidSlug($module)) {
            $errors['module'] = ['Module name must contain at least one letter or number.'];
        }

        if (! $this->isValidSlug($slug)) {
            $errors['name'] = ['SOP name must contain at least one letter or number.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $normalizedContent = $this->normalizeAndValidateContent($content);
        $canonicalRoot = $this->resolveCanonicalRoot();
        $moduleDir = $canonicalRoot . DIRECTORY_SEPARATOR . $module;

        if (is_link($moduleDir)) {
            throw ValidationException::withMessages([
                'module' => ['Target module path cannot be a symbolic link.'],
            ]);
        }

        if (file_exists($moduleDir) && ! is_dir($moduleDir)) {
            throw ValidationException::withMessages([
                'module' => ['A non-directory file already exists at this module path.'],
            ]);
        }

        if (! is_dir($moduleDir) && ! mkdir($moduleDir, 0755, false) && ! is_dir($moduleDir)) {
            throw new RuntimeException("Unable to create SOP module directory [{$module}].");
        }

        $resolvedModuleDir = realpath($moduleDir);
        if ($resolvedModuleDir === false || $resolvedModuleDir !== $canonicalRoot . DIRECTORY_SEPARATOR . $module) {
            throw new RuntimeException('Resolved SOP module directory escaped canonical root.');
        }

        $filePath = $resolvedModuleDir . DIRECTORY_SEPARATOR . $slug . '.md';

        if (file_exists($filePath) || is_link($filePath)) {
            throw ValidationException::withMessages([
                'name' => ['An SOP with this name already exists in this module.'],
            ]);
        }

        $this->writeAtomic($resolvedModuleDir, $filePath, $normalizedContent);

        return $this->read($module, $slug);
    }

    public function update(string $module, string $slug, string $content, string $expectedSha256): SopDocumentData
    {
        $current = $this->read($module, $slug);

        if (! hash_equals($current->sha256, $expectedSha256)) {
            throw new SopConflictException(
                'This SOP was modified on disk since you opened it. Reload the latest version before saving.',
                $current,
            );
        }

        $normalizedContent = $this->normalizeAndValidateContent($content);
        [, $filePath, $resolvedModuleDir] = $this->resolveExistingFilePath($module, $slug);

        $this->writeAtomic($resolvedModuleDir, $filePath, $normalizedContent);

        return $this->read($module, $slug);
    }

    private function resolveCanonicalRoot(): string
    {
        $root = $this->rootPath ?? (string) config('sops.root_path', base_path('context/sops'));

        if (is_link($root)) {
            throw new RuntimeException('SOP root path cannot be a symbolic link.');
        }

        if (! file_exists($root)) {
            if (! mkdir($root, 0755, true) && ! is_dir($root)) {
                throw new RuntimeException('Unable to create SOP root directory.');
            }
        }

        if (! is_dir($root)) {
            throw new RuntimeException('Configured SOP root path is not a directory.');
        }

        $canonicalRoot = realpath($root);
        if ($canonicalRoot === false) {
            throw new RuntimeException('Unable to resolve canonical SOP root path.');
        }

        return $canonicalRoot;
    }

    /**
     * @return list<SopSummaryData>
     */
    private function listModuleSops(string $canonicalRoot, string $module): array
    {
        $moduleDir = $canonicalRoot . DIRECTORY_SEPARATOR . $module;
        $entries = scandir($moduleDir);

        if ($entries === false) {
            return [];
        }

        $slugs = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            if (! str_ends_with(strtolower($entry), '.md')) {
                continue;
            }

            $slug = substr($entry, 0, -3);
            if (! $this->isValidSlug($slug)) {
                continue;
            }

            $filePath = $moduleDir . DIRECTORY_SEPARATOR . $entry;
            if (is_link($filePath) || ! is_file($filePath)) {
                continue;
            }

            $resolvedFile = realpath($filePath);
            if ($resolvedFile === false || $resolvedFile !== $moduleDir . DIRECTORY_SEPARATOR . $entry) {
                continue;
            }

            $slugs[] = $slug;
        }

        sort($slugs, SORT_STRING);

        $sops = [];

        foreach ($slugs as $slug) {
            $filePath = $moduleDir . DIRECTORY_SEPARATOR . $slug . '.md';
            $content = file_get_contents($filePath);

            if ($content === false) {
                continue;
            }

            $sops[] = $this->buildSummary($module, $slug, $filePath, $content);
        }

        return $sops;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveExistingFilePath(string $module, string $slug): array
    {
        if (! $this->isValidSlug($module) || ! $this->isValidSlug($slug)) {
            throw new SopNotFoundException('SOP not found.');
        }

        $canonicalRoot = $this->resolveCanonicalRoot();
        $moduleDir = $canonicalRoot . DIRECTORY_SEPARATOR . $module;

        if (is_link($moduleDir) || ! is_dir($moduleDir)) {
            throw new SopNotFoundException("SOP module [{$module}] not found.");
        }

        $resolvedModuleDir = realpath($moduleDir);
        if ($resolvedModuleDir === false || $resolvedModuleDir !== $canonicalRoot . DIRECTORY_SEPARATOR . $module) {
            throw new SopNotFoundException("SOP module [{$module}] not found.");
        }

        $filename = $slug . '.md';
        $filePath = $resolvedModuleDir . DIRECTORY_SEPARATOR . $filename;

        if (is_link($filePath) || ! is_file($filePath)) {
            throw new SopNotFoundException("SOP [{$module}/{$slug}] not found.");
        }

        $resolvedFile = realpath($filePath);
        if ($resolvedFile === false || $resolvedFile !== $resolvedModuleDir . DIRECTORY_SEPARATOR . $filename) {
            throw new SopNotFoundException("SOP [{$module}/{$slug}] not found.");
        }

        return [$canonicalRoot, $resolvedFile, $resolvedModuleDir];
    }

    private function buildSummary(string $module, string $slug, string $filePath, string $content): SopSummaryData
    {
        $filename = $slug . '.md';
        $mtime = filemtime($filePath);
        $updatedAt = CarbonImmutable::createFromTimestampUTC($mtime !== false ? $mtime : time())
            ->toIso8601String();

        return new SopSummaryData(
            module: $module,
            slug: $slug,
            filename: $filename,
            relative_path: "context/sops/{$module}/{$filename}",
            title: $this->extractTitle($content, $slug),
            size_bytes: strlen($content),
            updated_at: $updatedAt,
            sha256: hash('sha256', $content),
        );
    }

    private function extractTitle(string $content, string $fallbackSlug): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $index = 0;

        if (isset($lines[0]) && trim($lines[0]) === '---') {
            $index = 1;
            $count = count($lines);
            while ($index < $count && trim($lines[$index]) !== '---') {
                $index++;
            }
            $index++;
        }

        $total = count($lines);
        for (; $index < $total; $index++) {
            $line = trim($lines[$index]);
            if (preg_match('/^#\s+(.+)$/', $line, $matches) === 1) {
                $heading = trim($matches[1]);
                if ($heading !== '') {
                    return $heading;
                }
            }
        }

        return Str::headline($fallbackSlug);
    }

    private function assertSafeDisplayInput(string $value, string $field): void
    {
        if (
            str_contains($value, "\0")
            || str_contains($value, '/')
            || str_contains($value, '\\')
            || str_contains($value, '..')
        ) {
            throw ValidationException::withMessages([
                $field => ['Invalid characters in name. Avoid slashes or path traversal sequences.'],
            ]);
        }
    }

    private function normalizeAndValidateContent(string $content): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);

        if (! mb_check_encoding($normalized, 'UTF-8')) {
            throw ValidationException::withMessages([
                'content' => ['SOP content must be valid UTF-8 text.'],
            ]);
        }

        $maxBytes = $this->maxBytes ?? (int) config('sops.max_bytes', 524288);

        if (strlen($normalized) > $maxBytes) {
            throw ValidationException::withMessages([
                'content' => ["SOP content exceeds the maximum size of {$maxBytes} bytes."],
            ]);
        }

        return $normalized;
    }

    private function writeAtomic(string $directory, string $targetPath, string $content): void
    {
        $tempPath = tempnam($directory, '.sop-tmp-');
        if ($tempPath === false) {
            throw new RuntimeException('Unable to allocate temporary file for SOP write.');
        }

        try {
            $written = file_put_contents($tempPath, $content, LOCK_EX);
            if ($written === false) {
                throw new RuntimeException('Unable to write SOP temporary file.');
            }

            @chmod($tempPath, 0644);

            if (! rename($tempPath, $targetPath)) {
                throw new RuntimeException('Unable to finalize SOP file write.');
            }
        } catch (\Throwable $e) {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            throw $e;
        }
    }
}
