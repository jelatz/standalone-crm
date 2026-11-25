<?php

namespace App\Modules\Core\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserHierarchyClosure extends Model
{
    protected $table = 'user_hierarchy_closures';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ancestor_id',
        'descendant_id',
        'depth',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'depth' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function ancestor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ancestor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function descendant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'descendant_id');
    }
}
