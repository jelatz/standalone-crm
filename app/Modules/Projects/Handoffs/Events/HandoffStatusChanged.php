<?php

namespace App\Modules\Projects\Handoffs\Events;

use App\Modules\Projects\Handoffs\Enums\DealHandoffStatusEnum;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

final class HandoffStatusChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly string $recipientUserId,
        public readonly string $handoffId,
        public readonly DealHandoffStatusEnum $status,
        public readonly string $dealId,
    ) {}

    public function broadcastQueue(): string
    {
        return 'critical-realtime';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->recipientUserId}"),
        ];
    }

    /**
     * Broadcast strictly IDs and status — never expose deal title or client names.
     *
     * @return array{
     *     handoff_id: string,
     *     status: string,
     *     deal_id: string,
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'handoff_id' => $this->handoffId,
            'status' => $this->status->value,
            'deal_id' => $this->dealId,
        ];
    }
}
