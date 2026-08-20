<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Una ruta de recogida: por qué comercios pasa una furgoneta (CONTEXTO.md §0).
 *
 * `PickupRoute` y no `Route` para no colisionar con `Illuminate\Support\Facades\Route`
 * en ningún fichero que necesite las dos cosas.
 */
class PickupRoute extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = ['name'];

    /**
     * Con borrado pasivo las FK `restrictOnDelete` que apuntan aquí no llegan a dispararse
     * —no hay DELETE que restringir—, así que las reglas se sostienen aquí. Sin esto, dar de
     * baja una ruta dejaría a sus comercios y a sus gastos apuntando a una ruta invisible:
     * seguirían en la base, fuera del maestro y sin que nadie lo note.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $pickupRoute) {
            $vivos = $pickupRoute->merchants()->count();

            if ($vivos > 0) {
                throw new RuntimeException(sprintf(
                    'La ruta "%s" todavía tiene %d %s. %s a otra ruta antes de darla de baja.',
                    $pickupRoute->name,
                    $vivos,
                    $vivos === 1 ? 'comercio' : 'comercios',
                    $vivos === 1 ? 'Muévelo' : 'Muévelos',
                ));
            }

            // Y sus gastos (fase 15). Una línea huérfana seguiría sumando en los totales por
            // concepto sin que su ruta apareciese ya en ninguna pantalla.
            $gastos = $pickupRoute->routeExpenses()->count();

            if ($gastos > 0) {
                throw new RuntimeException(sprintf(
                    'La ruta "%s" todavía tiene %d %s. %s antes de darla de baja.',
                    $pickupRoute->name,
                    $gastos,
                    $gastos === 1 ? 'gasto' : 'gastos',
                    $gastos === 1 ? 'Retíralo' : 'Retíralos',
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
     * Lo que le cuesta la ruta al mes, concepto a concepto (fase 15). El importe está aquí y
     * no en el catálogo de conceptos porque es de la ruta: ver `RouteExpense`.
     */
    public function routeExpenses(): HasMany
    {
        return $this->hasMany(RouteExpense::class);
    }

    /**
     * Quien conduce la ruta hoy. hasOne y no hasMany porque
     * `couriers.pickup_route_id` es único entre los vivos: el contrato sirve un
     * solo `mensajero` por comercio (§3). Puede no haber ninguno — una ruta sin
     * mensajero asignado sigue siendo una ruta válida con sus comercios.
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
                Rule::unique('pickup_routes', 'name')->ignore($id)->whereNull('deleted_at'),
            ],
        ];
    }
}
