<?php

namespace App\Modules\Projects\Handoffs\Support;

use App\Modules\Projects\Handoffs\Enums\DealHandoffStatusEnum;
use Illuminate\Validation\ValidationException;

final class DealHandoffStateMachine
{
    /**
     * @var array<string, list<string>>
     */
    private const array ALLOWED_TRANSITIONS = [
        DealHandoffStatusEnum::PendingReview->value => [
            DealHandoffStatusEnum::ChangesRequested->value,
            DealHandoffStatusEnum::Approved->value,
            DealHandoffStatusEnum::Rejected->value,
        ],
        DealHandoffStatusEnum::ChangesRequested->value => [
            DealHandoffStatusEnum::PendingReview->value,
        ],
        DealHandoffStatusEnum::Approved->value => [
            DealHandoffStatusEnum::Provisioned->value,
        ],
        DealHandoffStatusEnum::Provisioned->value => [],
        DealHandoffStatusEnum::Rejected->value => [],
    ];

    public static function canTransition(DealHandoffStatusEnum $from, DealHandoffStatusEnum $to): bool
    {
        return in_array($to->value, self::ALLOWED_TRANSITIONS[$from->value] ?? [], true);
    }

    public static function assertCanTransition(DealHandoffStatusEnum $from, DealHandoffStatusEnum $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition handoff from [{$from->value}] to [{$to->value}]."],
            ]);
        }
    }
}
