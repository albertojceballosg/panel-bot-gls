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
    protected $fillable = [
        'incident_run_id', 'shipment_id', 'barcode',
        'merchant_id', 'merchant_name',
        'assigned_route_id', 'assigned_route_name', 'assigned_courier_name',
        'observed_route_id', 'observed_route_name',
        'type', 'belt_time', 'deviation_minutes',
        'compatible_routes', 'confidence', 'confidence_reasons', 'withdrawn_at',
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
            'deviation_minutes' => 'float',
            'compatible_routes' => 'array',
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
