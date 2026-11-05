<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role' => 'STAFF_PIC',
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => static::$password ??= Hash::make('password'),
            'department' => 'BM',
            'is_active' => true,
        ];
    }

    public function role(string $role, ?string $department = 'BM'): static
    {
        return $this->state(['role' => $role, 'department' => $department]);
    }
}
