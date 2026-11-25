<?php

namespace App\Modules\Crm\CustomFields\Models;

use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\CustomFields\Enums\CustomFieldTypeEnum;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Crm\Pipelines\Models\PipelineStage;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CustomFieldDefinition extends Model
{
    use HasUuidV7;

    protected $table = 'custom_field_definitions';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'validation_rules' => '{}',
        'is_filterable' => true,
        'is_active' => true,
        'sort_order' => 0,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'entity_type',
        'pipeline_id',
        'key',
        'label',
        'field_type',
        'options',
        'validation_rules',
        'is_filterable',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity_type' => CustomFieldEntityTypeEnum::class,
            'field_type' => CustomFieldTypeEnum::class,
            'options' => 'array',
            'validation_rules' => 'array',
            'is_filterable' => 'boolean',
            'is_active' => 'boolean',
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
     * @return BelongsToMany<PipelineStage, $this>
     */
    public function requiredStages(): BelongsToMany
    {
        return $this->belongsToMany(
            PipelineStage::class,
            'stage_required_fields',
            'custom_field_definition_id',
            'pipeline_stage_id',
        );
    }
}
