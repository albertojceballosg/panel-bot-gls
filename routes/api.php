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
Route::middleware(VerifyBotToken::class)->group(function () {
    Route::get('/rutas', RouteMasterController::class)->name('api.route-master');

    // Valida y guarda la jornada entera: `incident_runs` + `run_packages`, con
    // upsert por (jornada, expedicion) y marcado de retiradas. Ver §3.1.
    //
    // Nació como sonda que sólo validaba y registraba en el log, mientras el
    // modelo de datos estaba sin decidir; guarda desde el 13/08/2026.
    Route::post('/incidencias', IncidentIntakeController::class)->name('api.incident-intake');
});
