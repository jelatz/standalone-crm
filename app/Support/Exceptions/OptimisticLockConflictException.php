<?php

namespace App\Support\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class OptimisticLockConflictException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $entity
     */
    public function __construct(
        public readonly int $currentVersion,
        public readonly array $entity = [],
        string $message = 'The record was modified by another user. Refresh and try again.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 409, $previous);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'current_version' => $this->currentVersion,
            'entity' => $this->entity,
        ], 409);
    }
}
