<?php

namespace App\Modules\Projects\Handoffs\Support;

use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Notes\Actions\SanitizeTipTapAstAction;
use App\Modules\Projects\Handoffs\Data\SubmitHandoffData;
use Illuminate\Validation\ValidationException;

final class HandoffPayloadNormalizer
{
    public function __construct(
        private readonly SanitizeTipTapAstAction $sanitizer,
    ) {}

    /**
     * @return array{
     *     scope_notes_json: array<string, mixed>,
     *     handoff_checklist: list<array{label: string, checked: bool}>,
     *     target_kickoff_date: ?string,
     *     assigned_am_id: ?string
     * }
     */
    public function normalizeSubmission(SubmitHandoffData $data): array
    {
        $sanitizedNotes = $this->sanitizeScopeNotes($data->scope_notes_json);
        $checklist = $this->normalizeChecklist($data->handoff_checklist);
        $assignedAmId = $this->validateOptionalActiveUser($data->assigned_am_id, 'assigned_am_id', 'Selected account manager must be an active employee.');

        return [
            'scope_notes_json' => $sanitizedNotes,
            'handoff_checklist' => $checklist,
            'target_kickoff_date' => $data->target_kickoff_date,
            'assigned_am_id' => $assignedAmId,
        ];
    }

    /**
     * @param  array<mixed, mixed>  $rawScopeNotes
     * @return array<string, mixed>
     */
    public function sanitizeScopeNotes(array $rawScopeNotes): array
    {
        try {
            $sanitized = $this->sanitizer->execute($rawScopeNotes, requireNonEmpty: true);

            return $sanitized['body_json'];
        } catch (ValidationException $e) {
            $errors = $e->errors();

            throw ValidationException::withMessages([
                'scope_notes_json' => $errors['body_json'] ?? ['Scope notes content must be a valid non-empty TipTap doc document.'],
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<array{label: string, checked: bool}>
     */
    public function normalizeChecklist(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $normalized[] = [
                'label' => $label,
                'checked' => (bool) ($item['checked'] ?? false),
            ];
        }

        return $normalized;
    }

    public function validateOptionalActiveUser(?string $userId, string $field, string $message): ?string
    {
        if ($userId === null || trim($userId) === '') {
            return null;
        }

        $trimmed = trim($userId);

        $exists = User::query()
            ->whereKey($trimmed)
            ->where('status', UserStatusEnum::Active->value)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                $field => [$message],
            ]);
        }

        return $trimmed;
    }
}
