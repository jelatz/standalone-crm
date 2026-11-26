<?php

namespace App\Modules\Projects\Tasks\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

final class TaskBoardChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly string $projectId,
        public readonly string $taskId,
        public readonly ?string $projectStageId,
        public readonly float $boardPosition,
        public readonly int $version,
        public readonly string $status,
    ) {}

    public function broadcastQueue(): string
    {
        return 'critical-realtime';
    }

    /**
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("project-board.{$this->projectId}"),
        ];
    }

    /**
     * Broadcast strictly IDs, stage, board position, version, and status — never expose task titles or descriptions.
     *
     * @return array{
     *     task_id: string,
     *     project_id: string,
     *     project_stage_id: ?string,
     *     board_position: float,
     *     version: int,
     *     status: string,
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'task_id' => $this->taskId,
            'project_id' => $this->projectId,
            'project_stage_id' => $this->projectStageId,
            'board_position' => $this->boardPosition,
            'version' => $this->version,
            'status' => $this->status,
        ];
    }
}
