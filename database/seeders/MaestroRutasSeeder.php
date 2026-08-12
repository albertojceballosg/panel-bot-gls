<?php

namespace Database\Seeders;

use App\Models\Comercio;
use App\Models\Mensajero;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Carga el maestro de rutas desde el CSV derivado de `rutas.xlsx`.
 *
 * El CSV **no está en el repo** (CONTEXTO.md §9): contiene nombres reales de
 * los comercios del cliente. Sin él este seeder no puede correr, y avisa.
 *
 * Es idempotente: se puede volver a pasar sobre una base ya sembrada.
 */
class MaestroRutasSeeder extends Seeder
{
    private const ORIGEN = 'data/comercios.csv';

    public function run(): void
    {
        $ruta = database_path('seeders/'.self::ORIGEN);

        if (! is_readable($ruta)) {
            throw new RuntimeException(
                "No encuentro {$ruta}. Es el CSV derivado de rutas.xlsx y no se ".
                'versiona por confidencialidad: ver CONTEXTO.md §9.'
            );
        }

        $filas = $this->leer($ruta);

        if ($filas === []) {
            throw new RuntimeException('El CSV no tiene ni una fila de datos.');
        }

        $mensajeros = $this->sembrarMensajeros($filas);
        $this->sembrarComercios($filas, $mensajeros);

        $this->command?->info(sprintf(
            '  Maestro cargado: %d comercios en %d rutas.',
            count($filas),
            count($mensajeros),
        ));
    }

    /**
     * @return list<array{nombre: string, mensajero: string, ruta: ?int}>
     */
    private function leer(string $ruta): array
    {
        $fichero = fopen($ruta, 'r');
        $cabecera = fgetcsv($fichero, escape: '');

        $esperada = ['nombre', 'mensajero', 'ruta'];
        if ($cabecera !== $esperada) {
            throw new RuntimeException(
                'La cabecera del CSV debería ser "'.implode(',', $esperada).
                '" y es "'.implode(',', (array) $cabecera).'".'
            );
        }

        $filas = [];
        $n = 1;

        while ($fila = fgetcsv($fichero, escape: '')) {
            $n++;
            [$nombre, $mensajero, $rutaTexto] = array_pad($fila, 3, '');

            // El nombre se guarda tal cual viene: es lo que sirve el contrato
            // (§3, "sin normalizar") y lo que el bot cruza contra el portal.
            $nombre = trim($nombre);
            $mensajero = trim($mensajero);

            if ($nombre === '' || $mensajero === '') {
                throw new RuntimeException("Fila {$n}: falta el nombre del comercio o el del mensajero.");
            }

            $filas[] = [
                'nombre' => $nombre,
                'mensajero' => $mensajero,
                // El contrato admite mensajero sin número de ruta asignado.
                'ruta' => $rutaTexto === '' ? null : (int) $rutaTexto,
            ];
        }

        fclose($fichero);

        return $filas;
    }

    /**
     * @param  list<array{nombre: string, mensajero: string, ruta: ?int}>  $filas
     * @return array<string, Mensajero> Indexado por nombre de mensajero.
     */
    private function sembrarMensajeros(array $filas): array
    {
        // Un mensajero, una ruta (§4). Si el origen trae dos rutas para el mismo
        // mensajero hay que parar: es justo el error que el modelo de datos
        // existe para impedir, y que el bot no sabría detectar.
        $rutasPorMensajero = [];
        foreach ($filas as $fila) {
            $rutasPorMensajero[$fila['mensajero']][] = $fila['ruta'];
        }

        $mensajeros = [];

        foreach ($rutasPorMensajero as $nombre => $rutas) {
            $distintas = array_unique($rutas, SORT_REGULAR);

            if (count($distintas) > 1) {
                throw new RuntimeException(sprintf(
                    'El mensajero "%s" aparece con %d rutas distintas (%s). '.
                    'Un mensajero hace una sola ruta: ver CONTEXTO.md §4.',
                    $nombre,
                    count($distintas),
                    implode(', ', array_map(fn ($r) => $r ?? 'null', $distintas)),
                ));
            }

            $mensajeros[$nombre] = Mensajero::updateOrCreate(
                ['nombre' => $nombre],
                ['ruta' => reset($rutas)],
            );
        }

        return $mensajeros;
    }

    /**
     * @param  list<array{nombre: string, mensajero: string, ruta: ?int}>  $filas
     * @param  array<string, Mensajero>  $mensajeros
     */
    private function sembrarComercios(array $filas, array $mensajeros): void
    {
        foreach ($filas as $fila) {
            // Deliberadamente no se toca `codigo`: el maestro de origen no lo
            // trae, así que nace nulo, pero si alguien ya lo rellenó a mano (o
            // lo hizo el backfill del §8) volver a sembrar no debe borrárselo.
            $comercio = Comercio::firstOrNew(['nombre' => $fila['nombre']]);
            $comercio->mensajero_id = $mensajeros[$fila['mensajero']]->id;
            $comercio->save();
        }
    }
}
