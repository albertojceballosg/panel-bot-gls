<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\Rule;

/** El mensajero que conduce una ruta. */
class Courier extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'route_id'];

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
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
            Route::class,
            'id',        // routes.id
            'route_id',  // merchants.route_id
            'route_id',  // couriers.route_id
            'id',        // routes.id
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
            'route_id' => [
                'nullable',
                'integer',
                Rule::exists('routes', 'id')->whereNull('deleted_at'),
                Rule::unique('couriers', 'route_id')->ignore($id)->whereNull('deleted_at'),
            ],
        ];
    }
}
