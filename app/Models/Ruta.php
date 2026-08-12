<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\Rule;
use RuntimeException;

class Ruta extends Model
{
    use SoftDeletes;

    // Explícita porque el pluralizador de Laravel es inglés y el esquema está
    // en castellano: no conviene depender de que acierte.
    protected $table = 'rutas';

    protected $fillable = ['nombre'];

    /**
     * Con borrado pasivo la FK `restrictOnDelete` de `comercios` no llega a
     * dispararse —no hay DELETE que restringir—, así que la regla se sostiene
     * aquí. Sin esto, dar de baja una ruta dejaría a sus comercios apuntando a
     * una ruta invisible: seguirían en la base, fuera del maestro y sin que
     * nadie lo note.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $ruta) {
            if ($ruta->comercios()->exists()) {
                throw new RuntimeException(sprintf(
                    'La ruta "%s" todavía tiene %d comercios. Muévelos a otra ruta antes '.
                    'de darla de baja.',
                    $ruta->nombre,
                    $ruta->comercios()->count(),
                ));
            }
        });
    }

    /** Los comercios que componen la ruta. Es el maestro que consume el bot. */
    public function comercios(): HasMany
    {
        return $this->hasMany(Comercio::class);
    }

    /**
     * Quien conduce la ruta hoy. hasOne y no hasMany porque `mensajeros.ruta_id`
     * es único entre los vivos: el contrato sirve un solo `mensajero` por
     * comercio (§3). Puede no haber ninguno — una ruta sin mensajero asignado
     * sigue siendo una ruta válida con sus comercios.
     */
    public function mensajero(): HasOne
    {
        return $this->hasOne(Mensajero::class);
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
                // whereNull('deleted_at') para que la regla diga lo mismo que el
                // índice parcial: si no, avisaría de un choque con una ruta
                // dada de baja que la base sí deja crear.
                Rule::unique('rutas', 'nombre')->ignore($id)->whereNull('deleted_at'),
            ],
        ];
    }
}
