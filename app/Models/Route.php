<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Una ruta de recogida.
 *
 * OJO al importarlo: el nombre choca con `Illuminate\Support\Facades\Route`.
 * En un fichero que necesite las dos cosas, hay que aliasar una.
 */
class Route extends Model
{
    use SoftDeletes;

    protected $fillable = ['name'];

    /**
     * Con borrado pasivo la FK `restrictOnDelete` de `merchants` no llega a
     * dispararse —no hay DELETE que restringir—, así que la regla se sostiene
     * aquí. Sin esto, dar de baja una ruta dejaría a sus comercios apuntando a
     * una ruta invisible: seguirían en la base, fuera del maestro y sin que
     * nadie lo note.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $route) {
            if ($route->merchants()->exists()) {
                throw new RuntimeException(sprintf(
                    'La ruta "%s" todavía tiene %d comercios. Muévelos a otra ruta antes '.
                    'de darla de baja.',
                    $route->name,
                    $route->merchants()->count(),
                ));
            }
        });
    }

    /** Los comercios que componen la ruta. Es el maestro que consume el bot. */
    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class);
    }

    /**
     * Quien conduce la ruta hoy. hasOne y no hasMany porque `couriers.route_id`
     * es único entre los vivos: el contrato sirve un solo `mensajero` por
     * comercio (§3). Puede no haber ninguno — una ruta sin mensajero asignado
     * sigue siendo una ruta válida con sus comercios.
     */
    public function courier(): HasOne
    {
        return $this->hasOne(Courier::class);
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
            'name' => [
                'required',
                'string',
                'max:255',
                // whereNull('deleted_at') para que la regla diga lo mismo que el
                // índice parcial: si no, avisaría de un choque con una ruta
                // dada de baja que la base sí deja crear.
                Rule::unique('routes', 'name')->ignore($id)->whereNull('deleted_at'),
            ],
        ];
    }
}
