<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\Rule;

/** Un comercio del maestro: el remitente cuyos paquetes recoge una ruta. */
class Merchant extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = ['name', 'code', 'pickup_route_id'];

    /**
     * `normalized_name` es columna generada: se deriva de `name`, así que en el
     * historial sería ruido duplicado.
     */
    protected function auditExclude(): array
    {
        return ['normalized_name'];
    }

    protected function casts(): array
    {
        return ['code' => 'integer'];
    }

    public function pickupRoute(): BelongsTo
    {
        return $this->belongsTo(PickupRoute::class);
    }

    /**
     * El mensajero que lo recoge, a través de su ruta. Derivado y no una FK
     * propia: si el comercio apuntase al mensajero, dar de baja a esa persona
     * dejaría al comercio huérfano de ruta (§4).
     */
    public function courier(): HasOneThrough
    {
        return $this->hasOneThrough(
            Courier::class,
            PickupRoute::class,
            'id',                // pickup_routes.id
            'pickup_route_id',   // couriers.pickup_route_id
            'pickup_route_id',   // merchants.pickup_route_id
            'id',                // pickup_routes.id
        );
    }

    /**
     * Réplica en PHP de la expresión de la columna generada `normalized_name`
     * (ver la migración). Sirve para avisar del duplicado con un mensaje legible
     * antes de que Postgres devuelva un 23505 en crudo.
     *
     * Si se toca la expresión de la migración, hay que tocar esto también.
     */
    public static function normalize(string $name): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($name)));
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
                // No vale un Rule::unique sobre `name`: el índice único está
                // sobre la columna normalizada, así que la comprobación tiene
                // que hacerse contra ella o se cuelan duplicados por mayúsculas.
                function (string $atributo, mixed $valor, \Closure $fallar) use ($id) {
                    // La query lleva el scope de SoftDeletes, así que sólo mira
                    // a los vivos — igual que el índice parcial de la migración.
                    $duplicado = static::query()
                        ->where('normalized_name', static::normalize((string) $valor))
                        ->when($id, fn ($q) => $q->whereKeyNot($id))
                        ->exists();

                    if ($duplicado) {
                        $fallar('Ya existe un comercio con ese nombre.');
                    }
                },
            ],

            // Único cuando no es nulo: en Postgres el índice único deja pasar
            // varios NULL, así que la regla y el esquema dicen lo mismo.
            'code' => [
                'nullable',
                'integer',
                'min:1',
                Rule::unique('merchants', 'code')->ignore($id)->whereNull('deleted_at'),
            ],

            // Obligatoria: un comercio sin ruta no se puede agrupar (§3). Y la
            // ruta tiene que estar viva, no dada de baja.
            'pickup_route_id' => [
                'required',
                'integer',
                Rule::exists('pickup_routes', 'id')->whereNull('deleted_at'),
            ],
        ];
    }
}
