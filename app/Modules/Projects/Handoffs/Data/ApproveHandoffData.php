<?php

namespace App\Modules\Projects\Handoffs\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ApproveHandoffData extends Data
{
    public function __construct(
        public string $project_template_id,
        public string $assigned_pm_id,
        public string|Optional|null $assigned_am_id = new Optional,
        public string|Optional|null $project_name = new Optional,
        public string|Optional|null $target_kickoff_date = new Optional,
        public string|Optional|null $review_feedback = new Optional,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'project_template_id' => ['required', 'uuid'],
            'assigned_pm_id' => ['required', 'uuid'],
            'assigned_am_id' => ['sometimes', 'nullable', 'uuid'],
            'project_name' => ['sometimes', 'nullable', 'string', 'min:1', 'max:200'],
            'target_kickoff_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'review_feedback' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
