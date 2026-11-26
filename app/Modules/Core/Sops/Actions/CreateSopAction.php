<?php

namespace App\Modules\Core\Sops\Actions;

use App\Modules\Core\Sops\Data\CreateSopData;
use App\Modules\Core\Sops\Data\SopDocumentData;
use App\Modules\Core\Sops\Support\SopStorage;

class CreateSopAction
{
    public function __construct(
        private readonly SopStorage $storage,
    ) {}

    public function execute(CreateSopData $data): SopDocumentData
    {
        return $this->storage->create(
            moduleInput: $data->module,
            nameInput: $data->name,
            content: $data->content ?? '',
        );
    }
}
