<?php

namespace Database\Seeders;

use App\Models\Comercio;
use App\Models\Mensajero;
use App\Models\Ruta;
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

        $rutas = $this->sembrarRutas($filas);
        $this->sembrarMensajeros($filas, $rutas);
        $this->sembrarComercios($filas, $rutas);

        $this->command?->info(sprintf(
            '  Maestro cargado: %d comercios en %d rutas.',
            count($filas),
            count($rutas),
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

            // La ruta del CSV ("1".."6") pasa a ser el NOMBRE de la ruta, no un
            // número: son etiquetas renombrables desde el panel (§4).
            $ruta = trim($rutaTexto);

            if ($ruta === '') {
                throw new RuntimeException(
                    "Fila {$n}: el comercio \"{$nombre}\" no trae ruta, y sin ruta no se ".
                    'puede agrupar. Ver CONTEXTO.md §4.'
                );
            }

            $filas[] = [
                'nombre' => $nombre,
                'mensajero' => $mensajero,
                'ruta' => $ruta,
            ];
        }

        fclose($fichero);

        return $filas;
    }

    /**
     * @param  list<array{nombre: string, mensajero: string, ruta: string}>  $filas
     * @return array<string, Ruta> Indexado por nombre de ruta.
     */
    private function sembrarRutas(array $filas): array
    {
        $rutas = [];

        foreach (array_unique(array_column($filas, 'ruta')) as $nombre) {
            $rutas[$nombre] = $this->revivir(Ruta::withTrashed()->firstOrNew(['nombre' => $nombre]));
        }

        return $rutas;
    }

    /**
     * @param  list<array{nombre: string, mensajero: string, ruta: string}>  $filas
     * @param  array<string, Ruta>  $rutas
     */
    private function sembrarMensajeros(array $filas, array $rutas): void
    {
        // Un mensajero, una ruta, y una ruta un mensajero (§4). Si el origen no
        // lo cumple hay que parar: el contrato sirve un único `mensajero` por
        // comercio, así que cualquiera de los dos choques lo dejaría ambiguo.
        $rutasPorMensajero = [];
        foreach ($filas as $fila) {
            $rutasPorMensajero[$fila['mensajero']][$fila['ruta']] = true;
        }

        $asignadas = [];

        foreach ($rutasPorMensajero as $nombre => $suyas) {
            if (count($suyas) > 1) {
                throw new RuntimeException(sprintf(
                    'El mensajero "%s" aparece en %d rutas distintas (%s). Un mensajero '.
                    'conduce una sola ruta: ver CONTEXTO.md §4.',
                    $nombre,
                    count($suyas),
                    implode(', ', array_keys($suyas)),
                ));
            }

            $ruta = array_key_first($suyas);

            if (isset($asignadas[$ruta])) {
                throw new RuntimeException(sprintf(
                    'La ruta "%s" tiene dos mensajeros ("%s" y "%s"). El contrato sirve '.
                    'uno solo por comercio: ver CONTEXTO.md §3.',
                    $ruta,
                    $asignadas[$ruta],
                    $nombre,
                ));
            }

            $asignadas[$ruta] = $nombre;

            $mensajero = Mensajero::withTrashed()->firstOrNew(['nombre' => $nombre]);
            $mensajero->ruta_id = $rutas[$ruta]->id;
            $this->revivir($mensajero);
        }
    }

    /**
     * @param  list<array{nombre: string, mensajero: string, ruta: string}>  $filas
     * @param  array<string, Ruta>  $rutas
     */
    private function sembrarComercios(array $filas, array $rutas): void
    {
        foreach ($filas as $fila) {
            // Deliberadamente no se toca `codigo`: el maestro de origen no lo
            // trae, así que nace nulo, pero si alguien ya lo rellenó a mano (o
            // lo hizo el backfill del §8) volver a sembrar no debe borrárselo.
            $comercio = Comercio::withTrashed()->firstOrNew(['nombre' => $fila['nombre']]);
            $comercio->ruta_id = $rutas[$fila['ruta']]->id;
            $this->revivir($comercio);
        }
    }

    /**
     * Guarda, y si el registro estaba dado de baja lo devuelve a la vida.
     *
     * El seeder carga la lista completa del maestro (§3, regla 1): si algo
     * vuelve a aparecer en el origen, es que está vigente. Sin esto chocaría
     * contra el índice único parcial en vez de resucitar, y el error sería
     * incomprensible — la fila que estorba no se ve por ningún lado.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  T  $modelo
     * @return T
     */
    private function revivir($modelo)
    {
        $modelo->deleted_at = null;
        $modelo->save();

        return $modelo;
    }
}
