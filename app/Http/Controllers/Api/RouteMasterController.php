<?php

namespace App\Http\Controllers\Api;

use App\Models\Merchant;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * El maestro completo que consume el bot: CONTEXTO.md §3.
 *
 * **La forma de esta respuesta es un contrato entre dos repos.** Cambiarla
 * obliga a tocar `bot-gls`, así que no se toca sin coordinar. Por eso las
 * claves del JSON van en castellano aunque el código esté en inglés: son el
 * contrato, no nombres nuestros.
 *
 * Siempre la lista completa, nunca altas y bajas incrementales (§3, regla 1):
 * es idempotente y no se puede desincronizar.
 */
class RouteMasterController
{
    public function __invoke(): JsonResponse
    {
        // has('pickupRoute') deja fuera a un comercio cuya ruta esté dada de
        // baja. No debería poder pasar —PickupRoute se niega a darse de baja
        // con comercios vivos— pero servir un comercio con `ruta: null` haría
        // que el bot rechace el maestro entero, así que se filtra y se avisa.
        $merchants = Merchant::query()
            ->with('pickupRoute.courier')
            ->has('pickupRoute')
            ->orderBy('name')
            ->get();

        if (($orphans = Merchant::query()->doesntHave('pickupRoute')->count()) > 0) {
            Log::warning("GET /api/rutas: {$orphans} comercios vivos apuntan a una ruta dada de baja y quedan fuera del maestro.");
        }

        $ajustes = Setting::forModules(['bot', 'profitability']);

        return response()->json([
            // Informativo, con zona (§3). APP_TIMEZONE es Europe/Madrid.
            'generado' => now()->toIso8601String(),

            // Los ajustes del análisis que el cliente controla desde
            // /settings/bot. Viajan aquí y no por un endpoint propio porque el
            // bot **guarda esta respuesta en disco** y la reutiliza cuando el
            // panel no contesta: así el parámetro hereda gratis esa tolerancia a
            // caídas, en vez de necesitar su propia caché.
            //
            // `null` cuando nadie lo ha configurado — el panel no inventa
            // defectos (ver la migración de `settings`) — y entonces el bot se
            // queda con el suyo. Un hueco aquí no puede cambiar un informe.
            //
            // **El `fn ($v) => $v !== null` no es decorativo y no se puede quitar.**
            // `array_filter` sin callback descarta todo lo falsy, y eso incluye el `0`
            // de `dias_atras_ganancia`, donde cero significa «busca sólo el día que
            // analizas» — una respuesta que el cliente eligió, no un hueco. Sin el
            // callback, ese ajuste no llegaría nunca al bot y correría con su 3 sin que
            // nadie viera por qué (§3.2, fase 14).
            // Los dos módulos en una consulta y no una por cada uno: este endpoint tiene
            // un test que le cuenta las consultas, y con `for()` por módulo cada parámetro
            // nuevo del catálogo le añadiría una.
            'parametros' => array_filter([
                'semiancho_min' => self::entero($ajustes['bot']['window_half_minutes'] ?? ''),
                'dias_atras_ganancia' => self::entero($ajustes['profitability']['lookback_days'] ?? ''),
            ], fn ($v) => $v !== null),

            'comercios' => $merchants->map(fn (Merchant $merchant) => [
                // Identidad estable, y por eso va primero. El bot la guarda con
                // el maestro y la devuelve al subir las incidencias (§3.1), de
                // modo que cada incidencia enlaza con la entidad real en vez de
                // casar cadenas de texto. Sin esto, renombrar una ruta —que el
                // panel permite— descoloca todas las incidencias ya guardadas.
                'id' => $merchant->id,

                // Sin normalizar: lo normaliza el bot.
                'nombre' => $merchant->name,

                'ruta_id' => $merchant->pickupRoute->id,

                // OJO: texto, no número. Las rutas son entidades con nombre
                // libre desde el rediseño del 12/08/2026 (§4). Sigue siendo lo
                // que el bot usa para agrupar y para los informes; el `ruta_id`
                // es para el camino de vuelta.
                'ruta' => $merchant->pickupRoute->name,

                // Null si la ruta no tiene mensajero asignado ahora mismo.
                'mensajero' => $merchant->pickupRoute->courier?->name,

                // Null si el comercio no tiene código: el cruce del bot cae en
                // fuzzy para ese. 11 de los 93 están así (§3).
                'codigo' => $merchant->code,
            ])->all(),
        ]);
    }

    /**
     * El valor guardado como entero, o `null` si no hay ninguno.
     *
     * `Setting::for()` devuelve `''` para lo que nadie ha configurado y texto
     * para lo demás, porque los ajustes se guardan como cadenas (ver la
     * migración). Mandar `""` al bot le daría un valor que no es un número; el
     * hueco tiene que llegar como hueco.
     */
    private static function entero(string $valor): ?int
    {
        return $valor === '' ? null : (int) $valor;
    }
}
