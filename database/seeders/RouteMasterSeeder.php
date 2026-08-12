<?php

namespace Database\Seeders;

use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Route;
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
class RouteMasterSeeder extends Seeder
{
    private const SOURCE = 'data/merchants.csv';

    public function run(): void
    {
        $path = database_path('seeders/'.self::SOURCE);

        if (! is_readable($path)) {
            throw new RuntimeException(
                "No encuentro {$path}. Es el CSV derivado de rutas.xlsx y no se ".
                'versiona por confidencialidad: ver CONTEXTO.md §9.'
            );
        }

        $rows = $this->read($path);

        if ($rows === []) {
            throw new RuntimeException('El CSV no tiene ni una fila de datos.');
        }

        $routes = $this->seedRoutes($rows);
        $this->seedCouriers($rows, $routes);
        $this->seedMerchants($rows, $routes);

        $this->command?->info(sprintf(
            '  Maestro cargado: %d comercios en %d rutas.',
            count($rows),
            count($routes),
        ));
    }

    /**
     * @return list<array{name: string, courier: string, route: string}>
     */
    private function read(string $path): array
    {
        $file = fopen($path, 'r');
        $header = fgetcsv($file, escape: '');

        $expected = ['name', 'courier', 'route'];
        if ($header !== $expected) {
            throw new RuntimeException(
                'La cabecera del CSV debería ser "'.implode(',', $expected).
                '" y es "'.implode(',', (array) $header).'".'
            );
        }

        $rows = [];
        $n = 1;

        while ($row = fgetcsv($file, escape: '')) {
            $n++;
            [$name, $courier, $routeText] = array_pad($row, 3, '');

            // El nombre se guarda tal cual viene: es lo que sirve el contrato
            // (§3, "sin normalizar") y lo que el bot cruza contra el portal.
            $name = trim($name);
            $courier = trim($courier);

            if ($name === '' || $courier === '') {
                throw new RuntimeException("Fila {$n}: falta el nombre del comercio o el del mensajero.");
            }

            // La ruta del CSV ("1".."6") pasa a ser el NOMBRE de la ruta, no un
            // número: son etiquetas renombrables desde el panel (§4).
            $route = trim($routeText);

            if ($route === '') {
                throw new RuntimeException(
                    "Fila {$n}: el comercio \"{$name}\" no trae ruta, y sin ruta no se ".
                    'puede agrupar. Ver CONTEXTO.md §4.'
                );
            }

            $rows[] = ['name' => $name, 'courier' => $courier, 'route' => $route];
        }

        fclose($file);

        return $rows;
    }

    /**
     * @param  list<array{name: string, courier: string, route: string}>  $rows
     * @return array<string, Route> Indexado por nombre de ruta.
     */
    private function seedRoutes(array $rows): array
    {
        $routes = [];

        foreach (array_unique(array_column($rows, 'route')) as $name) {
            $routes[$name] = $this->revive(Route::withTrashed()->firstOrNew(['name' => $name]));
        }

        return $routes;
    }

    /**
     * @param  list<array{name: string, courier: string, route: string}>  $rows
     * @param  array<string, Route>  $routes
     */
    private function seedCouriers(array $rows, array $routes): void
    {
        // Un mensajero, una ruta, y una ruta un mensajero (§4). Si el origen no
        // lo cumple hay que parar: el contrato sirve un único `mensajero` por
        // comercio, así que cualquiera de los dos choques lo dejaría ambiguo.
        $routesPerCourier = [];
        foreach ($rows as $row) {
            $routesPerCourier[$row['courier']][$row['route']] = true;
        }

        $taken = [];

        foreach ($routesPerCourier as $name => $theirs) {
            if (count($theirs) > 1) {
                throw new RuntimeException(sprintf(
                    'El mensajero "%s" aparece en %d rutas distintas (%s). Un mensajero '.
                    'conduce una sola ruta: ver CONTEXTO.md §4.',
                    $name,
                    count($theirs),
                    implode(', ', array_keys($theirs)),
                ));
            }

            $route = array_key_first($theirs);

            if (isset($taken[$route])) {
                throw new RuntimeException(sprintf(
                    'La ruta "%s" tiene dos mensajeros ("%s" y "%s"). El contrato sirve '.
                    'uno solo por comercio: ver CONTEXTO.md §3.',
                    $route,
                    $taken[$route],
                    $name,
                ));
            }

            $taken[$route] = $name;

            $courier = Courier::withTrashed()->firstOrNew(['name' => $name]);
            $courier->route_id = $routes[$route]->id;
            $this->revive($courier);
        }
    }

    /**
     * @param  list<array{name: string, courier: string, route: string}>  $rows
     * @param  array<string, Route>  $routes
     */
    private function seedMerchants(array $rows, array $routes): void
    {
        foreach ($rows as $row) {
            // Deliberadamente no se toca `code`: el maestro de origen no lo
            // trae, así que nace nulo, pero si alguien ya lo rellenó a mano (o
            // lo hizo el backfill del §8) volver a sembrar no debe borrárselo.
            $merchant = Merchant::withTrashed()->firstOrNew(['name' => $row['name']]);
            $merchant->route_id = $routes[$row['route']]->id;
            $this->revive($merchant);
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
     * @param  T  $model
     * @return T
     */
    private function revive($model)
    {
        $model->deleted_at = null;
        $model->save();

        return $model;
    }
}
