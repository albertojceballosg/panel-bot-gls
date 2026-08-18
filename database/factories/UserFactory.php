<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\PermissionCatalog;
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
            // Las cuentas anteriores a la columna lo tienen a NULL y siguen
            // siendo válidas; para lo que se crea de cero, lo normal es que
            // conste, que es lo que exige el formulario (§7, fase 8).
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Las cuentas de prueba nacen administradoras (§7, fase 12).
     *
     * Es lo que era todo el mundo antes de que hubiera roles, así que los tests
     * que no van de permisos siguen probando lo mismo que probaban. Los que sí
     * van de permisos piden el rol que quieren con `->role(...)`, que se aplica
     * después y sustituye a éste.
     */
    public function configure(): static
    {
        return $this->afterCreating(
            fn (User $user) => $user->assignRole(PermissionCatalog::ROLE_ADMIN),
        );
    }

    /** Una cuenta con este rol y sólo con este. */
    public function role(string $name): static
    {
        return $this->afterCreating(fn (User $user) => $user->syncRoles([$name]));
    }

    /** Una cuenta sin ningún rol: no puede entrar a ninguna pantalla. */
    public function withoutRole(): static
    {
        return $this->afterCreating(fn (User $user) => $user->syncRoles([]));
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
