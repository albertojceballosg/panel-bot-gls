<?php

namespace Tests\Feature;

use App\Models\IncidentRun;
use App\Models\RunPackage;

/**
 * Jornadas e incidencias para los tests de pantalla.
 *
 * Un *trait* y no un *factory* porque `IncidentRun` y `RunPackage` no los crea
 * nunca el panel —los escribe el bot por el endpoint de §3.1—, así que un
 * factory viviría en `database/factories` sugiriendo que hay altas por aquí.
 *
 * Los valores por defecto son los del 03/08/2026 real, para que los tests se
 * lean contra las cifras que aparecen en `CONTEXTO.md`.
 */
trait MakesIncidents
{
    protected function storedRun(
        string $date = '2026-08-03',
        bool $reliable = true,
        array $alerts = [],
    ): IncidentRun {
        return IncidentRun::create([
            'run_date' => $date,
            'payload_version' => 1,
            'generated_at' => now(),
            'master_generated_at' => now(),
            'reliable' => $reliable,
            'tolerance_minutes' => 20,
            'batch_gap_minutes' => 5,
            'shipments' => 983,
            'evaluated' => 459,
            'incidents_reported' => 168,
            'without_belt_time' => 34,
            'without_route' => 490,
            'alerts' => $alerts,
        ]);
    }

    protected function incident(
        IncidentRun $run,
        string $shipment,
        string $type = RunPackage::TYPE_OTHER_ROUTE,
        string $confidence = RunPackage::CONFIDENCE_LOW,
        string $merchant = 'COBO FAMILY, S.L.',
        ?string $assignedRoute = 'Ruta 3',
        ?string $courier = 'Freddy GLS',
        ?string $observedRoute = 'Ruta 1',
        array $reasons = ['ruta_dispersa'],
        array $compatible = [],
        ?float $revenue = null,
    ): RunPackage {
        return RunPackage::create([
            'incident_run_id' => $run->id,
            'shipment_id' => $shipment,
            'barcode' => '6132630'.$shipment,
            'merchant_name' => $merchant,
            'assigned_route_name' => $assignedRoute,
            'assigned_courier_name' => $courier,

            // Un descolgado no señala a nadie: su ruta observada es nula (§3.1).
            'observed_route_name' => $type === RunPackage::TYPE_OUT_OF_BATCH ? null : $observedRoute,
            'type' => $type,
            'belt_time' => '2026-08-03T19:15:27+00:00',
            'deviation_minutes' => 22.3,
            // Nula por defecto: es lo que trae toda jornada anterior a la v4 (§3.1).
            'net_revenue' => $revenue,
            'compatible_routes' => $compatible,
            'confidence' => $confidence,
            'confidence_reasons' => $confidence === RunPackage::CONFIDENCE_HIGH ? [] : $reasons,
        ]);
    }

    /**
     * Un paquete que pasó donde debía: `type` nulo. Es la mayoría de la jornada
     * y lo que pone las incidencias en proporción.
     */
    protected function package(
        IncidentRun $run,
        string $shipment,
        string $merchant = 'BOHOCHIQUE',
        ?string $assignedRoute = 'Ruta 3',
        ?string $courier = 'Freddy GLS',
        ?string $beltTime = '2026-08-03T19:55:02+00:00',
        ?float $revenue = null,
    ): RunPackage {
        return RunPackage::create([
            'incident_run_id' => $run->id,
            'shipment_id' => $shipment,
            'barcode' => '6132630'.$shipment,
            'merchant_name' => $merchant,
            'assigned_route_name' => $assignedRoute,
            'assigned_courier_name' => $courier,
            'belt_time' => $beltTime,
            'net_revenue' => $revenue,
            'compatible_routes' => [],
            'confidence_reasons' => [],
        ]);
    }
}
