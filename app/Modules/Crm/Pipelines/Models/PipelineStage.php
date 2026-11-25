<?php

namespace App\Modules\Crm\Pipelines\Models;

use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Pipelines\Enums\PipelineStageTypeEnum;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineStage extends Model
{
    use HasUuidV7;

    protected $table = 'pipeline_stages';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'stage_type' => PipelineStageTypeEnum::Open->value,
        'win_probability' => 10,
        'required_core_fields' => '[]',
        'sort_order' => 0,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pipeline_id',
        'name',
        'stage_type',
        'win_probability',
        'rotting_days',
        'required_core_fields',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage_type' => PipelineStageTypeEnum::class,
            'win_probability' => 'integer',
            'rotting_days' => 'integer',
            'required_core_fields' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Pipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }

    /**
     * @return BelongsToMany<CustomFieldDefinition, $this>
     */
    public function requiredCustomFields(): BelongsToMany
    {
        return $this->belongsToMany(
            CustomFieldDefinition::class,
            'stage_required_fields',
            'pipeline_stage_id',
            'custom_field_definition_id',
        );
    }

    /**
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'stage_id');
    }
}
