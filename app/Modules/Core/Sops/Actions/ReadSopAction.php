<?php

namespace App\Modules\Core\Sops\Actions;

use App\Modules\Core\Sops\Data\SopDocumentData;
use App\Modules\Core\Sops\Support\SopStorage;

class ReadSopAction
{
    public function __construct(
        private readonly SopStorage $storage,
    ) {}

    public function execute(string $module, string $slug): SopDocumentData
    {
        return $this->storage->read($module, $slug);
    }
}
