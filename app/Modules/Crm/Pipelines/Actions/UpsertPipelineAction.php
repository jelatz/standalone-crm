<?php

namespace App\Modules\Crm\Pipelines\Actions;

use App\Modules\Crm\Pipelines\Data\UpsertPipelineData;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;

class UpsertPipelineAction
{
    public function execute(UpsertPipelineData $data, ?Pipeline $pipeline = null): Pipeline
    {
        return DB::transaction(function () use ($data, $pipeline): Pipeline {
            $name = ! $data->name instanceof Optional ? $data->name : $pipeline?->name;

            if ($name === null || trim($name) === '') {
                throw ValidationException::withMessages(['name' => ['Pipeline name is required.']]);
            }

            if (! $data->slug instanceof Optional) {
                $slug = $data->slug !== null && trim($data->slug) !== ''
                    ? trim($data->slug)
                    : Str::slug($name);
            } elseif ($pipeline !== null) {
                $slug = $pipeline->slug;
            } else {
                $slug = Str::slug($name);
            }

            if ($slug === '') {
                $slug = 'pipeline-'.Str::lower(Str::random(8));
            }

            $duplicateSlug = Pipeline::query()
                ->where('slug', $slug)
                ->when($pipeline !== null, fn ($q) => $q->whereKeyNot($pipeline->id))
                ->exists();

            if ($duplicateSlug) {
                throw ValidationException::withMessages(['slug' => ['A pipeline with this slug already exists.']]);
            }

            $departmentId = ! $data->department_id instanceof Optional
                ? $data->department_id
                : $pipeline?->department_id;

            $isDefault = ! $data->is_default instanceof Optional
                ? $data->is_default
                : ($pipeline?->is_default ?? false);

            $isActive = ! $data->is_active instanceof Optional
                ? $data->is_active
                : ($pipeline?->is_active ?? true);

            $sortOrder = ! $data->sort_order instanceof Optional
                ? $data->sort_order
                : ($pipeline?->sort_order ?? 0);

            if ($isDefault) {
                Pipeline::query()
                    ->when($pipeline !== null, fn ($q) => $q->whereKeyNot($pipeline->id))
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $attributes = [
                'name' => trim($name),
                'slug' => $slug,
                'department_id' => $departmentId,
                'is_default' => $isDefault,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
            ];

            if ($pipeline === null) {
                $created = Pipeline::query()->create($attributes);

                return $created->load('stages.requiredCustomFields');
            }

            $pipeline->update($attributes);

            return $pipeline->refresh()->load('stages.requiredCustomFields');
        });
    }
}
