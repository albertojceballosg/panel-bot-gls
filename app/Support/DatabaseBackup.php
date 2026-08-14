<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Copias de seguridad de la base (CONTEXTO.md §7, fase 7).
 *
 * Envuelve `pg_dump` y `pg_restore`, que van en la imagen (ver el `Dockerfile`).
 * **No se escribe el volcado a mano en PHP**: un fichero de `INSERT`s generado
 * por nosotros no reproduce la columna generada de `merchants`, los índices
 * únicos parciales de §4 ni las secuencias, y una copia que no restaura no es
 * una copia. Un `.dump` de verdad, además, lo abre cualquiera con las
 * herramientas del motor, sin este panel por medio.
 *
 * **El panel no guarda copias.** El volcado se genera, se manda al navegador y
 * el fichero temporal se borra; para restaurar hay que volver a subir el que se
 * descargó. Así los datos del cliente —nombres y códigos de los comercios, §9—
 * no se quedan acumulándose en el servidor, y no hay una carpeta que vigilar ni
 * que limpiar. La contrapartida es que la única copia es la que tú tengas.
 *
 * Formato `custom` (`-Fc`) y no SQL plano: comprime, y es el único que
 * `pg_restore` sabe limpiar antes de escribir (`--clean --if-exists`), que es lo
 * que permite restaurar sobre una base con datos sin dejar restos de los viejos.
 */
class DatabaseBackup
{
    /** Un volcado grande tarda; el que se muere ahí es el proceso, no la copia. */
    public const TIMEOUT_SECONDS = 900;

    /** Los cinco primeros bytes de un volcado en formato `custom`. */
    private const MAGIC = 'PGDMP';

    private ?bool $tools = null;

    /** Cómo se llama el fichero que se descarga. */
    public function filename(): string
    {
        return 'panel-'.now()->format('Y-m-d-His').'.dump';
    }

    /**
     * Genera un volcado y devuelve la ruta del fichero temporal.
     *
     * Temporal de verdad —fuera del proyecto, en el temporal del sistema— y de
     * quien llama es borrarlo: la respuesta de descarga lo hace sola con
     * `deleteFileAfterSend()`.
     */
    public function dump(): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'panel-dump-');

        $resultado = Process::timeout(self::TIMEOUT_SECONDS)
            ->env($this->environment())
            ->run([
                'pg_dump',
                ...$this->connectionArguments(),
                '--format=custom',
                // Sin dueños ni permisos: la copia tiene que poder restaurarse
                // en otra máquina donde el rol de la base se llame distinto.
                '--no-owner',
                '--no-privileges',
                '--file='.$ruta,
            ]);

        if ($resultado->failed()) {
            @unlink($ruta);

            throw new RuntimeException($this->reason($resultado->errorOutput(), 'No se pudo generar la copia.'));
        }

        Log::info('Copia de seguridad descargada', ['by' => auth()->user()?->email]);

        return $ruta;
    }

    /**
     * Restaura **encima de la base actual** el fichero que se le pasa. Todo lo
     * escrito después de ese volcado se pierde: quien llama tiene que haberlo
     * confirmado.
     */
    public function restore(string $path): void
    {
        if (! $this->looksLikeADump($path)) {
            throw new RuntimeException('Ese fichero no es una copia de esta base: no tiene la forma de un volcado de PostgreSQL.');
        }

        // Nuestra propia conexión estorba: `--clean` necesita bloqueo exclusivo
        // para tirar las tablas, y la conexión de esta petición las tiene
        // tomadas. Sin esto, la restauración se queda esperándose a sí misma.
        DB::disconnect();

        $resultado = Process::timeout(self::TIMEOUT_SECONDS)
            ->env($this->environment())
            ->run([
                'pg_restore',
                ...$this->connectionArguments(),
                // Tira lo que haya antes de escribir. Sin `--clean`, restaurar
                // sobre una base con datos deja los registros nuevos mezclados
                // con los viejos, que es la peor de las dos opciones.
                '--clean',
                '--if-exists',
                '--no-owner',
                '--no-privileges',
                // Todo o nada: que un error a mitad no deje media base puesta.
                '--exit-on-error',
                '--single-transaction',
                $path,
            ]);

        if ($resultado->failed()) {
            throw new RuntimeException($this->reason(
                $resultado->errorOutput(),
                'No se pudo restaurar la copia. La base se ha quedado como estaba.',
            ));
        }

        Log::warning('Base restaurada desde una copia subida', ['by' => auth()->user()?->email]);
    }

    /** El formato `custom` empieza por `PGDMP`; cualquier otra cosa no es una copia. */
    public function looksLikeADump(string $path): bool
    {
        return is_file($path) && file_get_contents($path, length: strlen(self::MAGIC)) === self::MAGIC;
    }

    /**
     * Si las herramientas están en la imagen. Sin ellas la pantalla no promete
     * nada, y se comprueba una vez por petición y no una por render.
     */
    public function toolsAvailable(): bool
    {
        return $this->tools ??= Process::run(['pg_dump', '--version'])->successful();
    }

    /** @return list<string> */
    private function connectionArguments(): array
    {
        $conexion = config('database.connections.'.config('database.default'));

        return [
            '--host='.$conexion['host'],
            '--port='.$conexion['port'],
            '--username='.$conexion['username'],
            '--dbname='.$conexion['database'],
            // Sin esto, el cliente se para a pedir la contraseña por teclado y
            // el proceso se queda colgado hasta el timeout.
            '--no-password',
        ];
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        // Por entorno y no como argumento: lo que va en la línea de mandato lo
        // ve cualquiera que liste los procesos de la máquina (§10).
        return ['PGPASSWORD' => (string) config('database.connections.'.config('database.default').'.password')];
    }

    /** La última línea de `stderr`, que es la que dice qué pasó de verdad. */
    private function reason(string $stderr, string $prefijo): string
    {
        $lineas = array_values(array_filter(array_map('trim', explode("\n", $stderr))));

        return $lineas === [] ? $prefijo : $prefijo.' '.end($lineas);
    }
}
