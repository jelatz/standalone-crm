<?php

namespace App\Modules\Crm\Pipelines\Models;

use App\Modules\Core\Users\Models\Department;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use App\Modules\Crm\Deals\Models\Deal;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pipeline extends Model
{
    use HasUuidV7;

    protected $table = 'pipelines';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 0,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'department_id',
        'is_default',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * @return HasMany<PipelineStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class, 'pipeline_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<CustomFieldDefinition, $this>
     */
    public function customFieldDefinitions(): HasMany
    {
        return $this->hasMany(CustomFieldDefinition::class, 'pipeline_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'pipeline_id');
    }
}
