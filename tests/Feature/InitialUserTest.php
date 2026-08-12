<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\InitialUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Tests\TestCase;

class InitialUserTest extends TestCase
{
    use RefreshDatabase;

    private function configure(string $email = 'alguien@panel.local', string $password = 'una-contraseña-larga'): void
    {
        config(['panel.initial_user' => [
            'name' => 'Panel',
            'email' => $email,
            'password' => $password,
        ]]);
    }

    public function test_the_seeder_creates_a_user_that_can_log_in(): void
    {
        $this->configure();
        $this->seed(InitialUserSeeder::class);

        $this->assertTrue(Auth::attempt([
            'email' => 'alguien@panel.local',
            'password' => 'una-contraseña-larga',
        ]));
    }

    public function test_the_password_is_stored_hashed(): void
    {
        $this->configure();
        $this->seed(InitialUserSeeder::class);

        $this->assertNotSame('una-contraseña-larga', User::first()->password);
    }

    public function test_running_it_again_updates_the_password_instead_of_failing(): void
    {
        $this->configure();
        $this->seed(InitialUserSeeder::class);

        // Es la forma de recuperar el acceso: cambiar el .env y volver a sembrar.
        $this->configure(password: 'otra-contraseña-distinta');
        $this->seed(InitialUserSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertFalse(Auth::attempt(['email' => 'alguien@panel.local', 'password' => 'una-contraseña-larga']));
        $this->assertTrue(Auth::attempt(['email' => 'alguien@panel.local', 'password' => 'otra-contraseña-distinta']));
    }

    public function test_it_blows_up_when_the_credentials_are_missing(): void
    {
        config(['panel.initial_user' => ['name' => 'Panel', 'email' => null, 'password' => null]]);

        // Mejor que falle a que invente una por defecto que acabe en producción.
        $this->expectException(RuntimeException::class);
        $this->seed(InitialUserSeeder::class);
    }

    // --- Borrado pasivo -----------------------------------------------------

    public function test_a_deleted_user_cannot_log_in(): void
    {
        $this->configure();
        $this->seed(InitialUserSeeder::class);

        User::first()->delete();

        // Es lo que hace útil el borrado pasivo aquí: se le quita el acceso sin
        // perder de quién era la cuenta.
        $this->assertFalse(Auth::attempt([
            'email' => 'alguien@panel.local',
            'password' => 'una-contraseña-larga',
        ]));
        $this->assertSame(0, User::count());
        $this->assertSame(1, User::withTrashed()->count());
    }

    public function test_the_email_of_a_deleted_user_is_freed(): void
    {
        $this->configure();
        $this->seed(InitialUserSeeder::class);
        User::first()->delete();

        // Con un índice único normal esto reventaría contra una fila invisible.
        $nuevo = User::create([
            'name' => 'Otra persona',
            'email' => 'alguien@panel.local',
            'password' => 'contraseña-nueva',
        ]);

        $this->assertTrue($nuevo->exists);
        $this->assertSame(2, User::withTrashed()->count());
    }

    public function test_the_seeder_revives_a_deleted_account(): void
    {
        $this->configure();
        $this->seed(InitialUserSeeder::class);
        $id = User::first()->id;
        User::first()->delete();

        $this->seed(InitialUserSeeder::class);

        // La misma fila, no una nueva: se conserva a quién pertenecía.
        $this->assertSame(1, User::withTrashed()->count());
        $this->assertSame($id, User::first()->id);
        $this->assertTrue(Auth::attempt(['email' => 'alguien@panel.local', 'password' => 'una-contraseña-larga']));
    }
}
