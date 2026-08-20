<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Lo que un concepto de gasto le cuesta a una ruta al mes (CONTEXTO.md §7, fase 15).
 *
 * El importe vive aquí y no en `Expense` porque es de la ruta, no del concepto: cada
 * transportista cobra lo suyo y cada ruta quema su gasolina. Ver la migración.
 *
 * **Todo es mensual.** `amount` es lo que cuesta en un mes, y `starts_on`/`ends_on` dicen en
 * cuáles aplica —ambos inclusive, `ends_on` nulo es «sigue vigente»—. `recurrent` distingue
 * el sueldo, que se cobra todos los meses, del mantenimiento que pasó una vez.
 */
class RouteExpense extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'pickup_route_id', 'expense_id', 'amount', 'recurrent', 'starts_on', 'ends_on',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'recurrent' => 'boolean',
            // `date` y no `datetime`: son meses, y una hora aquí sólo daría problemas de zona.
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function pickupRoute(): BelongsTo
    {
        return $this->belongsTo(PickupRoute::class)->withTrashed();
    }

    /** El concepto: «Gasolina», «Pago al transportista»… */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class)->withTrashed();
    }

    /**
     * El primer día del mes, que es como se guardan las fechas de esta tabla.
     *
     * Acepta lo que escribe el formulario (`2026-08`, de un `<input type="month">`) y también
     * una fecha entera. La convención del día 1 la impone la aplicación y no la base, así que
     * tiene que haber un único sitio que la aplique: éste.
     */
    public static function month(Carbon|string $value): Carbon
    {
        return Carbon::parse($value)->startOfMonth();
    }

    /**
     * Las líneas vigentes en un mes: las recurrentes que lo abarcan y las puntuales de ese
     * mes, de una vez. Es la consulta que justifica el par `starts_on`/`ends_on`.
     */
    public function scopeInMonth(Builder $query, Carbon|string $month): Builder
    {
        $mes = self::month($month);

        return $query
            ->whereDate('starts_on', '<=', $mes)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $mes));
    }

    /** Si en el mes que se mira esta línea ya no estaba, o todavía no. */
    public function isActiveIn(Carbon|string $month): bool
    {
        $mes = self::month($month);

        return $this->starts_on <= $mes
            && ($this->ends_on === null || $this->ends_on >= $mes);
    }

    /**
     * Reglas de validación. Viven en el modelo, como las del resto del maestro, para que la
     * pantalla y los tests validen lo mismo.
     *
     * Las fechas van en `Y-m` porque es lo que manda un `<input type="month">`: aquí se valida
     * el mes tal cual se teclea, y la conversión al día 1 la hace `month()` al guardar.
     *
     * @param  int|null  $id  Id a ignorar (edición).
     * @param  array<string, mixed>  $form  El resto del formulario, para la regla de solape.
     */
    public static function rules(?int $id = null, array $form = []): array
    {
        return [
            // `whereNull('deleted_at')`: no se le cuelga un gasto a una ruta dada de baja.
            'pickup_route_id' => [
                'required', 'integer',
                Rule::exists('pickup_routes', 'id')->whereNull('deleted_at'),
            ],

            'expense_id' => [
                'required', 'integer',
                Rule::exists('expenses', 'id')->whereNull('deleted_at'),
            ],

            // Euros al mes. Obligatorio, nunca negativo —eso sería un ingreso— y con los
            // límites de la columna, `decimal(10,2)`. El cero se admite: es una respuesta.
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],

            'recurrent' => ['required', 'boolean'],

            'starts_on' => [
                'required', 'date_format:Y-m',

                // Dos líneas del mismo concepto en la misma ruta no pueden solaparse en el
                // tiempo: el mes sumaría el gasto dos veces y nadie lo notaría. No lo puede
                // garantizar un índice —es un solape de rangos, no una igualdad—, así que la
                // regla es esto, más el cerrojo de doble envío que impide la carrera.
                function (string $attribute, mixed $value, callable $fail) use ($id, $form) {
                    if (self::overlaps($id, $form, $value)) {
                        $fail('Ese concepto ya tiene un gasto en esta ruta en alguno de esos meses. Cierra el anterior antes de abrir otro.');
                    }
                },
            ],

            // Nulo es «sigue vigente», y sólo lo tiene un recurrente: en un puntual la
            // pantalla iguala el fin al principio. Nunca antes del mes de inicio.
            'ends_on' => ['nullable', 'date_format:Y-m', 'after_or_equal:starts_on'],
        ];
    }

    /**
     * Si el periodo que se está guardando pisa el de otra línea viva del mismo concepto en la
     * misma ruta. Dos rangos se solapan cuando cada uno empieza antes de que acabe el otro,
     * contando el nulo de `ends_on` como «no acaba».
     *
     * @param  array<string, mixed>  $form
     */
    private static function overlaps(?int $id, array $form, string $startsOn): bool
    {
        $ruta = $form['pickup_route_id'] ?? null;
        $concepto = $form['expense_id'] ?? null;

        // Sin los dos no hay nada que comparar, y de que falten ya avisan sus propias reglas.
        if (! $ruta || ! $concepto) {
            return false;
        }

        $desde = self::month($startsOn);
        $hasta = ($form['ends_on'] ?? '') !== '' ? self::month($form['ends_on']) : null;

        return self::query()
            ->where('pickup_route_id', $ruta)
            ->where('expense_id', $concepto)
            ->when($id !== null, fn ($q) => $q->whereKeyNot($id))
            // El otro empieza antes de que acabe éste…
            ->when($hasta !== null, fn ($q) => $q->whereDate('starts_on', '<=', $hasta))
            // …y acaba después de que empiece éste.
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $desde))
            ->exists();
    }
}
