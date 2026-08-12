<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

class Mensajero extends Model
{
    // Explícita porque el pluralizador de Laravel es inglés y el esquema está
    // en castellano: no conviene depender de que acierte.
    protected $table = 'mensajeros';

    protected $fillable = ['nombre', 'ruta'];

    protected function casts(): array
    {
        return ['ruta' => 'integer'];
    }

    public function comercios(): HasMany
    {
        return $this->hasMany(Comercio::class);
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
            'nombre' => ['required', 'string', 'max:255', Rule::unique('mensajeros', 'nombre')->ignore($id)],
            'ruta' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
