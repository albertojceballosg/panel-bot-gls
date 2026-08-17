<?php

namespace App\Support;

use App\Models\RunPackage;

/**
 * Traduce a castellano lo que el bot manda en clave (CONTEXTO.md §3.1).
 *
 * Vive fuera de las pantallas por la misma razón que `AuditPresenter`: lo usan
 * el listado, el detalle de la jornada y el del paquete, y tenerlo en una de
 * ellas obligaría a copiarlo en las otras dos.
 *
 * **Las palabras no son decorado.** Un `motivo_confianza: ["ruta_dispersa"]`
 * pintado tal cual no le dice nada al cliente; escrito en una frase le dice por
 * qué esa fila no basta para hablar con un mensajero. Los textos salen de la
 * especificación de la pantalla en §7, fase 6.C, obligación 1.
 */
class IncidentPresenter
{
    /**
     * Por qué el bot no sostiene un hallazgo, en palabras y no en clave.
     *
     * `$routes` son las rutas implicadas en *ese* motivo, para poder nombrarlas: decir
     * «dos furgonetas descargaron juntas» sin decir cuáles obliga a quien lee a ir a
     * buscarlo a las alertas de la jornada. Vacío cuando no se sabe —una jornada anterior
     * a la v3 del payload no traía el dato— y entonces la frase se queda como estaba.
     */
    public static function reason(string $reason, array $routes = [], ?string $observed = null): string
    {
        // Fuera la ruta que la fila ya señala: repetirla no aporta nada y hace que la nota
        // parezca contradecir a la columna. Lo que el lector necesita saber es cuál era la
        // OTRA opción — «apunta a Ruta 4, pero podría haber sido Ruta 3».
        $otras = self::names(array_values(array_filter(
            $routes,
            fn (array $r) => ($r['nombre'] ?? null) !== $observed,
        )));
        $todas = self::names($routes);

        return match (true) {
            $reason === 'tanda_compartida' && $observed !== null && $otras !== '' => "podría haber sido {$otras}, que descargaba en el mismo bloque",
            $reason === 'tanda_compartida' && $todas !== '' => "{$todas} descargaron juntas: por la hora no se puede saber cuál lo llevó",
            $reason === 'ventana_compartida' && $todas !== '' => "a esa hora estaban descargando {$todas}: encaja en más de una",
            default => self::plain($reason),
        };
    }

    /** «Ruta 3 y Ruta 4»; «Ruta 2, Ruta 3 y Ruta 6» a partir de tres. */
    private static function names(array $routes): string
    {
        $nombres = collect($routes)->pluck('nombre')->filter()->values();

        if ($nombres->count() < 2) {
            return $nombres->implode('');
        }

        return $nombres->slice(0, -1)->implode(', ').' y '.$nombres->last();
    }

    private static function plain(string $reason): string
    {
        return match ($reason) {
            'ruta_dispersa' => 'esa ruta pasó desperdigada por la cinta ese día',
            'tanda_compartida' => 'dos furgonetas descargaron juntas: por la hora no se puede saber cuál lo llevó',

            // Desde la v2 del payload (17/08/2026). No es lo mismo que el anterior y por eso
            // son dos: `tanda_compartida` habla de la jornada —dos rutas descargaron en el
            // mismo bloque—, y éste de la hora concreta de *este* paquete, que cae dentro de
            // la ventana de más de una ruta. Pueden darse por separado.
            'ventana_compartida' => 'a esa hora estaban descargando varias rutas: encaja en más de una',

            // Un motivo que el bot añada mañana no puede dejar la fila muda.
            default => str_replace('_', ' ', $reason),
        };
    }

    /**
     * Cada motivo con las rutas que le corresponden.
     *
     * A cada uno el suyo: `tanda_compartida` se explica con quiénes compartían el bloque de
     * descarga y `ventana_compartida` con quiénes encajan en esa hora concreta. Suelen ser
     * las mismas rutas, pero no tienen por qué, y cruzarlas diría algo falso.
     *
     * @return list<string>
     */
    public static function reasons(RunPackage $incident): array
    {
        $rutasDe = [
            'tanda_compartida' => $incident->batch_shared_routes ?? [],
            'ventana_compartida' => $incident->compatible_routes ?? [],
        ];

        return array_map(
            fn (string $motivo) => self::reason(
                $motivo,
                $rutasDe[$motivo] ?? [],
                $incident->observed_route_name,
            ),
            $incident->confidence_reasons ?? [],
        );
    }

    /**
     * Qué clase de hallazgo es. Los dos no se mezclan (§3.1, obligación 3):
     * uno señala a alguien y el otro no, y juntarlos convierte 56 hechos
     * neutros en 56 acusaciones.
     */
    public static function type(string $type): string
    {
        return match ($type) {
            RunPackage::TYPE_OTHER_ROUTE => 'Se fue con otra ruta',
            RunPackage::TYPE_OUT_OF_BATCH => 'Pasó descolgado',
            default => $type,
        };
    }

    /** El desvío contra la mediana de su ruta, con signo y en palabras. */
    public static function deviation(?float $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        $signo = $minutes > 0 ? '+' : '';

        return $signo.number_format($minutes, 1, ',', '.').' min';
    }
}
