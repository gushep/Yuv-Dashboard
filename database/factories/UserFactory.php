<?php

namespace Database\Factories;

use App\Models\User;
use App\Screen;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'screens' => Screen::cases(),
        ];
    }

    /**
     * Restrict the user to the given dashboard screens.
     */
    public function withScreens(Screen ...$screens): static
    {
        return $this->state(fn (array $attributes) => [
            'screens' => $screens,
        ]);
    }

    /**
     * Allow the user to update cards (status, comments and attachments) from the raw data screen.
     */
    public function canUpdateItems(): static
    {
        return $this->state(fn (array $attributes) => [
            'can_update_items' => true,
        ]);
    }

    /**
     * Allow the user to open support tickets with the Novo Ticket button.
     */
    public function canCreateTickets(): static
    {
        return $this->state(fn (array $attributes) => [
            'can_create_tickets' => true,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
