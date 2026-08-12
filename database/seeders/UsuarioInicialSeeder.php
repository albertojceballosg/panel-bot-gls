<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Crea el usuario con el que se entra al panel.
 *
 * Las credenciales salen del `.env` (§10): en el repo no se escribe ninguna
 * clave, tampoco una de desarrollo. Si se dejase una por defecto aquí acabaría
 * en producción el día que alguien despliegue sin mirar.
 *
 * Es idempotente: repetirlo actualiza la contraseña en lugar de fallar, que es
 * la forma cómoda de recuperar el acceso si se te olvida.
 */
class UsuarioInicialSeeder extends Seeder
{
    public function run(): void
    {
        $datos = config('panel.usuario_inicial');

        foreach (['email', 'password'] as $obligatorio) {
            if (blank($datos[$obligatorio])) {
                throw new RuntimeException(
                    'Falta SEED_USER_'.strtoupper($obligatorio).' en el .env. Las '.
                    'credenciales del usuario inicial no se versionan: ver CONTEXTO.md §10 '.
                    'y el .env.example.'
                );
            }
        }

        // withTrashed: si la cuenta estaba dada de baja, se revive en vez de
        // chocar contra el índice único parcial de `users` (§4).
        $usuario = User::withTrashed()->firstOrNew(['email' => $datos['email']]);

        $usuario->name = $datos['nombre'];
        $usuario->password = $datos['password'];   // el cast 'hashed' lo cifra
        $usuario->deleted_at = null;
        $usuario->save();

        // El email sí, la contraseña no: los logs y la salida de consola acaban
        // pegados en sitios que no controlamos.
        $this->command?->info("  Usuario inicial listo: {$usuario->email}");
    }
}
