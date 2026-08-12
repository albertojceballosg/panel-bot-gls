<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\UsuarioInicialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Tests\TestCase;

class UsuarioInicialTest extends TestCase
{
    use RefreshDatabase;

    private function configurar(string $email = 'alguien@panel.local', string $password = 'una-contraseña-larga'): void
    {
        config(['panel.usuario_inicial' => [
            'nombre' => 'Panel',
            'email' => $email,
            'password' => $password,
        ]]);
    }

    public function test_el_seeder_crea_un_usuario_con_el_que_se_puede_entrar(): void
    {
        $this->configurar();
        $this->seed(UsuarioInicialSeeder::class);

        $this->assertTrue(Auth::attempt([
            'email' => 'alguien@panel.local',
            'password' => 'una-contraseña-larga',
        ]));
    }

    public function test_la_contrasena_se_guarda_cifrada(): void
    {
        $this->configurar();
        $this->seed(UsuarioInicialSeeder::class);

        $this->assertNotSame('una-contraseña-larga', User::first()->password);
    }

    public function test_repetirlo_actualiza_la_contrasena_en_vez_de_fallar(): void
    {
        $this->configurar();
        $this->seed(UsuarioInicialSeeder::class);

        // Es la forma de recuperar el acceso: cambiar el .env y volver a sembrar.
        $this->configurar(password: 'otra-contraseña-distinta');
        $this->seed(UsuarioInicialSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertFalse(Auth::attempt(['email' => 'alguien@panel.local', 'password' => 'una-contraseña-larga']));
        $this->assertTrue(Auth::attempt(['email' => 'alguien@panel.local', 'password' => 'otra-contraseña-distinta']));
    }

    public function test_revienta_si_faltan_las_credenciales_en_el_env(): void
    {
        config(['panel.usuario_inicial' => ['nombre' => 'Panel', 'email' => null, 'password' => null]]);

        // Mejor que falle a que invente una por defecto que acabe en producción.
        $this->expectException(RuntimeException::class);
        $this->seed(UsuarioInicialSeeder::class);
    }

    // --- Borrado pasivo -----------------------------------------------------

    public function test_un_usuario_dado_de_baja_no_puede_entrar(): void
    {
        $this->configurar();
        $this->seed(UsuarioInicialSeeder::class);

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

    public function test_el_email_de_un_usuario_dado_de_baja_queda_libre(): void
    {
        $this->configurar();
        $this->seed(UsuarioInicialSeeder::class);
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

    public function test_el_seeder_revive_la_cuenta_dada_de_baja(): void
    {
        $this->configurar();
        $this->seed(UsuarioInicialSeeder::class);
        $id = User::first()->id;
        User::first()->delete();

        $this->seed(UsuarioInicialSeeder::class);

        // La misma fila, no una nueva: se conserva a quién pertenecía.
        $this->assertSame(1, User::withTrashed()->count());
        $this->assertSame($id, User::first()->id);
        $this->assertTrue(Auth::attempt(['email' => 'alguien@panel.local', 'password' => 'una-contraseña-larga']));
    }
}
