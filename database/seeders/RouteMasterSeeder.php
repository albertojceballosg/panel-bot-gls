<?php

namespace Database\Seeders;

use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PickupRoute;
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

        $pickupRoutes = $this->seedPickupRoutes($rows);
        $this->seedCouriers($rows, $pickupRoutes);
        $this->seedMerchants($rows, $pickupRoutes);

        $this->command?->info(sprintf(
            '  Maestro cargado: %d comercios en %d rutas.',
            count($rows),
            count($pickupRoutes),
        ));
    }

    /**
     * @return list<array{name: string, courier: string, pickup_route: string}>
     */
    private function read(string $path): array
    {
        $file = fopen($path, 'r');
        $header = fgetcsv($file, escape: '');

        $expected = ['name', 'courier', 'pickup_route'];
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
            [$name, $courier, $pickupRouteText] = array_pad($row, 3, '');

            // El nombre se guarda tal cual viene: es lo que sirve el contrato
            // (§3, "sin normalizar") y lo que el bot cruza contra el portal.
            $name = trim($name);
            $courier = trim($courier);

            if ($name === '' || $courier === '') {
                throw new RuntimeException("Fila {$n}: falta el nombre del comercio o el del mensajero.");
            }

            // La ruta del CSV ("1".."6") pasa a ser el NOMBRE de la ruta, no un
            // número: son etiquetas renombrables desde el panel (§4).
            $pickupRoute = trim($pickupRouteText);

            if ($pickupRoute === '') {
                throw new RuntimeException(
                    "Fila {$n}: el comercio \"{$name}\" no trae ruta, y sin ruta no se ".
                    'puede agrupar. Ver CONTEXTO.md §4.'
                );
            }

            $rows[] = ['name' => $name, 'courier' => $courier, 'pickup_route' => $pickupRoute];
        }

        fclose($file);

        return $rows;
    }

    /**
     * @param  list<array{name: string, courier: string, pickup_route: string}>  $rows
     * @return array<string, PickupRoute> Indexado por nombre de ruta.
     */
    private function seedPickupRoutes(array $rows): array
    {
        $pickupRoutes = [];

        foreach (array_unique(array_column($rows, 'pickup_route')) as $name) {
            $pickupRoutes[$name] = $this->revive(PickupRoute::withTrashed()->firstOrNew(['name' => $name]));
        }

        return $pickupRoutes;
    }

    /**
     * @param  list<array{name: string, courier: string, pickup_route: string}>  $rows
     * @param  array<string, PickupRoute>  $pickupRoutes
     */
    private function seedCouriers(array $rows, array $pickupRoutes): void
    {
        // Un mensajero, una ruta, y una ruta un mensajero (§4). Si el origen no
        // lo cumple hay que parar: el contrato sirve un único `mensajero` por
        // comercio, así que cualquiera de los dos choques lo dejaría ambiguo.
        $routeNamesPerCourier = [];
        foreach ($rows as $row) {
            $routeNamesPerCourier[$row['courier']][$row['pickup_route']] = true;
        }

        $takenBy = [];

        foreach ($routeNamesPerCourier as $courierName => $theirs) {
            if (count($theirs) > 1) {
                throw new RuntimeException(sprintf(
                    'El mensajero "%s" aparece en %d rutas distintas (%s). Un mensajero '.
                    'conduce una sola ruta: ver CONTEXTO.md §4.',
                    $courierName,
                    count($theirs),
                    implode(', ', array_keys($theirs)),
                ));
            }

            $routeName = array_key_first($theirs);

            if (isset($takenBy[$routeName])) {
                throw new RuntimeException(sprintf(
                    'La ruta "%s" tiene dos mensajeros ("%s" y "%s"). El contrato sirve '.
                    'uno solo por comercio: ver CONTEXTO.md §3.',
                    $routeName,
                    $takenBy[$routeName],
                    $courierName,
                ));
            }

            $takenBy[$routeName] = $courierName;

            $courier = Courier::withTrashed()->firstOrNew(['name' => $courierName]);
            $courier->pickup_route_id = $pickupRoutes[$routeName]->id;
            $this->revive($courier);
        }
    }

    /**
     * @param  list<array{name: string, courier: string, pickup_route: string}>  $rows
     * @param  array<string, PickupRoute>  $pickupRoutes
     */
    private function seedMerchants(array $rows, array $pickupRoutes): void
    {
        foreach ($rows as $row) {
            // Deliberadamente no se toca `code`: el maestro de origen no lo
            // trae, así que nace nulo, pero si alguien ya lo rellenó a mano (o
            // lo hizo el backfill del §8) volver a sembrar no debe borrárselo.
            $merchant = Merchant::withTrashed()->firstOrNew(['name' => $row['name']]);
            $merchant->pickup_route_id = $pickupRoutes[$row['pickup_route']]->id;
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
