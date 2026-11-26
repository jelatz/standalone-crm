<?php

namespace App\Modules\Core\Sops\Exceptions;

use App\Modules\Core\Sops\Data\SopDocumentData;
use RuntimeException;

class SopConflictException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly SopDocumentData $currentDocument,
    ) {
        parent::__construct($message);
    }
}
