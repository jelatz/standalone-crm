<?php

namespace App\Modules\Core\Auth\Models;

use App\Modules\Core\Auth\Enums\OidcProviderEnum;
use App\Modules\Core\Users\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OauthIdentity extends Model
{
    use HasUuidV7;

    protected $table = 'oauth_identities';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'provider',
        'provider_tenant_id',
        'provider_user_id',
        'email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => OidcProviderEnum::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'immutable_datetime',
            'scopes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
