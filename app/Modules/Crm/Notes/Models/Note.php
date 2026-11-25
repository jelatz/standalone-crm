<?php

namespace App\Modules\Crm\Notes\Models;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Timeline\Models\Activity;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Note extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'notes';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_pinned' => false,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'author_id',
        'body_json',
        'body_text',
        'is_pinned',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'body_json' => 'array',
            'is_pinned' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'source');
    }
}
