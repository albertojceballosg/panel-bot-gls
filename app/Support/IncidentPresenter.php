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
    /** Por qué el bot no sostiene un hallazgo, en palabras y no en clave. */
    public static function reason(string $reason): string
    {
        return match ($reason) {
            'ruta_dispersa' => 'esa ruta pasó desperdigada por la cinta ese día',
            'tanda_compartida' => 'dos furgonetas descargaron juntas: por la hora no se puede saber cuál lo llevó',

            // Un motivo que el bot añada mañana no puede dejar la fila muda.
            default => str_replace('_', ' ', $reason),
        };
    }

    /** @return list<string> */
    public static function reasons(RunPackage $incident): array
    {
        return array_map(self::reason(...), $incident->confidence_reasons ?? []);
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
