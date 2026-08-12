<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\Rule;

class Mensajero extends Model
{
    use SoftDeletes;

    protected $table = 'mensajeros';

    protected $fillable = ['nombre', 'ruta_id'];

    public function ruta(): BelongsTo
    {
        return $this->belongsTo(Ruta::class);
    }

    /**
     * Los comercios por los que pasa, a través de su ruta. Derivado a propósito:
     * el mensajero no "tiene" comercios, conduce una ruta que los tiene. Así no
     * hay dos sitios donde pueda decir a qué ruta pertenece un comercio.
     */
    public function comercios(): HasManyThrough
    {
        return $this->hasManyThrough(
            Comercio::class,
            Ruta::class,
            'id',        // rutas.id
            'ruta_id',   // comercios.ruta_id
            'ruta_id',   // mensajeros.ruta_id
            'id',        // rutas.id
        );
    }

    /**
     * Reglas de validación. Viven en el modelo para que el CRUD de la fase 3
     * las reutilice en lugar de reescribirlas.
     *
     * @param  int|null  $id  Id a ignorar al comprobar unicidad (edición).
     */
    public static function reglas(?int $id = null): array
    {
        return [
            // whereNull('deleted_at') para que la regla diga lo mismo que los
            // índices parciales: el nombre y la ruta de un mensajero dado de
            // baja quedan libres para su sustituto.
            'nombre' => [
                'required',
                'string',
                'max:255',
                Rule::unique('mensajeros', 'nombre')->ignore($id)->whereNull('deleted_at'),
            ],

            // Única: una ruta la lleva un solo mensajero, o el `mensajero` del
            // contrato quedaría ambiguo. Nullable: puede no tener ruta asignada.
            'ruta_id' => [
                'nullable',
                'integer',
                Rule::exists('rutas', 'id')->whereNull('deleted_at'),
                Rule::unique('mensajeros', 'ruta_id')->ignore($id)->whereNull('deleted_at'),
            ],
        ];
    }
}
