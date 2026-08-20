<?php

namespace App\Support;

use App\Models\RouteExpense;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * El gasto fijo de una ruta repartido por día (CONTEXTO.md §7, fase 15).
 *
 * Los gastos se llevan **al mes** —un sueldo, la gasolina, el seguro— y las corridas del bot
 * son **diarias**, así que para poner un día al lado de lo que costó hay que repartir. Aquí
 * está ese reparto, y sólo eso: quién lo enseña es cosa de la pantalla.
 *
 * **El divisor son los días laborables del mes natural, de lunes a viernes** (decisión del
 * cliente, 20/08/2026). Se eligió por encima de «los días que de verdad hubo corrida», que a
 * fin de mes repartiría exacto, porque ese divisor **crece según avanza el mes**: mirando el
 * 03/08 el día 3 saldrían 1.000 €/día y el día 31, 47,62 €. El mismo día diría tres cifras
 * distintas según cuándo se mire, y una cifra que cambia sola no se puede contrastar con
 * nada. Lo que se paga a cambio: un sábado trabajado no lleva gasto fijo y sale más rentable
 * de lo que fue, y un festivo entre semana se queda con su parte sin que nadie la trabajase.
 * Si algún día se trabajan sábados de forma habitual, esto pasa a Configuraciones.
 *
 * **No prorratea el `real_cost` de los envíos**: ése ya es diario y va por paquete (§3.1). La
 * pantalla suma las dos mitades; aquí sólo está la fija.
 */
final class DailyExpenseShare
{
    /**
     * Cuántos días laborables tiene el mes de esa fecha. Lunes a viernes, sin descontar
     * festivos: el panel no tiene calendario laboral y adivinarlo sería peor que no tenerlo.
     */
    public static function workingDays(Carbon $day): int
    {
        $dia = $day->copy()->startOfMonth();
        $fin = $day->copy()->endOfMonth();
        $laborables = 0;

        while ($dia <= $fin) {
            $laborables += $dia->isWeekend() ? 0 : 1;
            $dia->addDay();
        }

        return $laborables;
    }

    /**
     * Lo que le toca a cada ruta cada uno de esos días.
     *
     * **Una sola consulta**, cubran los días los meses que cubran: se traen las líneas cuyo
     * periodo toca el rango entero —son decenas, no miles— y el reparto se hace en PHP. Con
     * una consulta por mes, una semana a caballo entre dos costaría dos, y la tabla del
     * calendario tiene un test que le cuenta las consultas.
     *
     * @param  Collection<int, Carbon>  $days
     * @return array<string, array<int, float>> `aaaa-mm-dd` => [pickup_route_id => euros]
     */
    public static function forDays(Collection $days): array
    {
        if ($days->isEmpty()) {
            return [];
        }

        $meses = $days
            ->map(fn (Carbon $dia) => $dia->copy()->startOfMonth())
            ->unique(fn (Carbon $mes) => $mes->format('Y-m'))
            ->values();

        $desde = $meses->min();
        $hasta = $meses->max()->copy()->endOfMonth();

        $lineas = RouteExpense::query()
            ->whereDate('starts_on', '<=', $hasta)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $desde))
            ->get(['pickup_route_id', 'amount', 'starts_on', 'ends_on']);

        // El reparto de cada mes se calcula una vez y lo comparten todos sus días.
        $porMes = $meses->mapWithKeys(function (Carbon $mes) use ($lineas) {
            $laborables = self::workingDays($mes);

            // Un mes sin días laborables no existe, pero dividir por cero sí.
            if ($laborables === 0) {
                return [$mes->format('Y-m') => []];
            }

            $reparto = [];

            foreach ($lineas as $linea) {
                if (! $linea->isActiveIn($mes)) {
                    continue;
                }

                $ruta = (int) $linea->pickup_route_id;
                $reparto[$ruta] = ($reparto[$ruta] ?? 0.0) + $linea->amount / $laborables;
            }

            return [$mes->format('Y-m') => $reparto];
        });

        return $days
            ->mapWithKeys(fn (Carbon $dia) => [
                $dia->toDateString() => $porMes[$dia->format('Y-m')] ?? [],
            ])
            ->all();
    }
}
