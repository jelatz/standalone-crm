<?php

namespace App\Support\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

trait HasUuidV7
{
    use HasUuids;

    /**
     * Generate a time-ordered UUIDv7 identifier for B-tree locality.
     */
    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
