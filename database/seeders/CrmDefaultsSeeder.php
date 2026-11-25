<?php

namespace Database\Seeders;

use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\CustomFields\Enums\CustomFieldTypeEnum;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use App\Modules\Crm\Pipelines\Enums\PipelineStageTypeEnum;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Crm\Pipelines\Models\PipelineStage;
use Illuminate\Database\Seeder;

class CrmDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $newBusiness = Pipeline::query()->updateOrCreate(
            ['slug' => 'new-business'],
            [
                'name' => 'New Business',
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 1,
            ],
        );

        $newBusinessStages = $this->seedStages($newBusiness, [
            [
                'name' => 'Qualification',
                'stage_type' => PipelineStageTypeEnum::Open,
                'win_probability' => 10,
                'rotting_days' => 14,
                'required_core_fields' => [],
                'sort_order' => 1,
            ],
            [
                'name' => 'Discovery',
                'stage_type' => PipelineStageTypeEnum::Open,
                'win_probability' => 25,
                'rotting_days' => 14,
                'required_core_fields' => [],
                'sort_order' => 2,
            ],
            [
                'name' => 'Proposal',
                'stage_type' => PipelineStageTypeEnum::Open,
                'win_probability' => 50,
                'rotting_days' => 21,
                'required_core_fields' => [],
                'sort_order' => 3,
            ],
            [
                'name' => 'Negotiation',
                'stage_type' => PipelineStageTypeEnum::Open,
                'win_probability' => 75,
                'rotting_days' => 21,
                'required_core_fields' => ['expected_close_date', 'amount_cents'],
                'sort_order' => 4,
            ],
            [
                'name' => 'Closed Won',
                'stage_type' => PipelineStageTypeEnum::Won,
                'win_probability' => 100,
                'rotting_days' => null,
                'required_core_fields' => [],
                'sort_order' => 5,
            ],
            [
                'name' => 'Closed Lost',
                'stage_type' => PipelineStageTypeEnum::Lost,
                'win_probability' => 0,
                'rotting_days' => null,
                'required_core_fields' => [],
                'sort_order' => 6,
            ],
        ]);

        $renewal = Pipeline::query()->updateOrCreate(
            ['slug' => 'renewal-upsell'],
            [
                'name' => 'Renewal / Upsell',
                'is_default' => false,
                'is_active' => true,
                'sort_order' => 2,
            ],
        );

        $this->seedStages($renewal, [
            [
                'name' => 'Upcoming Renewal',
                'stage_type' => PipelineStageTypeEnum::Open,
                'win_probability' => 25,
                'rotting_days' => 30,
                'required_core_fields' => [],
                'sort_order' => 1,
            ],
            [
                'name' => 'Account Review',
                'stage_type' => PipelineStageTypeEnum::Open,
                'win_probability' => 50,
                'rotting_days' => 14,
                'required_core_fields' => [],
                'sort_order' => 2,
            ],
            [
                'name' => 'Proposal & Terms',
                'stage_type' => PipelineStageTypeEnum::Open,
                'win_probability' => 75,
                'rotting_days' => 14,
                'required_core_fields' => ['expected_close_date', 'amount_cents'],
                'sort_order' => 3,
            ],
            [
                'name' => 'Closed Won',
                'stage_type' => PipelineStageTypeEnum::Won,
                'win_probability' => 100,
                'rotting_days' => null,
                'required_core_fields' => [],
                'sort_order' => 4,
            ],
            [
                'name' => 'Churned / Lost',
                'stage_type' => PipelineStageTypeEnum::Lost,
                'win_probability' => 0,
                'rotting_days' => null,
                'required_core_fields' => [],
                'sort_order' => 5,
            ],
        ]);

        CustomFieldDefinition::query()->updateOrCreate(
            [
                'entity_type' => CustomFieldEntityTypeEnum::Deal->value,
                'key' => 'contract_term_months',
            ],
            [
                'pipeline_id' => null,
                'label' => 'Contract Term (Months)',
                'field_type' => CustomFieldTypeEnum::Number,
                'options' => null,
                'validation_rules' => ['min' => 1, 'max' => 120],
                'is_filterable' => true,
                'is_active' => true,
                'sort_order' => 1,
            ],
        );

        $deploymentRegion = CustomFieldDefinition::query()->updateOrCreate(
            [
                'entity_type' => CustomFieldEntityTypeEnum::Deal->value,
                'key' => 'deployment_region',
            ],
            [
                'pipeline_id' => null,
                'label' => 'Deployment Region',
                'field_type' => CustomFieldTypeEnum::Select,
                'options' => [
                    ['value' => 'us_east', 'label' => 'US East'],
                    ['value' => 'us_west', 'label' => 'US West'],
                    ['value' => 'eu_central', 'label' => 'EU Central'],
                    ['value' => 'ap_southeast', 'label' => 'AP Southeast'],
                ],
                'validation_rules' => [],
                'is_filterable' => true,
                'is_active' => true,
                'sort_order' => 2,
            ],
        );

        CustomFieldDefinition::query()->updateOrCreate(
            [
                'entity_type' => CustomFieldEntityTypeEnum::Company->value,
                'key' => 'tier',
            ],
            [
                'pipeline_id' => null,
                'label' => 'Account Tier',
                'field_type' => CustomFieldTypeEnum::Select,
                'options' => [
                    ['value' => 'enterprise', 'label' => 'Enterprise'],
                    ['value' => 'mid_market', 'label' => 'Mid-Market'],
                    ['value' => 'smb', 'label' => 'SMB'],
                ],
                'validation_rules' => [],
                'is_filterable' => true,
                'is_active' => true,
                'sort_order' => 1,
            ],
        );

        CustomFieldDefinition::query()->updateOrCreate(
            [
                'entity_type' => CustomFieldEntityTypeEnum::Contact->value,
                'key' => 'linkedin_url',
            ],
            [
                'pipeline_id' => null,
                'label' => 'LinkedIn Profile',
                'field_type' => CustomFieldTypeEnum::Url,
                'options' => null,
                'validation_rules' => [],
                'is_filterable' => false,
                'is_active' => true,
                'sort_order' => 1,
            ],
        );

        $closedWonStage = $newBusinessStages['Closed Won'] ?? null;
        if ($closedWonStage !== null) {
            $closedWonStage->requiredCustomFields()->syncWithoutDetaching([$deploymentRegion->id]);
        }
    }

    /**
     * @param  list<array{
     *     name: string,
     *     stage_type: PipelineStageTypeEnum,
     *     win_probability: int,
     *     rotting_days: ?int,
     *     required_core_fields: list<string>,
     *     sort_order: int
     * }>  $stages
     * @return array<string, PipelineStage>
     */
    protected function seedStages(Pipeline $pipeline, array $stages): array
    {
        $seeded = [];

        foreach ($stages as $stageData) {
            $seeded[$stageData['name']] = PipelineStage::query()->updateOrCreate(
                [
                    'pipeline_id' => $pipeline->id,
                    'name' => $stageData['name'],
                ],
                [
                    'stage_type' => $stageData['stage_type'],
                    'win_probability' => $stageData['win_probability'],
                    'rotting_days' => $stageData['rotting_days'],
                    'required_core_fields' => $stageData['required_core_fields'],
                    'sort_order' => $stageData['sort_order'],
                ],
            );
        }

        return $seeded;
    }
}
