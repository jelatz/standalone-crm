<?php

namespace App\Modules\Core\Sops\Actions;

use App\Modules\Core\Sops\Data\SopModuleGroupData;
use App\Modules\Core\Sops\Support\SopStorage;

class ListSopsAction
{
    public function __construct(
        private readonly SopStorage $storage,
    ) {}

    /**
     * @return list<SopModuleGroupData>
     */
    public function execute(): array
    {
        return $this->storage->listModuleGroups();
    }
}
