<?php

namespace App\Modules\Crm\Deals\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

final class SiloBoardInvalidated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly string $recipientUserId,
        public readonly string $dealId,
        public readonly string $pipelineId,
        public readonly string $stageId,
        public readonly float $boardPosition,
        public readonly int $version,
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
     * Broadcast strictly IDs, stage, board position, and version — never titles, amounts, or company names.
     *
     * @return array{
     *     entity_type: string,
     *     entity_id: string,
     *     pipeline_id: string,
     *     stage_id: string,
     *     board_position: float,
     *     version: int,
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'entity_type' => 'deal',
            'entity_id' => $this->dealId,
            'pipeline_id' => $this->pipelineId,
            'stage_id' => $this->stageId,
            'board_position' => $this->boardPosition,
            'version' => $this->version,
        ];
    }
}
