<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\Rule;

class Comercio extends Model
{
    use SoftDeletes;

    protected $table = 'comercios';

    protected $fillable = ['nombre', 'codigo', 'ruta_id'];

    protected function casts(): array
    {
        return ['codigo' => 'integer'];
    }

    public function ruta(): BelongsTo
    {
        return $this->belongsTo(Ruta::class);
    }

    /**
     * El mensajero que lo recoge, a través de su ruta. Derivado y no una FK
     * propia: si el comercio apuntase al mensajero, dar de baja a esa persona
     * dejaría al comercio huérfano de ruta (§4).
     */
    public function mensajero(): HasOneThrough
    {
        return $this->hasOneThrough(
            Mensajero::class,
            Ruta::class,
            'id',       // rutas.id
            'ruta_id',  // mensajeros.ruta_id
            'ruta_id',  // comercios.ruta_id
            'id',       // rutas.id
        );
    }

    /**
     * Réplica en PHP de la expresión de la columna generada `nombre_normalizado`
     * (ver la migración). Sirve para avisar del duplicado con un mensaje legible
     * antes de que Postgres devuelva un 23505 en crudo.
     *
     * Si se toca la expresión de la migración, hay que tocar esto también.
     */
    public static function normalizar(string $nombre): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($nombre)));
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
            'nombre' => [
                'required',
                'string',
                'max:255',
                // No vale un Rule::unique sobre `nombre`: el índice único está
                // sobre la columna normalizada, así que la comprobación tiene
                // que hacerse contra ella o se cuelan duplicados por mayúsculas.
                function (string $atributo, mixed $valor, \Closure $fallar) use ($id) {
                    // La query lleva el scope de SoftDeletes, así que sólo mira
                    // a los vivos — igual que el índice parcial de la migración.
                    $duplicado = static::query()
                        ->where('nombre_normalizado', static::normalizar((string) $valor))
                        ->when($id, fn ($q) => $q->whereKeyNot($id))
                        ->exists();

                    if ($duplicado) {
                        $fallar('Ya existe un comercio con ese nombre.');
                    }
                },
            ],

            // Único cuando no es nulo: en Postgres el índice único deja pasar
            // varios NULL, así que la regla y el esquema dicen lo mismo.
            'codigo' => [
                'nullable',
                'integer',
                'min:1',
                Rule::unique('comercios', 'codigo')->ignore($id)->whereNull('deleted_at'),
            ],

            // Obligatoria: un comercio sin ruta no se puede agrupar (§3). Y la
            // ruta tiene que estar viva, no dada de baja.
            'ruta_id' => ['required', 'integer', Rule::exists('rutas', 'id')->whereNull('deleted_at')],
        ];
    }
}
