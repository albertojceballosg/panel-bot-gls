<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un paquete evaluado de una jornada (CONTEXTO.md §3.1).
 *
 * **Una fila por paquete, no por incidencia**: `type` nulo es un paquete que
 * pasó por la cinta con el grueso de su ruta, que es la mayoría. Ver la
 * migración para por qué es una sola tabla y no dos.
 *
 * Como `IncidentRun`, **no es `Auditable`**: lo escribe el bot, no una persona.
 *
 * Ojo al leerlo: `merchant_name`, `assigned_route_name` y `assigned_courier_name`
 * son la foto del día. Para enseñar una fila hay que usarlos a ellos, no
 * `$package->merchant->name`, o una ruta renombrada reescribiría el pasado.
 */
class RunPackage extends Model
{
    /**
     * Sólo lo que manda el bot: esta lista es la superficie del contrato de
     * §3.1. La gestión del panel —`handled_at`, `handled_by`, `handling_note`—
     * se asigna a mano en la pantalla y **no entra aquí a propósito**, para que
     * ningún día un campo del payload acabe escribiéndola.
     */
    protected $fillable = [
        'incident_run_id', 'shipment_id', 'barcode',
        'merchant_id', 'merchant_name',
        'assigned_route_id', 'assigned_route_name', 'assigned_courier_name',
        'observed_route_id', 'observed_route_name',
        'type', 'belt_time', 'deviation_minutes', 'volume_m3', 'net_revenue', 'real_cost',
        'compatible_routes', 'batch_shared_routes', 'confidence', 'confidence_reasons',
        'withdrawn_at',
    ];

    /** El paquete pasó en la tanda principal de otra ruta: hay a quién señalar. */
    public const TYPE_OTHER_ROUTE = 'tanda_de_otra_ruta';

    /** Pasó fuera de la tanda de su ruta, pero esa tanda no era de nadie. */
    public const TYPE_OUT_OF_BATCH = 'fuera_de_tanda';

    public const CONFIDENCE_HIGH = 'alta';

    public const CONFIDENCE_LOW = 'baja';

    protected function casts(): array
    {
        return [
            'belt_time' => 'datetime',
            'withdrawn_at' => 'datetime',
            'handled_at' => 'datetime',
            'deviation_minutes' => 'float',
            // Nulo si el portal no dio el dato. No confundir con cero: ver la migración.
            'volume_m3' => 'float',
            // Ídem: nulo es «el envío no está en Envexpress», no «no dejó nada».
            'net_revenue' => 'float',
            // Lo que costó el envío. Aquí, al revés que en las dos de arriba, **el cero es un
            // dato**: alguien tecleó un 0 en la ficha. Nulo es «no lo rellenó nadie».
            'real_cost' => 'float',
            'compatible_routes' => 'array',
            'batch_shared_routes' => 'array',
            'confidence_reasons' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(IncidentRun::class, 'incident_run_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class)->withTrashed();
    }

    public function assignedRoute(): BelongsTo
    {
        return $this->belongsTo(PickupRoute::class, 'assigned_route_id')->withTrashed();
    }

    public function observedRoute(): BelongsTo
    {
        return $this->belongsTo(PickupRoute::class, 'observed_route_id')->withTrashed();
    }

    /** Las que siguen vigentes: no retiradas en un reenvío posterior. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('withdrawn_at');
    }

    /** Sólo lo que salió mal. El resto de la jornada pasó donde debía. */
    public function scopeIncidents(Builder $query): Builder
    {
        return $query->whereNotNull('type');
    }

    /** Quien la marcó como atendida, si sigue en el maestro de usuarios. */
    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by')->withTrashed();
    }

    /**
     * Si alguien ya se ocupó de esto.
     *
     * `handled_at` es la única fuente de verdad del estado: un booleano aparte
     * podría contradecir a la fecha, y entonces ninguna de las dos vale.
     */
    public function isHandled(): bool
    {
        return $this->handled_at !== null;
    }

    public function isIncident(): bool
    {
        return $this->type !== null;
    }

    /**
     * Si el bot sostiene el hallazgo sin reservas. Cuando es `false`, o la ruta
     * del comercio pasó desperdigada por la cinta o la tanda la compartían varias
     * rutas: en ninguno de los dos casos se puede afirmar quién recogió qué.
     *
     * Un paquete sin incidencia no es «no concluyente»: no hay nada que concluir.
     */
    public function isConclusive(): bool
    {
        return $this->confidence === self::CONFIDENCE_HIGH;
    }
}
