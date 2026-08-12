<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\Rule;

/** El mensajero que conduce una ruta de recogida. */
class Courier extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = ['name', 'pickup_route_id'];

    public function pickupRoute(): BelongsTo
    {
        return $this->belongsTo(PickupRoute::class);
    }

    /**
     * Los comercios por los que pasa, a través de su ruta. Derivado a propósito:
     * el mensajero no "tiene" comercios, conduce una ruta que los tiene. Así no
     * hay dos sitios donde pueda decir a qué ruta pertenece un comercio.
     */
    public function merchants(): HasManyThrough
    {
        return $this->hasManyThrough(
            Merchant::class,
            PickupRoute::class,
            'id',                // pickup_routes.id
            'pickup_route_id',   // merchants.pickup_route_id
            'pickup_route_id',   // couriers.pickup_route_id
            'id',                // pickup_routes.id
        );
    }

    /**
     * Reglas de validación. Viven en el modelo para que el CRUD de la fase 3
     * las reutilice en lugar de reescribirlas.
     *
     * @param  int|null  $id  Id a ignorar al comprobar unicidad (edición).
     */
    public static function rules(?int $id = null): array
    {
        return [
            // whereNull('deleted_at') para que la regla diga lo mismo que los
            // índices parciales: el nombre y la ruta de un mensajero dado de
            // baja quedan libres para su sustituto.
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('couriers', 'name')->ignore($id)->whereNull('deleted_at'),
            ],

            // Única: una ruta la lleva un solo mensajero, o el `mensajero` del
            // contrato quedaría ambiguo. Nullable: puede no tener ruta asignada.
            'pickup_route_id' => [
                'nullable',
                'integer',
                Rule::exists('pickup_routes', 'id')->whereNull('deleted_at'),
                Rule::unique('couriers', 'pickup_route_id')->ignore($id)->whereNull('deleted_at'),
            ],
        ];
    }
}
