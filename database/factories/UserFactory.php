<?php

namespace Database\Factories;

use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<User>
     */
    protected $model = User::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_code' => 'EMP-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid7()), -10)),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'department_id' => static fn (): string => (string) Department::query()->firstOrCreate(
                ['slug' => 'general'],
                ['name' => 'General'],
            )->id,
            'job_title' => fake()->jobTitle(),
            'status' => UserStatusEnum::Active,
            'timezone' => 'UTC',
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UserStatusEnum::Suspended,
        ]);
    }

    public function offboarded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UserStatusEnum::Offboarded,
        ]);
    }
}
