<?php

namespace App\Http\Controllers\Api;

use App\Models\Merchant;
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

        return response()->json([
            // Informativo, con zona (§3). APP_TIMEZONE es Europe/Madrid.
            'generado' => now()->toIso8601String(),

            'comercios' => $merchants->map(fn (Merchant $merchant) => [
                // Sin normalizar: lo normaliza el bot.
                'nombre' => $merchant->name,

                // OJO: texto, no número. Las rutas son entidades con nombre
                // libre desde el rediseño del 12/08/2026 (§4). Es un cambio de
                // contrato todavía sin cerrar con bot-gls: ver el aviso de §3.
                'ruta' => $merchant->pickupRoute->name,

                // Null si la ruta no tiene mensajero asignado ahora mismo.
                'mensajero' => $merchant->pickupRoute->courier?->name,

                // Null si el comercio no tiene código: el cruce del bot cae en
                // fuzzy para ese. 11 de los 93 están así (§3).
                'codigo' => $merchant->code,
            ])->all(),
        ]);
    }
}
