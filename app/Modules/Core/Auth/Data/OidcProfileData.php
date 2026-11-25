<?php

namespace App\Modules\Core\Auth\Data;

use App\Modules\Core\Auth\Enums\OidcProviderEnum;
use Spatie\LaravelData\Data;

class OidcProfileData extends Data
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public OidcProviderEnum $provider,
        public string $providerUserId,
        public string $providerTenantId,
        public string $email,
        public bool $emailVerified,
        public string $name,
        public string $accessToken,
        public ?string $refreshToken = null,
        public ?int $expiresIn = null,
        public array $scopes = [],
    ) {}
}
