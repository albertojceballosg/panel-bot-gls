<?php

use App\Http\Controllers\Api\IncidentIntakeController;
use App\Http\Controllers\Api\RouteMasterController;
use App\Http\Middleware\VerifyBotToken;
use Illuminate\Support\Facades\Route;

/*
| Las dos mitades del trato con el bot (CONTEXTO.md §2): baja el maestro al
| arrancar la corrida, sube las incidencias al terminarla.
|
| Van aquí y no en web.php a propósito: el grupo `api` no arrastra sesión ni
| CSRF, y estos endpoints no deben depender de nada de la interfaz. Son el
| producto; el panel es la forma de alimentarlo.
|
| Las URL se quedan en castellano: son parte del contrato, y cambiarlas
| obligaría a tocar el .env del bot.
*/
/*
| El límite de peticiones va **antes** del token a propósito: si fuera después, probar tokens
| a ritmo de máquina contra el maestro completo del cliente saldría gratis (§10).
|
| 30 por minuto es holgado a sabiendas. El bot hace dos peticiones al día —el maestro al
| arrancar la corrida y las incidencias al terminarla—, pero su reenviador puede soltar
| seguidas todas las jornadas que se quedaran pendientes mientras el panel estuvo caído
| (`--reenviar`, CONTEXT.md del bot §11.5), y **un 429 es un 4xx, así que el bot no lo
| reintenta**: pasarse de estrictos aquí no protege de nada y sí puede costar una jornada.
*/
Route::middleware(['throttle:30,1', VerifyBotToken::class])->group(function () {
    Route::get('/rutas', RouteMasterController::class)->name('api.route-master');

    // Valida y guarda la jornada entera: `incident_runs` + `run_packages`, con
    // upsert por (jornada, expedicion) y marcado de retiradas. Ver §3.1.
    //
    // Nació como sonda que sólo validaba y registraba en el log, mientras el
    // modelo de datos estaba sin decidir; guarda desde el 13/08/2026.
    Route::post('/incidencias', IncidentIntakeController::class)->name('api.incident-intake');
});
