<?php

use App\Modules\Core\Rbac\Actions\GrantEntityCollaboratorAction;
use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\CollaboratorRoleLabel;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\CustomFields\Actions\UpsertCustomFieldDefinitionAction;
use App\Modules\Crm\CustomFields\Actions\ValidateEntityCustomFieldsAction;
use App\Modules\Crm\CustomFields\Data\UpsertCustomFieldDefinitionData;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\CustomFields\Enums\CustomFieldTypeEnum;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Notes\Actions\CreateNoteAction;
use App\Modules\Crm\Notes\Actions\SanitizeTipTapAstAction;
use App\Modules\Crm\Notes\Actions\TogglePinNoteAction;
use App\Modules\Crm\Notes\Actions\UpdateNoteAction;
use App\Modules\Crm\Notes\Data\CreateNoteData;
use App\Modules\Crm\Notes\Data\NoteTargetData;
use App\Modules\Crm\Notes\Data\UpdateNoteData;
use App\Modules\Crm\Notes\Models\Note;
use App\Modules\Crm\Pipelines\Enums\PipelineStageTypeEnum;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Crm\Pipelines\Models\PipelineStage;
use App\Modules\Crm\Timeline\Actions\QueryEntityTimelineAction;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Crm\Timeline\Models\Activity;
use App\Modules\Crm\Timeline\Models\ActivityLink;
use Database\Seeders\CrmDefaultsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

function createTask12User(
    Department $department,
    string $code,
    string $email,
    RoleEnum $role,
    ?User $manager = null,
    UserStatusEnum $status = UserStatusEnum::Active,
): User {
    $user = User::query()->create([
        'employee_code' => $code,
        'name' => $code,
        'email' => $email,
        'department_id' => $department->id,
        'manager_id' => $manager?->id,
        'status' => $status,
    ]);

    $user->assignRole($role->value);
    app(RebuildUserHierarchyClosureAction::class)->execute($user);

    return $user;
}

beforeEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(CrmDefaultsSeeder::class);
});

afterEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('validates all 10 CustomFieldTypeEnum types, pipeline scoping, required rules, and rejects invalid payloads', function (): void {
    $dept = Department::query()->create(['name' => 'Revenue Ops', 'slug' => 't12-rev-ops']);
    $activeAssignee = createTask12User($dept, 'EMP-CF-1', 'cf.active@acme.internal', RoleEnum::Sales);
    $suspendedAssignee = createTask12User($dept, 'EMP-CF-2', 'cf.susp@acme.internal', RoleEnum::Sales, null, UserStatusEnum::Suspended);
    $trashedAssignee = createTask12User($dept, 'EMP-CF-3', 'cf.del@acme.internal', RoleEnum::Sales);
    $trashedAssignee->delete();

    $pipelineA = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $pipelineB = Pipeline::query()->where('slug', 'renewal-upsell')->firstOrFail();

    $defs = [];
    $defs['short_code'] = CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityTypeEnum::Deal,
        'key' => 'short_code',
        'label' => 'Short Code',
        'field_type' => CustomFieldTypeEnum::Text,
        'validation_rules' => ['required' => true, 'max_length' => 12],
        'sort_order' => 10,
    ]);
    $defs['summary_notes'] = CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityTypeEnum::Deal,
        'key' => 'summary_notes',
        'label' => 'Summary Notes',
        'field_type' => CustomFieldTypeEnum::Textarea,
        'validation_rules' => ['max_length' => 200],
        'sort_order' => 11,
    ]);
    $defs['seat_count'] = CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityTypeEnum::Deal,
        'key' => 'seat_count',
        'label' => 'Seat Count',
        'field_type' => CustomFieldTypeEnum::Number,
        'validation_rules' => ['min' => 1, 'max' => 5000],
        'sort_order' => 12,
    ]);
    $defs['implementation_fee'] = CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityTypeEnum::Deal,
        'key' => 'implementation_fee',
        'label' => 'Implementation Fee',
        'field_type' => CustomFieldTypeEnum::Currency,
        'validation_rules' => ['min' => 0, 'max' => 10000000],
        'sort_order' => 13,
    ]);
    $defs['security_reviewed'] = CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityTypeEnum::Deal,
        'key' => 'security_reviewed',
        'label' => 'Security Reviewed',
        'field_type' => CustomFieldTypeEnum::Boolean,
        'validation_rules' => ['required' => true],
        'sort_order' => 14,
    ]);
    $defs['go_live_date'] = CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityTypeEnum::Deal,
        'key' => 'go_live_date',
        'label' => 'Go-Live Date',
        'field_type' => CustomFieldTypeEnum::Date,
        'sort_order' => 15,
    ]);
    $defs['compliance_tags'] = CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityTypeEnum::Deal,
        'key' => 'compliance_tags',
        'label' => 'Compliance Tags',
        'field_type' => CustomFieldTypeEnum::MultiSelect,
        'options' => [
            ['value' => 'soc2', 'label' => 'SOC 2'],
            ['value' => 'hipaa', 'label' => 'HIPAA'],
            ['value' => 'iso27001', 'label' => 'ISO 27001'],
        ],
        'sort_order' => 16,
    ]);
    $defs['solutions_architect'] = CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityTypeEnum::Deal,
        'key' => 'solutions_architect',
        'label' => 'Solutions Architect',
        'field_type' => CustomFieldTypeEnum::UserId,
        'sort_order' => 17,
    ]);
    $defs['rfp_portal_url'] = CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityTypeEnum::Deal,
        'key' => 'rfp_portal_url',
        'label' => 'RFP Portal URL',
        'field_type' => CustomFieldTypeEnum::Url,
        'sort_order' => 18,
    ]);
    $defs['pipeline_a_only'] = CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityTypeEnum::Deal,
        'pipeline_id' => $pipelineA->id,
        'key' => 'pipeline_a_only',
        'label' => 'Pipeline A Scope',
        'field_type' => CustomFieldTypeEnum::Text,
        'sort_order' => 19,
    ]);

    $validator = app(ValidateEntityCustomFieldsAction::class);

    $validInput = [
        'short_code' => 'ENT-2026',
        'summary_notes' => "Multi-site rollout\nPhase 1",
        'seat_count' => '250',
        'implementation_fee' => 150000,
        'security_reviewed' => false,
        'go_live_date' => '2026-12-15',
        'deployment_region' => 'us_east',
        'compliance_tags' => ['soc2', 'iso27001'],
        'solutions_architect' => $activeAssignee->id,
        'rfp_portal_url' => 'https://rfp.northwind.internal/deal/42',
        'pipeline_a_only' => 'inbound',
    ];

    $normalized = $validator->execute(
        entityType: CustomFieldEntityTypeEnum::Deal,
        values: $validInput,
        pipelineId: $pipelineA->id,
    );

    expect($normalized)->toMatchArray([
        'short_code' => 'ENT-2026',
        'seat_count' => 250,
        'implementation_fee' => 150000,
        'security_reviewed' => false,
        'go_live_date' => '2026-12-15',
        'deployment_region' => 'us_east',
        'compliance_tags' => ['soc2', 'iso27001'],
        'solutions_architect' => $activeAssignee->id,
        'rfp_portal_url' => 'https://rfp.northwind.internal/deal/42',
        'pipeline_a_only' => 'inbound',
    ]);

    // Pipeline A scoped field rejected on Pipeline B
    expect(fn () => $validator->execute(
        entityType: CustomFieldEntityTypeEnum::Deal,
        values: ['short_code' => 'OK', 'security_reviewed' => true, 'pipeline_a_only' => 'leak'],
        pipelineId: $pipelineB->id,
    ))->toThrow(ValidationException::class);

    // Unknown field key rejected
    try {
        $validator->execute(
            entityType: CustomFieldEntityTypeEnum::Deal,
            values: ['short_code' => 'OK', 'security_reviewed' => true, 'nonexistent_key' => 'x'],
            pipelineId: $pipelineA->id,
        );
        $this->fail('Expected unknown key ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('custom_fields.nonexistent_key');
    }

    // Invalid URL (javascript:), invalid select, duplicate multi_select, inactive user_id, invalid date, non-strict bool, min/max, max_length
    $badCases = [
        'rfp_portal_url' => 'javascript:alert(1)',
        'deployment_region' => 'mars_colony',
        'compliance_tags' => ['soc2', 'soc2'],
        'solutions_architect' => $suspendedAssignee->id,
        'go_live_date' => '2026-02-30',
        'security_reviewed' => 'true',
        'seat_count' => 0,
        'implementation_fee' => -5,
        'short_code' => 'TOO-LONG-CODE-12345',
    ];

    foreach ($badCases as $fieldKey => $badValue) {
        try {
            $validator->execute(
                entityType: CustomFieldEntityTypeEnum::Deal,
                values: array_merge($validInput, [$fieldKey => $badValue]),
                pipelineId: $pipelineA->id,
            );
            $this->fail("Expected ValidationException for [{$fieldKey}].");
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey("custom_fields.{$fieldKey}");
        }
    }

    // Trashed user_id rejected
    expect(fn () => $validator->execute(
        entityType: CustomFieldEntityTypeEnum::Deal,
        values: array_merge($validInput, ['solutions_architect' => $trashedAssignee->id]),
        pipelineId: $pipelineA->id,
    ))->toThrow(ValidationException::class);

    // Required rule enforcement (non-partial missing required field)
    try {
        $validator->execute(
            entityType: CustomFieldEntityTypeEnum::Deal,
            values: ['security_reviewed' => true],
            pipelineId: $pipelineA->id,
            partial: false,
        );
        $this->fail('Expected missing required short_code ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('custom_fields.short_code');
    }

    // Partial update skips unprovided definition-level required fields, rejects nulling a required field, and enforces explicit $requiredDefinitionIds
    $partialOk = $validator->execute(
        entityType: CustomFieldEntityTypeEnum::Deal,
        values: ['seat_count' => 10],
        pipelineId: $pipelineA->id,
        partial: true,
    );
    expect($partialOk)->toBe(['seat_count' => 10]);

    try {
        $validator->execute(
            entityType: CustomFieldEntityTypeEnum::Deal,
            values: ['short_code' => null],
            pipelineId: $pipelineA->id,
            partial: true,
        );
        $this->fail('Expected partial nulling of required short_code to throw ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('custom_fields.short_code');
    }

    try {
        $validator->execute(
            entityType: CustomFieldEntityTypeEnum::Deal,
            values: ['seat_count' => 10],
            pipelineId: $pipelineA->id,
            requiredDefinitionIds: [$defs['go_live_date']->id],
            partial: true,
        );
        $this->fail('Expected requiredDefinitionIds ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('custom_fields.go_live_date');
    }
});

it('allows Admin to manage custom fields, pipelines, and stage-required gates while rejecting Sales (403) and stage deletes with active deals (422)', function (): void {
    $dept = Department::query()->create(['name' => 'Operations', 'slug' => 't12-admin-ops']);
    $admin = createTask12User($dept, 'EMP-ADM-1', 'admin.t12@acme.internal', RoleEnum::Admin);
    $sales = createTask12User($dept, 'EMP-SLS-1', 'sales.t12@acme.internal', RoleEnum::Sales);

    // PipelineSettingsPage renders via Inertia for authorized viewers
    $this->actingAs($admin)
        ->get('/app/crm/settings/pipelines')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/pages/PipelineSettingsPage')
            ->has('pipelines', 2));

    // Sales gets 403 on all custom field & pipeline admin mutations
    $this->actingAs($sales)->postJson('/api/internal/crm/custom-fields', [
        'entity_type' => 'deal',
        'key' => 'partner_code',
        'label' => 'Partner Code',
        'field_type' => 'text',
    ])->assertForbidden();

    $this->actingAs($sales)->postJson('/api/internal/crm/pipelines', [
        'name' => 'Channel Sales',
    ])->assertForbidden();

    // Admin creates custom field
    $cfRes = $this->actingAs($admin)->postJson('/api/internal/crm/custom-fields', [
        'entity_type' => 'deal',
        'key' => 'partner_code',
        'label' => 'Partner Code',
        'field_type' => 'text',
        'validation_rules' => ['max_length' => 32],
    ])->assertCreated();

    $customFieldId = (string) $cfRes->json('id');

    $this->actingAs($admin)->patchJson("/api/internal/crm/custom-fields/{$customFieldId}", [
        'label' => 'Reseller Partner Code',
        'is_filterable' => false,
    ])->assertOk()
        ->assertJsonPath('label', 'Reseller Partner Code')
        ->assertJsonPath('is_filterable', false);

    // Admin creates new default pipeline (clears previous is_default)
    $pipelineRes = $this->actingAs($admin)->postJson('/api/internal/crm/pipelines', [
        'name' => 'Enterprise Expansion',
        'slug' => 'enterprise-expansion',
        'is_default' => true,
        'sort_order' => 3,
    ])->assertCreated()
        ->assertJsonPath('slug', 'enterprise-expansion')
        ->assertJsonPath('is_default', true);

    $pipelineId = (string) $pipelineRes->json('id');
    expect(Pipeline::query()->where('is_default', true)->count())->toBe(1);

    $this->actingAs($admin)->patchJson("/api/internal/crm/pipelines/{$pipelineId}", [
        'name' => 'Enterprise Expansion FY27',
    ])->assertOk()
        ->assertJsonPath('name', 'Enterprise Expansion FY27');

    // Reject unknown required_core_fields and non-deal / cross-pipeline required_custom_field_ids
    $this->actingAs($admin)->postJson("/api/internal/crm/pipelines/{$pipelineId}/stages", [
        'name' => 'Bad Gate',
        'stage_type' => PipelineStageTypeEnum::Open->value,
        'win_probability' => 30,
        'required_core_fields' => ['hacked_column'],
    ])->assertUnprocessable();

    $companyCustomFieldId = (string) CustomFieldDefinition::query()
        ->where('entity_type', CustomFieldEntityTypeEnum::Company->value)
        ->where('key', 'tier')
        ->value('id');

    $this->actingAs($admin)->postJson("/api/internal/crm/pipelines/{$pipelineId}/stages", [
        'name' => 'Wrong Entity Field Gate',
        'stage_type' => PipelineStageTypeEnum::Open->value,
        'win_probability' => 30,
        'required_custom_field_ids' => [$companyCustomFieldId],
    ])->assertUnprocessable();

    // Admin creates stage with valid required_core_fields & required_custom_field_ids
    $stageRes = $this->actingAs($admin)->postJson("/api/internal/crm/pipelines/{$pipelineId}/stages", [
        'name' => 'Security Review',
        'stage_type' => PipelineStageTypeEnum::Open->value,
        'win_probability' => 60,
        'rotting_days' => 14,
        'required_core_fields' => ['amount_cents', 'expected_close_date'],
        'required_custom_field_ids' => [$customFieldId],
        'sort_order' => 1,
    ])->assertCreated()
        ->assertJsonPath('name', 'Security Review')
        ->assertJsonPath('required_core_fields', ['amount_cents', 'expected_close_date'])
        ->assertJsonPath('required_custom_field_ids', [$customFieldId]);

    $stageId = (string) $stageRes->json('id');

    $this->actingAs($admin)->patchJson("/api/internal/crm/pipelines/{$pipelineId}/stages/{$stageId}", [
        'win_probability' => 70,
        'required_core_fields' => ['amount_cents'],
        'required_custom_field_ids' => [],
    ])->assertOk()
        ->assertJsonPath('win_probability', 70)
        ->assertJsonPath('required_custom_field_ids', []);

    // Sales cannot delete stage
    $this->actingAs($sales)->deleteJson("/api/internal/crm/pipelines/{$pipelineId}/stages/{$stageId}")
        ->assertForbidden();

    // Create active deal in stage -> deletion must return 422
    $company = Company::query()->create(['name' => 'Northwind Logistics', 'owner_id' => $sales->id]);
    $deal = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipelineId,
        'stage_id' => $stageId,
        'owner_id' => $sales->id,
        'title' => 'Northwind Fleet Rollout',
        'stage_entered_at' => now(),
    ]);

    $this->actingAs($admin)->deleteJson("/api/internal/crm/pipelines/{$pipelineId}/stages/{$stageId}")
        ->assertUnprocessable();

    expect(PipelineStage::query()->whereKey($stageId)->exists())->toBeTrue();

    // Once active deal moves/deletes (wait: soft delete still holds FK if not moved; move to another stage first)
    $otherStage = $this->actingAs($admin)->postJson("/api/internal/crm/pipelines/{$pipelineId}/stages", [
        'name' => 'Legal Review',
        'stage_type' => PipelineStageTypeEnum::Open->value,
        'win_probability' => 80,
        'sort_order' => 2,
    ])->assertCreated();
    $deal->update(['stage_id' => $otherStage->json('id')]);

    $this->actingAs($admin)->deleteJson("/api/internal/crm/pipelines/{$pipelineId}/stages/{$stageId}")
        ->assertOk();
    expect(PipelineStage::query()->whereKey($stageId)->exists())->toBeFalse();

    // Admin deletes custom field
    $this->actingAs($admin)->deleteJson("/api/internal/crm/custom-fields/{$customFieldId}")
        ->assertOk();
    expect(CustomFieldDefinition::query()->whereKey($customFieldId)->exists())->toBeFalse();
});

it('sanitizes TipTap AST against XSS protocols and unsafe nodes while preserving formatting and body_text', function (): void {
    $sanitizer = app(SanitizeTipTapAstAction::class);

    $dirtyAst = [
        'type' => 'doc',
        'onclick' => 'alert(1)',
        'content' => [
            [
                'type' => 'heading',
                'attrs' => ['level' => 2, 'style' => 'color:red'],
                'content' => [
                    ['type' => 'text', 'text' => 'Executive Summary'],
                ],
            ],
            [
                'type' => 'script',
                'content' => [
                    ['type' => 'text', 'text' => 'alert("xss")'],
                ],
            ],
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Reviewed ',
                        'marks' => [
                            ['type' => 'bold', 'attrs' => ['onclick' => 'steal()']],
                            ['type' => 'xssEvilMark'],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'contract',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'https://docs.northwind.internal/msa', 'onclick' => 'xss()']],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => ' and ',
                    ],
                    [
                        'type' => 'text',
                        'text' => 'unsafe link',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => "java\tscript:alert(1)"]],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => ' plus ',
                    ],
                    [
                        'type' => 'text',
                        'text' => 'data link',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'data:text/html,<script>alert(1)</script>']],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => ' or ',
                    ],
                    [
                        'type' => 'text',
                        'text' => 'legal@northwind.internal',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'mailto:legal@northwind.internal']],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $result = $sanitizer->execute($dirtyAst);

    expect($result['body_text'])->toBe("Executive Summary\nReviewed contract and unsafe link plus data link or legal@northwind.internal")
        ->and($result['body_text'])->not->toContain('alert("xss")');

    $serialized = json_encode($result['body_json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    expect($serialized)->not->toContain('script')
        ->and($serialized)->not->toContain('onclick')
        ->and($serialized)->not->toContain('xssEvilMark')
        ->and($serialized)->not->toContain('data:text/html')
        ->and($serialized)->toContain('https://docs.northwind.internal/msa')
        ->and($serialized)->toContain('mailto:legal@northwind.internal');

    // Empty / purely unsafe AST fails validation when requireNonEmpty is true
    expect(fn () => $sanitizer->execute([
        'type' => 'doc',
        'content' => [
            ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'evil()']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '   ']]],
        ],
    ]))->toThrow(ValidationException::class);
});

it('fans out note activity_links, enforces cross-silo NoteController/NotePolicy isolation, paginates timeline with keyset cursor, and hides out-of-silo deal activities on shared companies', function (): void {
    $dept = Department::query()->create(['name' => 'Commercial', 'slug' => 't12-silo-comm']);
    $admin = createTask12User($dept, 'EMP-TL-ADM', 'admin.tl@acme.internal', RoleEnum::Admin);
    $repA = createTask12User($dept, 'EMP-TL-A', 'rep.a.tl@acme.internal', RoleEnum::Sales);
    $repB = createTask12User($dept, 'EMP-TL-B', 'rep.b.tl@acme.internal', RoleEnum::Sales);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $stage = $pipeline->stages()->orderBy('sort_order')->firstOrFail();

    // Shared company owned by Rep A, with Rep B granted Editor access ONLY to Company (NOT Deal A!)
    $company = Company::query()->create(['name' => 'Pacific Meridian Health', 'owner_id' => $repA->id]);
    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $company,
        user: $repB,
        accessLevel: CollaboratorAccessLevel::Editor,
        roleLabel: CollaboratorRoleLabel::SalesOverlay,
        grantedBy: $repA,
    );

    $contact = Contact::query()->create([
        'company_id' => $company->id,
        'owner_id' => $repA->id,
        'first_name' => 'Elena',
        'last_name' => 'Rostova',
    ]);

    $dealA = Deal::query()->create([
        'company_id' => $company->id,
        'primary_contact_id' => $contact->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_id' => $repA->id,
        'title' => 'Pacific Meridian Core CRM',
        'stage_entered_at' => now(),
    ]);

    $dealB = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_id' => $repB->id,
        'title' => 'Pacific Meridian Secret Division Deal',
        'stage_entered_at' => now(),
    ]);

    // Zero-role user cannot post notes even with empty payload; Rep B cannot attach a note to Deal A (nor smuggle Deal A in multi-target fan-out alongside shared Company)
    $zeroRoleUser = User::query()->create([
        'employee_code' => 'EMP-TL-0',
        'name' => 'Zero Role TL',
        'email' => 'zero.tl@acme.internal',
        'department_id' => $dept->id,
        'status' => UserStatusEnum::Active,
    ]);
    $this->actingAs($zeroRoleUser)->postJson('/api/internal/crm/notes', [])->assertForbidden();

    $notePayload = [
        'body_json' => [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Attempted silo breach']]]],
        ],
    ];

    $this->actingAs($repB)->postJson('/api/internal/crm/notes', array_merge($notePayload, [
        'targets' => [
            ['linkable_type' => 'deal', 'linkable_id' => $dealA->id],
        ],
    ]))->assertForbidden();

    $this->actingAs($repB)->postJson('/api/internal/crm/notes', array_merge($notePayload, [
        'targets' => [
            ['linkable_type' => 'company', 'linkable_id' => $company->id],
            ['linkable_type' => 'deal', 'linkable_id' => $dealA->id],
        ],
    ]))->assertForbidden();

    // 2. Rep A creates rich note via HTTP fanned out to dealA + company + contact (with duplicate target deduped)
    $noteRes = $this->actingAs($repA)->postJson('/api/internal/crm/notes', [
        'body_json' => [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Kickoff call completed with procurement.']]]],
        ],
        'targets' => [
            ['linkable_type' => 'deal', 'linkable_id' => $dealA->id],
            ['linkable_type' => 'company', 'linkable_id' => $company->id],
            ['linkable_type' => 'contact', 'linkable_id' => $contact->id],
            ['linkable_type' => 'company', 'linkable_id' => $company->id],
        ],
    ])->assertCreated()
        ->assertJsonPath('body_text', 'Kickoff call completed with procurement.');

    $noteId = (string) $noteRes->json('id');

    $noteActivity = Activity::query()->where('source_type', 'note')->where('source_id', $noteId)->firstOrFail();
    expect(ActivityLink::query()->where('activity_id', $noteActivity->id)->count())->toBe(3);

    // Toggle pin & update note via HTTP
    $this->actingAs($repA)->patchJson("/api/internal/crm/notes/{$noteId}/pin", ['is_pinned' => true])
        ->assertOk()
        ->assertJsonPath('is_pinned', true);

    $this->actingAs($repA)->patchJson("/api/internal/crm/notes/{$noteId}", [
        'body_json' => [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Updated procurement note.']]]],
        ],
    ])->assertOk()
        ->assertJsonPath('body_text', 'Updated procurement note.');

    // Rep B is Editor on $company ONLY (NOT $dealA) -> CANNOT update, pin, or delete $note co-linked to $dealA!
    $this->actingAs($repB)->patchJson("/api/internal/crm/notes/{$noteId}", [
        'body_json' => [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Unauthorized edit']]]],
        ],
    ])->assertForbidden();
    $this->actingAs($repB)->patchJson("/api/internal/crm/notes/{$noteId}/pin", ['is_pinned' => true])->assertForbidden();
    $this->actingAs($repB)->deleteJson("/api/internal/crm/notes/{$noteId}")->assertForbidden();

    // Create a Deal-A-only note by Rep A and verify reassignment strips Rep A's access!
    $dealOnlyNote = app(CreateNoteAction::class)->execute(
        actor: $repA,
        data: new CreateNoteData(
            body_json: ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Deal A private strategy']]]]],
            targets: [new NoteTargetData('deal', $dealA->id)],
        ),
    );

    expect(Gate::forUser($repA)->allows('view', $dealOnlyNote))->toBeTrue()
        ->and(Gate::forUser($repB)->allows('view', $dealOnlyNote))->toBeFalse();

    // Reassign Deal A to Rep B -> Rep A loses silo access to Deal A, so NotePolicy must deny Rep A (even as author!)
    $dealA->update(['owner_id' => $repB->id]);
    expect(Gate::forUser($repA)->allows('view', $dealOnlyNote))->toBeFalse()
        ->and(Gate::forUser($repA)->allows('update', $dealOnlyNote))->toBeFalse()
        ->and(Gate::forUser($repB)->allows('view', $dealOnlyNote))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('view', $dealOnlyNote))->toBeTrue();

    // Reassign Deal A back to Rep A for remaining timeline tests
    $dealA->update(['owner_id' => $repA->id]);

    // Soft delete $dealOnlyNote and verify it disappears from Deal A timeline
    $this->actingAs($repA)->deleteJson("/api/internal/crm/notes/{$dealOnlyNote->id}")->assertOk();
    expect(Note::withTrashed()->find($dealOnlyNote->id)?->trashed())->toBeTrue();

    // 3. Seed mixed visible + out-of-silo deal activities on shared Company (including exact tie occurred_at timestamps!)
    $recordActivity = app(RecordActivityAction::class);
    $tBase = now()->toImmutable()->subMinutes(30);

    // 4 visible Deal A activities across 3 timestamps (2 sharing exact tie timestamp)
    $vis1 = $recordActivity->execute($repA, ActivityTypeEnum::DealCreated, 'deal', $dealA->id, ['deal' => $dealA->id, 'company' => $company->id], 'Deal A created', [], 'source_policy', $tBase->addMinutes(1));
    $vis2 = $recordActivity->execute($repA, ActivityTypeEnum::StageChanged, 'deal', $dealA->id, ['deal' => $dealA->id, 'company' => $company->id], 'Deal A Discovery', [], 'source_policy', $tBase->addMinutes(2));
    $vis3 = $recordActivity->execute($repA, ActivityTypeEnum::FieldUpdated, 'deal', $dealA->id, ['deal' => $dealA->id, 'company' => $company->id], 'Deal A Amount Updated', [], 'source_policy', $tBase->addMinutes(2));
    $vis4 = $recordActivity->execute($repA, ActivityTypeEnum::StageChanged, 'deal', $dealA->id, ['deal' => $dealA->id, 'company' => $company->id], 'Deal A Proposal', [], 'source_policy', $tBase->addMinutes(4));

    // 5 out-of-silo Deal B activities linked to shared Company (including newest items!)
    for ($i = 1; $i <= 5; $i++) {
        $recordActivity->execute(
            $repB,
            ActivityTypeEnum::StageChanged,
            'deal',
            $dealB->id,
            ['deal' => $dealB->id, 'company' => $company->id],
            "Secret Deal B step {$i}",
            [],
            'source_policy',
            $tBase->addMinutes(10 + $i),
        );
    }

    // Rep A queries Company timeline with limit=2 across pages -> must see note + vis4 + vis3 + vis2 + vis1 (5 items), ZERO Deal B items, ZERO deleted notes!
    $collectedSummaries = [];
    $collectedIds = [];
    $cursor = null;
    $pageCount = 0;

    do {
        $url = '/api/internal/crm/timeline?linkable_type=company&linkable_id='.$company->id.'&limit=2'
            .($cursor !== null ? '&cursor='.urlencode($cursor) : '');

        $res = $this->actingAs($repA)->getJson($url)->assertOk();
        $items = $res->json('items');
        foreach ($items as $item) {
            $collectedIds[] = $item['id'];
            $collectedSummaries[] = $item['summary'];
        }
        $cursor = $res->json('next_cursor');
        $hasMore = (bool) $res->json('has_more');
        $pageCount++;
    } while ($hasMore && $cursor !== null && $pageCount < 10);

    expect($collectedIds)->toHaveCount(5)
        ->and(array_unique($collectedIds))->toHaveCount(5)
        ->and($collectedSummaries)->not->toContain('Secret Deal B step 1')
        ->and($collectedSummaries)->not->toContain('Secret Deal B step 5')
        ->and($collectedSummaries)->toContain('Deal A created', 'Deal A Discovery', 'Deal A Amount Updated', 'Deal A Proposal');

    // Note body is hydrated for the visible note activity
    $firstPage = app(QueryEntityTimelineAction::class)->execute($repA, 'company', $company->id, null, 2);
    $noteItem = collect($firstPage->items)->firstWhere('source_type', 'note');
    expect($noteItem)->not->toBeNull()
        ->and($noteItem->note?->body_text)->toBe('Updated procurement note.');

    // Admin sees ALL 10 activities (including Deal B's 5 secret activities!)
    $adminPage = app(QueryEntityTimelineAction::class)->execute($admin, 'company', $company->id, null, 50);
    expect($adminPage->items)->toHaveCount(10);

    // Cross-silo Rep B cannot query Deal A's timeline directly (403)
    $this->actingAs($repB)
        ->getJson('/api/internal/crm/timeline?linkable_type=deal&linkable_id='.$dealA->id)
        ->assertForbidden();
});

it('gates timeline sources by module viewAny permission, enforces strict ISO-8601 cursor parsing, caps scan batches, and records microsecond UTC activity_links', function (): void {
    $dept = Department::query()->create(['name' => 'Security Ops', 'slug' => 't12-tl-hardening']);
    $repA = createTask12User($dept, 'EMP-TH-A', 'rep.a.th@acme.internal', RoleEnum::Sales);
    $repB = createTask12User($dept, 'EMP-TH-B', 'rep.b.th@acme.internal', RoleEnum::Sales);
    $itUser = createTask12User($dept, 'EMP-TH-IT', 'it.th@acme.internal', RoleEnum::It);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $stage = $pipeline->stages()->orderBy('sort_order')->firstOrFail();

    $company = Company::query()->create(['name' => 'Gated Timeline Co', 'owner_id' => $repA->id]);
    $contact = Contact::query()->create([
        'company_id' => $company->id,
        'owner_id' => $repA->id,
        'first_name' => 'Alan',
        'last_name' => 'Turing',
    ]);
    $dealA = Deal::query()->create([
        'company_id' => $company->id,
        'primary_contact_id' => $contact->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_id' => $repA->id,
        'title' => 'Hidden Deal From IT',
        'stage_entered_at' => now(),
    ]);
    $dealB = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_id' => $repB->id,
        'title' => 'Silo B Flood Deal',
        'stage_entered_at' => now(),
    ]);

    // Grant IT collaborator access on Company, Contact, AND Deal A (IT still lacks deals.view!)
    $grant = app(GrantEntityCollaboratorAction::class);
    foreach ([$company, $contact, $dealA] as $target) {
        $grant->execute(
            collaboratable: $target,
            user: $itUser,
            accessLevel: CollaboratorAccessLevel::Manager,
            roleLabel: CollaboratorRoleLabel::ItLead,
            grantedBy: $repA,
        );
    }

    $recordActivity = app(RecordActivityAction::class);
    $baseTime = now()->toImmutable()->subMinutes(10)->startOfSecond();

    // Verify RecordActivityAction formats activity_links.occurred_at in UTC with microsecond +00 format
    /** @var list<array<int|string, mixed>> $activityLinkInsertBindings */
    $activityLinkInsertBindings = [];
    DB::listen(static function (QueryExecuted $query) use (&$activityLinkInsertBindings): void {
        if (str_contains($query->sql, 'insert into "activity_links"')) {
            $activityLinkInsertBindings[] = $query->bindings;
        }
    });

    $recordActivity->execute(
        actor: $repA,
        activityType: ActivityTypeEnum::FieldUpdated,
        sourceType: 'company',
        sourceId: $company->id,
        targets: ['company' => $company->id],
        summary: 'Company first',
        occurredAt: $baseTime->setTimezone('America/New_York')->addMicroseconds(123456),
    );
    $recordActivity->execute(
        actor: $repA,
        activityType: ActivityTypeEnum::FieldUpdated,
        sourceType: 'contact',
        sourceId: $contact->id,
        targets: ['company' => $company->id, 'contact' => $contact->id],
        summary: 'Contact second',
        occurredAt: $baseTime->addSeconds(1),
    );
    $recordActivity->execute(
        actor: $repA,
        activityType: ActivityTypeEnum::DealCreated,
        sourceType: 'deal',
        sourceId: $dealA->id,
        targets: ['company' => $company->id, 'deal' => $dealA->id],
        summary: 'Deal A created secret from IT',
        occurredAt: $baseTime->addSeconds(2),
    );

    expect($activityLinkInsertBindings)->not->toBeEmpty()
        ->and(collect($activityLinkInsertBindings[0])->contains(
            static fn (mixed $value): bool => is_string($value)
                && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.123456\+00$/', $value) === 1,
        ))->toBeTrue();

    // IT sees company + contact activities in descending order, NEVER deal activity
    $itPage1 = $this->actingAs($itUser)
        ->getJson('/api/internal/crm/timeline?linkable_type=company&linkable_id='.$company->id.'&limit=1')
        ->assertOk()
        ->assertJsonPath('has_more', true)
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.summary', 'Contact second');

    $this->actingAs($itUser)
        ->getJson('/api/internal/crm/timeline?linkable_type=company&linkable_id='.$company->id.'&limit=1&cursor='.urlencode((string) $itPage1->json('next_cursor')))
        ->assertOk()
        ->assertJsonPath('has_more', false)
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.summary', 'Company first');

    // Malformed / fuzzy cursor timestamps rejected cleanly with 422 ValidationException
    $badPayloads = [
        base64_encode(json_encode(['occurred_at' => 'next tuesday', 'activity_id' => (string) Str::uuid7()], JSON_THROW_ON_ERROR)),
        base64_encode(json_encode(['occurred_at' => '2026-11-26 01:35:28.123456+00', 'activity_id' => (string) Str::uuid7()], JSON_THROW_ON_ERROR)),
        base64_encode(json_encode(['occurred_at' => '2026-02-30T01:35:28.123456+00:00', 'activity_id' => (string) Str::uuid7()], JSON_THROW_ON_ERROR)),
        base64_encode(json_encode(['occurred_at' => '2026-11-26T01:35:28', 'activity_id' => (string) Str::uuid7()], JSON_THROW_ON_ERROR)),
        'not-valid-base64!!!',
    ];
    foreach ($badPayloads as $badCursor) {
        $this->actingAs($repA)
            ->getJson('/api/internal/crm/timeline?linkable_type=company&linkable_id='.$company->id.'&cursor='.urlencode($badCursor))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cursor']);
    }

    // Valid ISO-8601 variants (microsecond +00 and ATOM fallback) succeed
    $validCursors = [
        base64_encode(json_encode(['occurred_at' => $baseTime->addSeconds(10)->utc()->format('Y-m-d\TH:i:s.u+00'), 'activity_id' => (string) Str::uuid7()], JSON_THROW_ON_ERROR)),
        base64_encode(json_encode(['occurred_at' => $baseTime->addSeconds(10)->utc()->format(DateTimeInterface::ATOM), 'activity_id' => (string) Str::uuid7()], JSON_THROW_ON_ERROR)),
    ];
    foreach ($validCursors as $validCursor) {
        $this->actingAs($repA)
            ->getJson('/api/internal/crm/timeline?linkable_type=company&linkable_id='.$company->id.'&cursor='.urlencode($validCursor))
            ->assertOk();
    }

    // MAX_SCAN_BATCHES (20) caps scan loop when 1050 inaccessible Deal B activities precede visible items
    $floodActivities = [];
    $floodLinks = [];
    $floodStart = $baseTime->addMinutes(1);
    for ($i = 0; $i < 1050; $i++) {
        $actId = (string) Str::uuid7();
        $ts = $floodStart->addMicroseconds($i * 10)->utc()->format('Y-m-d H:i:s.u+00');
        $floodActivities[] = [
            'id' => $actId,
            'actor_id' => $repB->id,
            'activity_type' => ActivityTypeEnum::StageChanged->value,
            'source_type' => 'deal',
            'source_id' => $dealB->id,
            'visibility_mode' => 'source_policy',
            'summary' => "Hidden flood {$i}",
            'metadata' => '{}',
            'occurred_at' => $ts,
            'created_at' => $ts,
        ];
        $floodLinks[] = [
            'linkable_type' => 'company',
            'linkable_id' => $company->id,
            'occurred_at' => $ts,
            'activity_id' => $actId,
        ];
    }
    foreach (array_chunk($floodActivities, 500) as $chunk) {
        DB::table('activities')->insert($chunk);
    }
    foreach (array_chunk($floodLinks, 500) as $chunk) {
        DB::table('activity_links')->insert($chunk);
    }

    $batchQueries = 0;
    DB::listen(static function (QueryExecuted $query) use (&$batchQueries): void {
        if (str_contains($query->sql, 'from "activity_links"') && str_contains($query->sql, 'limit 50')) {
            $batchQueries++;
        }
    });

    $boundedResult = app(QueryEntityTimelineAction::class)->execute($repA, 'company', $company->id, null, 25);
    expect($batchQueries)->toBe(QueryEntityTimelineAction::MAX_SCAN_BATCHES)
        ->and($boundedResult->items)->toBeEmpty()
        ->and($boundedResult->has_more)->toBeFalse();
});

it('enforces custom field immutability, stage_required_fields protection, pipeline route scopeBindings, and Note FOR UPDATE locking', function (): void {
    $dept = Department::query()->create(['name' => 'Ops', 'slug' => 't12-cf-note-lock']);
    $admin = createTask12User($dept, 'EMP-CFN-ADM', 'admin.cfn@acme.internal', RoleEnum::Admin);
    $repA = createTask12User($dept, 'EMP-CFN-REPA', 'rep.a.cfn@acme.internal', RoleEnum::Sales);
    $demotedAuthor = createTask12User($dept, 'EMP-CFN-DEM', 'demoted.cfn@acme.internal', RoleEnum::Sales);

    $pipelineA = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $pipelineB = Pipeline::query()->where('slug', 'renewal-upsell')->firstOrFail();
    $stageA = $pipelineA->stages()->orderBy('sort_order')->firstOrFail();
    $stageB = $pipelineB->stages()->orderBy('sort_order')->firstOrFail();

    // 1. Cross-pipeline stage route binding returns 404 via scopeBindings()
    $this->actingAs($admin)
        ->patchJson("/api/internal/crm/pipelines/{$pipelineA->id}/stages/{$stageB->id}", ['win_probability' => 45])
        ->assertNotFound();
    $this->actingAs($admin)
        ->deleteJson("/api/internal/crm/pipelines/{$pipelineA->id}/stages/{$stageB->id}")
        ->assertNotFound();

    // 2. Custom field immutability (entity_type, key) and stage_required_fields protection
    $field = app(UpsertCustomFieldDefinitionAction::class)->execute(new UpsertCustomFieldDefinitionData(
        entity_type: CustomFieldEntityTypeEnum::Deal,
        pipeline_id: null,
        key: 'security_clearance',
        label: 'Security Clearance',
        field_type: CustomFieldTypeEnum::Text,
    ));

    // Reject entity_type or key mutation
    $this->actingAs($admin)->patchJson("/api/internal/crm/custom-fields/{$field->id}", [
        'entity_type' => CustomFieldEntityTypeEnum::Company->value,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['entity_type']);

    $this->actingAs($admin)->patchJson("/api/internal/crm/custom-fields/{$field->id}", [
        'key' => 'renamed_key',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['key']);

    // Idempotent resend of unchanged entity_type and key succeeds
    $this->actingAs($admin)->patchJson("/api/internal/crm/custom-fields/{$field->id}", [
        'entity_type' => CustomFieldEntityTypeEnum::Deal->value,
        'key' => 'security_clearance',
        'label' => 'Security Clearance Level',
    ])->assertOk()
        ->assertJsonPath('label', 'Security Clearance Level');

    // Attach field as required on stageA (Pipeline A)
    $stageA->requiredCustomFields()->sync([$field->id]);

    // Cannot deactivate while required by stageA
    $this->actingAs($admin)->patchJson("/api/internal/crm/custom-fields/{$field->id}", [
        'is_active' => false,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['is_active']);

    // Cannot scope to incompatible Pipeline B while required by stageA in Pipeline A
    $this->actingAs($admin)->patchJson("/api/internal/crm/custom-fields/{$field->id}", [
        'pipeline_id' => $pipelineB->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['pipeline_id']);

    // Scoping to compatible Pipeline A (sole referencing pipeline) succeeds
    $this->actingAs($admin)->patchJson("/api/internal/crm/custom-fields/{$field->id}", [
        'pipeline_id' => $pipelineA->id,
    ])->assertOk()
        ->assertJsonPath('pipeline_id', $pipelineA->id);

    // 3. TogglePinNoteAction & UpdateNoteAction lock FOR UPDATE & compute nextPinned from fresh locked row
    $company = Company::query()->create(['name' => 'Pin Lock Co', 'owner_id' => $repA->id]);
    $deal = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipelineA->id,
        'stage_id' => $stageA->id,
        'owner_id' => $repA->id,
        'title' => 'Pin Lock Deal',
        'stage_entered_at' => now(),
    ]);

    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $deal,
        user: $demotedAuthor,
        accessLevel: CollaboratorAccessLevel::Editor,
        roleLabel: CollaboratorRoleLabel::SalesOverlay,
        grantedBy: $repA,
    );

    $note = app(CreateNoteAction::class)->execute($demotedAuthor, new CreateNoteData(
        body_json: ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Initial note']]]]],
        targets: [new NoteTargetData('deal', $deal->id)],
        is_pinned: false,
    ));

    // Demote author to Viewer -> HTTP update/pin/delete all 403
    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $deal,
        user: $demotedAuthor,
        accessLevel: CollaboratorAccessLevel::Viewer,
        roleLabel: CollaboratorRoleLabel::Observer,
        grantedBy: $repA,
    );

    $this->actingAs($demotedAuthor)->patchJson("/api/internal/crm/notes/{$note->id}", [
        'body_json' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Demoted edit']]]]],
    ])->assertForbidden();
    $this->actingAs($demotedAuthor)->patchJson("/api/internal/crm/notes/{$note->id}/pin", ['is_pinned' => true])->assertForbidden();
    $this->actingAs($demotedAuthor)->deleteJson("/api/internal/crm/notes/{$note->id}")->assertForbidden();

    // Simulate stale model passed to TogglePinNoteAction after concurrent toggle -> FOR UPDATE reads fresh DB state
    $staleNote = Note::query()->findOrFail($note->id);
    Note::query()->whereKey($note->id)->update(['is_pinned' => true]);

    $noteLocks = 0;
    DB::listen(static function (QueryExecuted $query) use (&$noteLocks): void {
        if (str_contains(strtolower($query->sql), 'from "notes"') && str_contains(strtolower($query->sql), 'for update')) {
            $noteLocks++;
        }
    });

    // Stale instance thought is_pinned=false, DB actually had is_pinned=true -> toggle must flip to false!
    $toggled = app(TogglePinNoteAction::class)->execute($repA, $staleNote, null);
    expect($toggled->is_pinned)->toBeFalse()
        ->and($noteLocks)->toBeGreaterThanOrEqual(1);

    $beforeUpdateLocks = $noteLocks;
    app(UpdateNoteAction::class)->execute($repA, $staleNote, new UpdateNoteData(
        body_json: ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Locked update']]]]],
    ));
    expect($noteLocks)->toBeGreaterThan($beforeUpdateLocks);
});
