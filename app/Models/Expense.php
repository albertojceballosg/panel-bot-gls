<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Un concepto de gasto: «Gasolina», «Pago al transportista», «Mantenimiento»
 * (CONTEXTO.md §7, fase 15).
 *
 * **Es el catálogo, no el dinero.** El importe vive en `RouteExpense`, porque es de la ruta y
 * no del concepto: ni todos los transportistas cobran igual ni todas las rutas gastan la misma
 * gasolina. Aquí sólo está el vocabulario común, que es lo que permite preguntar cuánto se va
 * en gasolina **entre todas las rutas** — con el nombre escrito a mano en cada línea,
 * «Gasolina» y «gasolina» serían dos cosas distintas y esa pregunta no tendría respuesta.
 *
 * Son cuatro o cinco filas que casi nunca se tocan. `Auditable` de todos modos: quién añadió
 * o retiró un concepto explica por qué un mes dejó de tener una partida.
 */
class Expense extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = ['name', 'description'];

    /**
     * Un concepto en uso no se da de baja. Mismo criterio que `PickupRoute` con sus comercios
     * y por lo mismo: con baja pasiva la FK no llega a dispararse, así que sin esto el
     * concepto desaparecería del catálogo dejando líneas de gasto apuntando a un nombre
     * invisible, que seguirían sumando en los totales sin que nadie pudiera verlas.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $expense) {
            $vivas = $expense->routeExpenses()->count();

            if ($vivas > 0) {
                throw new RuntimeException(sprintf(
                    'El concepto "%s" todavía se usa en %d %s. %s antes de darlo de baja.',
                    $expense->name,
                    $vivas,
                    $vivas === 1 ? 'línea de gasto' : 'líneas de gasto',
                    $vivas === 1 ? 'Retírala' : 'Retíralas',
                ));
            }
        });
    }

    /** Las líneas que lo usan: una por ruta y periodo. */
    public function routeExpenses(): HasMany
    {
        return $this->hasMany(RouteExpense::class);
    }

    /**
     * Reglas de validación. Viven en el modelo para que la pantalla, la validación en caliente
     * y los tests validen lo mismo (§7, fase 1).
     *
     * @param  int|null  $id  Id a ignorar al comprobar unicidad (edición).
     */
    public static function rules(?int $id = null): array
    {
        return [
            // `whereNull('deleted_at')` para decir lo mismo que el índice parcial de la
            // migración: el nombre de un concepto dado de baja queda libre.
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('expenses', 'name')->ignore($id)->whereNull('deleted_at'),
            ],

            // Opcional: hay conceptos cuyo nombre ya lo dice todo. El tope es para que quepa
            // en la pantalla y en el historial, no porque la columna lo necesite.
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
