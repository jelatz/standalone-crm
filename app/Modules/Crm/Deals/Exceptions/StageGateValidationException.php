<?php

namespace App\Modules\Crm\Deals\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class StageGateValidationException extends RuntimeException
{
    /**
     * @param  list<string>  $missingCoreFields
     * @param  list<string>  $missingCustomFields
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        public readonly array $missingCoreFields,
        public readonly array $missingCustomFields,
        public readonly array $errors,
        string $message = 'Stage gate validation failed: complete all required fields before moving to this stage.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 422, $previous);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => $this->errors,
            'missing_core_fields' => $this->missingCoreFields,
            'missing_custom_fields' => $this->missingCustomFields,
        ], 422);
    }
}
