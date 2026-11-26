<?php

namespace App\Modules\Core\Sops\Actions;

use App\Modules\Core\Sops\Data\SopDocumentData;
use App\Modules\Core\Sops\Data\UpdateSopData;
use App\Modules\Core\Sops\Support\SopStorage;

class UpdateSopAction
{
    public function __construct(
        private readonly SopStorage $storage,
    ) {}

    public function execute(string $module, string $slug, UpdateSopData $data): SopDocumentData
    {
        return $this->storage->update(
            module: $module,
            slug: $slug,
            content: $data->content ?? '',
            expectedSha256: $data->expected_sha256,
        );
    }
}
