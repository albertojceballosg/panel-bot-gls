<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\SettingsCatalog;
use Illuminate\Database\Eloquent\Model;

/**
 * Un parámetro de configuración de un módulo (CONTEXTO.md §7, fase 11).
 *
 * `Auditable` como el maestro: un umbral que cambia altera cómo se lee una
 * pantalla entera, y saber quién lo movió y cuándo es exactamente la clase de
 * pregunta para la que existe el historial (§4).
 *
 * **Sin `SoftDeletes`**: aquí no se da de baja nada. Un parámetro se cambia, y
 * si se borra su fila el módulo vuelve a su valor por defecto — que es lo que
 * rige mientras nadie ha guardado nada.
 */
class Setting extends Model
{
    use Auditable;

    protected $fillable = ['module', 'key', 'value'];

    /**
     * Cómo se llama esta fila en el historial.
     *
     * `AuditPresenter::record()` busca un `name`; sin esto, la pantalla de
     * auditoría diría «#7», que no le dice nada a nadie. Con esto dice
     * «Calendario de capacidades · Porcentaje mínimo».
     */
    public function getNameAttribute(): string
    {
        $definicion = SettingsCatalog::module($this->module);

        return trim(sprintf(
            '%s · %s',
            $definicion['label'] ?? $this->module,
            $definicion['fields'][$this->key]['label'] ?? $this->key,
        ), ' ·');
    }

    /**
     * Los valores de un módulo, con `''` en lo que nadie ha configurado.
     *
     * **No hay valores por defecto** (decisión del 14/08/2026): un parámetro sin
     * configurar es un hueco y así se devuelve. Inventarle un número al cliente
     * sería peor que el hueco, porque cambiaría cómo se lee una pantalla sin que
     * nadie lo haya elegido y sin que nada lo avise.
     *
     * @return array<string, string>
     */
    public static function for(string $module): array
    {
        $guardados = static::where('module', $module)->pluck('value', 'key')->all();

        return collect(SettingsCatalog::keys($module))
            ->mapWithKeys(fn (string $key) => [$key => (string) ($guardados[$key] ?? '')])
            ->all();
    }

    /**
     * Lo mismo para varios módulos a la vez, en **una** consulta.
     *
     * Existe por `GET /api/rutas`: desde la fase 14 sirve parámetros de dos módulos y
     * llamar a `for()` una vez por módulo le añadía una consulta a cada parámetro nuevo.
     * Ese endpoint es el producto (regla 3 de `CLAUDE.md`) y tiene un test que le cuenta
     * las consultas, así que crecer con el catálogo no era aceptable.
     *
     * @param  list<string>  $modules
     * @return array<string, array<string, string>>
     */
    public static function forModules(array $modules): array
    {
        $guardados = static::whereIn('module', $modules)
            ->get(['module', 'key', 'value'])
            ->groupBy('module')
            ->map(fn ($filas) => $filas->pluck('value', 'key'));

        return collect($modules)
            ->mapWithKeys(fn (string $module) => [
                $module => collect(SettingsCatalog::keys($module))
                    ->mapWithKeys(fn (string $key) => [$key => (string) ($guardados[$module][$key] ?? '')])
                    ->all(),
            ])
            ->all();
    }

    /**
     * Los parámetros que le faltan a un módulo, por su nombre de cara al
     * usuario. Vacío es «configurado del todo».
     *
     * Lo usa la pantalla del módulo para avisar, así que devuelve etiquetas y no
     * claves: quien lee el aviso no sabe qué es `optimal_percent`.
     *
     * @return list<string>
     */
    public static function missing(string $module): array
    {
        return static::missingIn($module, static::for($module));
    }

    /**
     * Lo mismo, pero sobre unos valores ya leídos.
     *
     * Existe para quien necesita las dos cosas —los valores para trabajar y lo
     * que falta para avisar—: con `missing()` a secas, la pantalla que ya llamó
     * a `for()` pagaría una segunda consulta por la misma fila.
     *
     * @param  array<string, string>  $values
     * @return list<string>
     */
    public static function missingIn(string $module, array $values): array
    {
        $campos = SettingsCatalog::module($module)['fields'] ?? [];

        return collect($values)
            ->filter(fn (string $valor) => $valor === '')
            ->keys()
            ->map(fn (string $key) => $campos[$key]['label'] ?? $key)
            ->values()
            ->all();
    }

    /** Si el módulo tiene todos sus parámetros puestos. */
    public static function configured(string $module): bool
    {
        return static::missing($module) === [];
    }

    /**
     * Guarda los valores de un módulo. Sólo escribe los que el catálogo declara:
     * lo que llegue de más se descarta en vez de acabar en la base.
     *
     * @param  array<string, mixed>  $values
     */
    public static function store(string $module, array $values): void
    {
        foreach (array_keys(SettingsCatalog::module($module)['fields'] ?? []) as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            static::updateOrCreate(
                ['module' => $module, 'key' => $key],
                ['value' => (string) $values[$key]],
            );
        }
    }
}
